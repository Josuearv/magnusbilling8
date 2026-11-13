<?php
/**
 * CDR & ASR Number Quality Analytics Engine.
 *
 * Computes per-destination behavioral signals from pkg_cdr and pkg_cdr_failed:
 * - ASR (Answer Seizure Ratio)
 * - Short Duration <= 5s (SDR_5s)
 * - Sub-3-minute calls (< 180s) & zero >=3m ceiling detection
 * - Duration variability (ACD, StdDev, Coefficient of Variation) for rigid hangup loops
 * - Dominant failure cause & cross-trunk anomaly separation (TRUNK_SUSPECT)
 */
class NumberQualityService
{
    const SUMMARY_NAME        = 'pkg_cdr_number_quality';
    const DEFAULT_WINDOW_DAYS = 30;
    const DEFAULT_MIN_ATTEMPTS = 3;
    const PRIOR_WEIGHT        = 5.0;
    const SHORT_5S_MAX        = 5;
    const MID_SHORT_MAX       = 179;
    const LONG_MIN            = 180;
    const BATCH_SIZE          = 5000;

    /**
     * Evaluates a single destination aggregate bucket and assigns quality score + label.
     *
     * Pure helper so unit tests can exercise all heuristics without a database.
     *
     * @param array $metrics Aggregated counters for (number, campaign, trunk, user)
     * @param array $baseline Campaign/global baseline ratios ('asr', 'sdr_5s', 'mid_short')
     * @param array $options Optional overrides ('min_attempts')
     * @return array
     */
    public static function evaluateMetrics(array $metrics, array $baseline = array(), array $options = array())
    {
        $minAttempts = max(1, (int) ($options['min_attempts'] ?? self::DEFAULT_MIN_ATTEMPTS));

        $attempts    = max(0, (int) ($metrics['attempts'] ?? 0));
        $answered    = max(0, (int) ($metrics['answered'] ?? 0));
        $failed      = max(0, (int) ($metrics['failed'] ?? 0));
        $cntShort5s  = max(0, (int) ($metrics['cnt_short_5s'] ?? 0));
        $cnt6to30s   = max(0, (int) ($metrics['cnt_6_30s'] ?? 0));
        $cnt31to179s = max(0, (int) ($metrics['cnt_31_179s'] ?? 0));
        $cntMidShort = max(0, (int) ($metrics['cnt_mid_short'] ?? ($cnt6to30s + $cnt31to179s)));
        $cntLong180s = max(0, (int) ($metrics['cnt_long_180s'] ?? 0));
        $sumDur      = max(0.0, (float) ($metrics['sum_duration'] ?? 0));
        $sumDurSq    = max(0.0, (float) ($metrics['sum_duration_sq'] ?? 0));
        $buycost     = round((float) ($metrics['buycost_total'] ?? 0), 6);

        $asrPct      = $attempts > 0 ? round(($answered / $attempts) * 100.0, 2) : 0.0;
        $sdr5sPct    = $answered > 0 ? round(($cntShort5s / $answered) * 100.0, 2) : 0.0;
        $midShortPct = $answered > 0 ? round(($cntMidShort / $answered) * 100.0, 2) : 0.0;
        $acdSec      = $answered > 0 ? round($sumDur / $answered, 2) : 0.0;

        $variance = 0.0;
        if ($answered > 0) {
            $variance = max(0.0, ($sumDurSq / $answered) - ($acdSec * $acdSec));
        }
        $stddev = round(sqrt($variance), 2);
        $cv     = ($answered >= 3 && $acdSec > 0.0) ? round($stddev / $acdSec, 4) : 0.0;

        $domFail = self::resolveDominantFailure($metrics, $attempts, $failed);

        // Empirical Bayes smoothing against campaign/global priors (weight alpha = 5)
        $bAsr      = self::clamp((float) ($baseline['asr'] ?? 0.35), 0.05, 0.90);
        $bSdr5     = self::clamp((float) ($baseline['sdr_5s'] ?? 0.25), 0.05, 0.80);
        $bMidShort = self::clamp((float) ($baseline['mid_short'] ?? 0.40), 0.05, 0.85);
        $bLong     = self::clamp(1.0 - $bSdr5 - $bMidShort, 0.05, 0.80);

        $alpha       = self::PRIOR_WEIGHT;
        $effAnswered = max(0, $answered - $cntShort5s);
        $bEffAsr     = max(0.05, $bAsr * (1.0 - $bSdr5));
        $pEffAsr     = ($effAnswered + $alpha * $bEffAsr) / ($attempts + $alpha);
        $pShort5     = ($cntShort5s + $alpha * $bSdr5) / ($answered + $alpha);
        $pLong       = ($cntLong180s + $alpha * $bLong) / ($answered + $alpha);
        $confidence  = round($attempts / ($attempts + $alpha), 4);

        // Weighted 0..100 score (higher = healthier destination; <=5s calls do not inflate ASR)
        $asrComp    = self::clamp($pEffAsr / max(0.35, $bEffAsr), 0.0, 1.0) * 35.0;
        $short5Comp = (1.0 - self::clamp($pShort5 / 0.50, 0.0, 1.0)) * 25.0;
        $longComp   = self::clamp($pLong / 0.40, 0.0, 1.0) * 25.0;

        $failRatio   = $attempts > 0 ? ($failed / $attempts) : 0.0;
        $failWeight  = in_array($domFail['cause'], array('CHANUNAVAIL', 'CONGESTION', 'BUSY'), true) ? 10.0 : 7.0;
        $nonFailComp = max(0.0, 10.0 - (self::clamp($failRatio, 0.0, 1.0) * $failWeight));

        $patternComp = 5.0;
        $patternPenalty = 0.0;
        if ($answered >= 5 && $cv > 0.0 && $cv < 0.25 && $acdSec < self::LONG_MIN) {
            $patternComp    = ($cv / 0.25) * 5.0;
            $patternPenalty = (1.0 - ($cv / 0.25)) * 12.0;
        }

        $under3mPenalty = 0.0;
        if ($answered >= 4 && $cntLong180s === 0) {
            $under3mPenalty = min(22.0, $answered * 3.0);
        }

        $score = round(
            self::clamp(
                $asrComp + $short5Comp + $longComp + $nonFailComp + $patternComp - $patternPenalty - $under3mPenalty,
                0.0,
                100.0
            ),
            2
        );

        list($label, $reason) = self::classifyNumber(array(
            'attempts'         => $attempts,
            'answered'         => $answered,
            'min_attempts'     => $minAttempts,
            'asr_pct'          => $asrPct,
            'sdr_5s_pct'       => $sdr5sPct,
            'mid_short_pct'    => $midShortPct,
            'acd_sec'          => $acdSec,
            'duration_cv'      => $cv,
            'cnt_short_5s'     => $cntShort5s,
            'cnt_mid_short'    => $cntMidShort,
            'cnt_long_180s'    => $cntLong180s,
            'dom_fail_cause'   => $domFail['cause'],
            'dom_fail_pct'     => $domFail['pct'],
            'quality_score'    => $score,
        ));

        return array(
            'attempts'            => $attempts,
            'answered'            => $answered,
            'failed'              => $failed,
            'cnt_short_5s'        => $cntShort5s,
            'cnt_6_30s'           => $cnt6to30s,
            'cnt_31_179s'         => $cnt31to179s,
            'cnt_mid_short'       => $cntMidShort,
            'cnt_long_180s'       => $cntLong180s,
            'asr_pct'             => $asrPct,
            'sdr_5s_pct'          => $sdr5sPct,
            'mid_short_pct'       => $midShortPct,
            'acd_sec'             => $acdSec,
            'duration_stddev'     => $stddev,
            'duration_cv'         => $cv,
            'dominant_fail_cause' => $domFail['cause'],
            'dominant_fail_pct'   => $domFail['pct'],
            'last_hangupcause'    => (int) ($metrics['last_hangupcause'] ?? 0),
            'buycost_total'       => $buycost,
            'quality_score'       => $score,
            'confidence'          => $confidence,
            'quality_label'       => $label,
            'reason_summary'      => self::truncateReason($reason),
            'is_trunk_suspect'    => 0,
        );
    }

    /**
     * Separates carrier/trunk issues from bad destinations by comparing trunks for the same number.
     *
     * If a number achieves healthy performance (score >= 60, ASR >= 25%) on at least one trunk,
     * unhealthy rows on other trunks are flagged as TRUNK_SUSPECT instead of blaming the number.
     *
     * @param array $rows Evaluated score rows
     * @return array Updated rows
     */
    public static function applyCrossTrunkSeparation(array $rows)
    {
        $healthyBestByNumber = array();

        foreach ($rows as $row) {
            $num     = (string) ($row['canonical_number'] ?? '');
            $idTrunk = (int) ($row['id_trunk'] ?? 0);
            if ($num === '' || $idTrunk <= 0) {
                continue;
            }
            if ((int) ($row['attempts'] ?? 0) < self::DEFAULT_MIN_ATTEMPTS) {
                continue;
            }
            $score = (float) ($row['quality_score'] ?? 0);
            $asr   = (float) ($row['asr_pct'] ?? 0);
            if ($score >= 60.0 && $asr >= 25.0) {
                if (!isset($healthyBestByNumber[$num]) || $score > $healthyBestByNumber[$num]['quality_score']) {
                    $healthyBestByNumber[$num] = array(
                        'quality_score' => $score,
                        'asr_pct'       => $asr,
                        'id_trunk'      => $idTrunk,
                    );
                }
            }
        }

        $unhealthyLabels = array(
            'UNREACHABLE_FAIL_PATTERN',
            'BAD_SHORT_5S',
            'BAD_UNDER_3MIN',
            'RIGID_CUT_PATTERN',
            'WATCHLIST',
        );

        foreach ($rows as $idx => $row) {
            $num     = (string) ($row['canonical_number'] ?? '');
            $idTrunk = (int) ($row['id_trunk'] ?? 0);
            $label   = (string) ($row['quality_label'] ?? '');

            if ($idTrunk > 0 && isset($healthyBestByNumber[$num]) && in_array($label, $unhealthyLabels, true)) {
                $best = $healthyBestByNumber[$num];
                if ($best['id_trunk'] !== $idTrunk) {
                    $rows[$idx]['is_trunk_suspect'] = 1;
                    $rows[$idx]['quality_label']    = 'TRUNK_SUSPECT';
                    $prefix = '[Posible troncal #' . $idTrunk . ': ASR ' . $row['asr_pct']
                        . '% vs ' . $best['asr_pct'] . '% en troncal #' . $best['id_trunk'] . '] ';
                    $rows[$idx]['reason_summary'] = self::truncateReason($prefix . $row['reason_summary']);
                }
            }
        }

        return $rows;
    }

    /**
     * Full incremental sync + score recomputation entry point for cron & manual trigger.
     *
     * On first run (no watermark row in pkg_cdr_summary_ids), automatically backfills
     * the last $windowDays days once so admins get populated analytics immediately.
     *
     * @param int $windowDays
     * @param int $minAttempts
     * @param bool $forceBackfill
     * @return array Execution stats
     */
    public static function syncAndScore($windowDays = self::DEFAULT_WINDOW_DAYS, $minAttempts = self::DEFAULT_MIN_ATTEMPTS, $forceBackfill = false)
    {
        $windowDays  = max(1, min(90, (int) $windowDays));
        $minAttempts = max(1, (int) $minAttempts);
        $startedAt   = microtime(true);
        $db          = Yii::app()->db;
        $hasLock     = false;

        try {
            $lock = (int) $db->createCommand("SELECT GET_LOCK('mbilling_number_quality_sync', 0)")->queryScalar();
            if ($lock !== 1) {
                return array('success' => false, 'skipped' => true, 'reason' => 'Lock held by another process.');
            }
            $hasLock = true;

            $watermark = self::getWatermark();
            $cdrProcessed = 0;
            $failProcessed = 0;
            $mode = 'incremental';

            if ($forceBackfill || $watermark === null) {
                $mode = 'backfill';
                $res = self::performBackfill($windowDays);
                $cdrProcessed  = $res['cdr_processed'];
                $failProcessed = $res['fail_processed'];
            } else {
                $res = self::performIncrementalSync($watermark);
                $cdrProcessed  = $res['cdr_processed'];
                $failProcessed = $res['fail_processed'];
            }

            $scoredCount = self::recomputeScoresInternal($windowDays, $minAttempts);

            return array(
                'success'        => true,
                'skipped'        => false,
                'mode'           => $mode,
                'cdr_processed'  => $cdrProcessed,
                'fail_processed' => $failProcessed,
                'scores_updated' => $scoredCount,
                'duration_sec'   => round(microtime(true) - $startedAt, 3),
            );
        } finally {
            if ($hasLock) {
                try {
                    $db->createCommand("SELECT RELEASE_LOCK('mbilling_number_quality_sync')")->queryScalar();
                } catch (Exception $e) {
                    Yii::log('NumberQualityService release lock error: ' . $e->getMessage(), CLogger::LEVEL_WARNING);
                }
            }
        }
    }

    /**
     * Rebuilds the rolling N-day daily aggregates directly with the sync lock held.
     *
     * @param int $days
     * @return array
     */
    public static function backfillAndScore($days = self::DEFAULT_WINDOW_DAYS)
    {
        return self::syncAndScore($days, self::DEFAULT_MIN_ATTEMPTS, true);
    }

    /**
     * Recomputes only the rolling quality scores from existing daily buckets under lock.
     *
     * @param int $windowDays
     * @param int $minAttempts
     * @return array
     */
    public static function recomputeScoresOnly($windowDays = self::DEFAULT_WINDOW_DAYS, $minAttempts = self::DEFAULT_MIN_ATTEMPTS)
    {
        $db      = Yii::app()->db;
        $hasLock = false;
        try {
            $lock = (int) $db->createCommand("SELECT GET_LOCK('mbilling_number_quality_sync', 0)")->queryScalar();
            if ($lock !== 1) {
                return array('success' => false, 'skipped' => true, 'reason' => 'Lock held by another process.');
            }
            $hasLock = true;
            $count = self::recomputeScoresInternal(max(1, (int) $windowDays), max(1, (int) $minAttempts));
            return array('success' => true, 'scores_updated' => $count);
        } finally {
            if ($hasLock) {
                try {
                    $db->createCommand("SELECT RELEASE_LOCK('mbilling_number_quality_sync')")->queryScalar();
                } catch (Exception $e) {
                    Yii::log('NumberQualityService release lock error: ' . $e->getMessage(), CLogger::LEVEL_WARNING);
                }
            }
        }
    }

    /**
     * Returns dashboard KPIs, label distribution and trunk health strip data.
     *
     * @param int|null $idCampaign
     * @param int|null $idTrunk
     * @return array
     */
    public static function getSummary($idCampaign = null, $idTrunk = null)
    {
        $db     = Yii::app()->db;
        $where  = array('1=1');
        $params = array();

        if ($idCampaign !== null && (int) $idCampaign > 0) {
            $where[]               = 's.id_campaign = :idCampaign';
            $params[':idCampaign'] = (int) $idCampaign;
        }
        if ($idTrunk !== null && (int) $idTrunk > 0) {
            $where[]            = 's.id_trunk = :idTrunk';
            $params[':idTrunk'] = (int) $idTrunk;
        }
        $whereSql = implode(' AND ', $where);

        $aggSql = "SELECT
                       COUNT(*) AS total_numbers,
                       COALESCE(SUM(s.attempts), 0) AS total_attempts,
                       COALESCE(SUM(s.answered), 0) AS total_answered,
                       COALESCE(SUM(s.cnt_short_5s), 0) AS total_short_5s,
                       COALESCE(SUM(s.cnt_mid_short), 0) AS total_mid_short,
                       COALESCE(SUM(s.cnt_long_180s), 0) AS total_long_180s,
                       COALESCE(SUM(CASE WHEN s.quality_label IN ('BAD_SHORT_5S','BAD_UNDER_3MIN','RIGID_CUT_PATTERN','UNREACHABLE_FAIL_PATTERN') THEN 1 ELSE 0 END), 0) AS bad_numbers,
                       COALESCE(SUM(CASE WHEN s.quality_label = 'BAD_SHORT_5S' THEN 1 ELSE 0 END), 0) AS bad_short_5s,
                       COALESCE(SUM(CASE WHEN s.quality_label = 'BAD_UNDER_3MIN' THEN 1 ELSE 0 END), 0) AS bad_under_3min,
                       COALESCE(SUM(CASE WHEN s.quality_label = 'RIGID_CUT_PATTERN' THEN 1 ELSE 0 END), 0) AS rigid_cut_pattern,
                       COALESCE(SUM(CASE WHEN s.quality_label = 'UNREACHABLE_FAIL_PATTERN' THEN 1 ELSE 0 END), 0) AS unreachable_fail,
                       COALESCE(SUM(CASE WHEN s.quality_label = 'TRUNK_SUSPECT' THEN 1 ELSE 0 END), 0) AS trunk_suspect,
                       COALESCE(SUM(CASE WHEN s.quality_label = 'WATCHLIST' THEN 1 ELSE 0 END), 0) AS watchlist,
                       COALESCE(SUM(CASE WHEN s.quality_label = 'HEALTHY' THEN 1 ELSE 0 END), 0) AS healthy,
                       COALESCE(SUM(CASE WHEN s.quality_label = 'INSUFFICIENT_DATA' THEN 1 ELSE 0 END), 0) AS insufficient_data,
                       COALESCE(SUM(CASE WHEN s.quality_label IN ('BAD_SHORT_5S','BAD_UNDER_3MIN','RIGID_CUT_PATTERN','UNREACHABLE_FAIL_PATTERN') THEN s.buycost_total ELSE 0 END), 0) AS bad_buycost,
                       MAX(s.updated_at) AS last_updated_at
                   FROM pkg_cdr_number_quality_score s
                   WHERE {$whereSql}";

        $cmd = $db->createCommand($aggSql);
        self::bindParams($cmd, $params);
        $kpis = $cmd->queryRow() ?: array();

        $totAtt = (int) ($kpis['total_attempts'] ?? 0);
        $totAns = (int) ($kpis['total_answered'] ?? 0);
        $tot5s  = (int) ($kpis['total_short_5s'] ?? 0);
        $kpis['global_asr_pct']    = $totAtt > 0 ? round(($totAns / $totAtt) * 100.0, 2) : 0.0;
        $kpis['global_sdr_5s_pct'] = $totAns > 0 ? round(($tot5s / $totAns) * 100.0, 2) : 0.0;

        $trunkSql = "SELECT
                         s.id_trunk,
                         COALESCE(t.trunkcode, CONCAT('Trunk #', s.id_trunk)) AS trunkcode,
                         SUM(s.attempts) AS attempts,
                         SUM(s.answered) AS answered,
                         SUM(s.cnt_short_5s) AS short_5s,
                         ROUND(CASE WHEN SUM(s.attempts) > 0 THEN (SUM(s.answered) / SUM(s.attempts)) * 100 ELSE 0 END, 2) AS asr_pct,
                         ROUND(CASE WHEN SUM(s.answered) > 0 THEN (SUM(s.cnt_short_5s) / SUM(s.answered)) * 100 ELSE 0 END, 2) AS sdr_5s_pct,
                         SUM(CASE WHEN s.quality_label = 'TRUNK_SUSPECT' THEN 1 ELSE 0 END) AS suspect_numbers
                     FROM pkg_cdr_number_quality_score s
                     LEFT JOIN pkg_trunk t ON t.id = s.id_trunk
                     WHERE {$whereSql} AND s.id_trunk > 0
                     GROUP BY s.id_trunk, t.trunkcode
                     ORDER BY attempts DESC
                     LIMIT 8";
        $trunkCmd = $db->createCommand($trunkSql);
        self::bindParams($trunkCmd, $params);
        $trunks = $trunkCmd->queryAll() ?: array();

        return array(
            'kpis'   => $kpis,
            'trunks' => $trunks,
        );
    }

    /**
     * Fetches duration histogram & recent CDR/CDR-Failed calls for a destination number.
     *
     * @param string $canonicalNumber
     * @param int $idCampaign
     * @param int $idTrunk
     * @param int $windowDays
     * @return array
     */
    public static function getNumberDetail($canonicalNumber, $idCampaign = 0, $idTrunk = 0, $windowDays = self::DEFAULT_WINDOW_DAYS)
    {
        $canonical = self::normalizeNumber($canonicalNumber);
        if ($canonical === '') {
            return array('score' => null, 'histogram' => array(), 'calls' => array());
        }

        $db         = Yii::app()->db;
        $idCampaign = max(0, (int) $idCampaign);
        $idTrunk    = max(0, (int) $idTrunk);
        $since      = date('Y-m-d H:i:s', time() - (max(1, (int) $windowDays) * 86400));

        $scoreSql = "SELECT s.*,
                            c.name AS campaign_name,
                            t.trunkcode AS trunk_code,
                            u.username AS user_name
                     FROM pkg_cdr_number_quality_score s
                     LEFT JOIN pkg_campaign c ON c.id = s.id_campaign
                     LEFT JOIN pkg_trunk t ON t.id = s.id_trunk
                     LEFT JOIN pkg_user u ON u.id = s.id_user
                     WHERE s.canonical_number = :num
                       AND s.id_campaign = :camp
                       AND s.id_trunk = :trunk
                     ORDER BY s.attempts DESC
                     LIMIT 1";
        $score = $db->createCommand($scoreSql)
            ->bindValue(':num', $canonical, PDO::PARAM_STR)
            ->bindValue(':camp', $idCampaign, PDO::PARAM_INT)
            ->bindValue(':trunk', $idTrunk, PDO::PARAM_INT)
            ->queryRow();

        $candidates = array($canonical, '+' . $canonical);
        if ($idTrunk > 0) {
            $trunkPrefixes = self::getTrunkPrefixMap();
            $tp            = $trunkPrefixes[$idTrunk] ?? '';
            if ($tp !== '') {
                $candidates[] = $tp . $canonical;
                $candidates[] = '+' . $tp . $canonical;
            }
        }
        $candidates   = array_values(array_unique($candidates));
        $inPlaceholders = array();
        $inParams       = array();
        foreach ($candidates as $idx => $cand) {
            $ph                 = ':cs' . $idx;
            $inPlaceholders[]   = $ph;
            $inParams[$ph]      = $cand;
        }
        $calledInSql = 'IN (' . implode(', ', $inPlaceholders) . ')';

        // Answered calls (use index on calledstation + filter starttime)
        $cdrWhere = array(
            "c.calledstation {$calledInSql}",
            'c.starttime >= :since',
        );
        $cdrParams = array_merge($inParams, array(
            ':since' => $since,
        ));
        if ($idCampaign > 0) {
            $cdrWhere[]               = 'COALESCE(c.id_campaign, 0) = :idCamp';
            $cdrParams[':idCamp']     = $idCampaign;
        }
        if ($idTrunk > 0) {
            $cdrWhere[]               = 'COALESCE(c.id_trunk, 0) = :idTrunk';
            $cdrParams[':idTrunk']    = $idTrunk;
        }
        $cdrWhereSql = implode(' AND ', $cdrWhere);

        $cdrSql = "SELECT
                       'ANSWERED' AS call_type,
                       c.starttime,
                       COALESCE(c.real_sessiontime, c.sessiontime, 0) AS duration_sec,
                       c.terminatecauseid,
                       0 AS hangupcause,
                       COALESCE(t.trunkcode, CONCAT('#', COALESCE(c.id_trunk, 0))) AS trunkcode,
                       c.buycost,
                       c.src,
                       c.callerid
                   FROM pkg_cdr c
                   LEFT JOIN pkg_trunk t ON t.id = c.id_trunk
                   WHERE {$cdrWhereSql}
                   ORDER BY c.starttime DESC
                   LIMIT 30";
        $cdrCmd = $db->createCommand($cdrSql);
        self::bindParams($cdrCmd, $cdrParams);
        $answeredCalls = $cdrCmd->queryAll() ?: array();

        // Failed calls
        $failWhere = array(
            "f.calledstation {$calledInSql}",
            'f.starttime >= :since',
        );
        $failParams = array_merge($inParams, array(
            ':since' => $since,
        ));
        if ($idCampaign > 0) {
            $failWhere[]             = "f.sessionid = :mcSess";
            $failParams[':mcSess']   = 'MC-' . $idCampaign;
        }
        if ($idTrunk > 0) {
            $failWhere[]              = 'COALESCE(f.id_trunk, 0) = :idTrunk';
            $failParams[':idTrunk']   = $idTrunk;
        }
        $failWhereSql = implode(' AND ', $failWhere);

        $failSql = "SELECT
                        'FAILED' AS call_type,
                        f.starttime,
                        0 AS duration_sec,
                        COALESCE(f.terminatecauseid, 0) AS terminatecauseid,
                        COALESCE(f.hangupcause, 0) AS hangupcause,
                        COALESCE(t.trunkcode, CONCAT('#', COALESCE(f.id_trunk, 0))) AS trunkcode,
                        0 AS buycost,
                        f.src,
                        f.callerid
                    FROM pkg_cdr_failed f
                    LEFT JOIN pkg_trunk t ON t.id = f.id_trunk
                    WHERE {$failWhereSql}
                    ORDER BY f.starttime DESC
                    LIMIT 30";
        $failCmd = $db->createCommand($failSql);
        self::bindParams($failCmd, $failParams);
        $failedCalls = $failCmd->queryAll() ?: array();

        $merged = array_merge($answeredCalls, $failedCalls);
        usort($merged, function ($a, $b) {
            return strcmp((string) ($b['starttime'] ?? ''), (string) ($a['starttime'] ?? ''));
        });
        $merged = array_slice($merged, 0, 50);

        foreach ($merged as &$call) {
            $call['cause_label'] = self::formatTerminateCause(
                (int) ($call['terminatecauseid'] ?? 0),
                (int) ($call['hangupcause'] ?? 0),
                (string) ($call['call_type'] ?? '')
            );
        }
        unset($call);

        $histogram = array();
        if (is_array($score)) {
            $ans = max(1, (int) ($score['answered'] ?? 0));
            $b1  = (int) ($score['cnt_short_5s'] ?? 0);
            $b2  = (int) ($score['cnt_6_30s'] ?? 0);
            $b3  = (int) ($score['cnt_31_179s'] ?? 0);
            $b4  = (int) ($score['cnt_long_180s'] ?? 0);
            $histogram = array(
                array('bucket' => '0-5s',   'count' => $b1, 'pct' => round(($b1 / $ans) * 100, 1)),
                array('bucket' => '6-30s',  'count' => $b2, 'pct' => round(($b2 / $ans) * 100, 1)),
                array('bucket' => '31-179s','count' => $b3, 'pct' => round(($b3 / $ans) * 100, 1)),
                array('bucket' => '>=180s', 'count' => $b4, 'pct' => round(($b4 / $ans) * 100, 1)),
            );
        }

        return array(
            'score'     => $score ?: null,
            'histogram' => $histogram,
            'calls'     => $merged,
        );
    }

    /**
     * Runs incremental batch ingestion from pkg_cdr and pkg_cdr_failed using pkg_cdr_summary_ids watermark.
     *
     * @param array $watermark
     * @return array
     */
    private static function performIncrementalSync(array $watermark)
    {
        $db       = Yii::app()->db;
        $lastCdr  = (int) ($watermark['cdr_id'] ?? 0);
        $lastFail = (int) ($watermark['cdr_falide_id'] ?? 0);

        $maxCdr  = (int) $db->createCommand("SELECT COALESCE(MAX(id), 0) FROM pkg_cdr")->queryScalar();
        $maxFail = (int) $db->createCommand("SELECT COALESCE(MAX(id), 0) FROM pkg_cdr_failed")->queryScalar();

        $cdrProcessed  = 0;
        $failProcessed = 0;
        $trunkPrefixes = self::getTrunkPrefixMap();

        while ($lastCdr < $maxCdr) {
            $batchEnd = min($maxCdr, $lastCdr + self::BATCH_SIZE);
            $rows = $db->createCommand(
                "SELECT id, id_user, COALESCE(id_campaign, 0) AS id_campaign, COALESCE(id_trunk, 0) AS id_trunk,
                        starttime, calledstation, COALESCE(real_sessiontime, sessiontime, 0) AS duration_sec,
                        COALESCE(buycost, 0) AS buycost, COALESCE(terminatecauseid, 1) AS terminatecauseid,
                        COALESCE(sipiax, 0) AS sipiax
                 FROM pkg_cdr
                 WHERE id > :startId AND id <= :endId
                 ORDER BY id ASC"
            )
                ->bindValue(':startId', $lastCdr, PDO::PARAM_INT)
                ->bindValue(':endId', $batchEnd, PDO::PARAM_INT)
                ->queryAll();

            $buckets = self::aggregateCdrRows($rows, $trunkPrefixes);
            self::upsertDailyBuckets($buckets, true);
            $cdrProcessed += count($rows);
            $lastCdr = $batchEnd;
            self::saveWatermark($lastCdr, $lastFail);
        }

        while ($lastFail < $maxFail) {
            $batchEnd = min($maxFail, $lastFail + self::BATCH_SIZE);
            $rows = $db->createCommand(
                "SELECT id, id_user,
                        CASE WHEN sessionid LIKE 'MC-%' THEN CAST(SUBSTRING(sessionid, 4) AS UNSIGNED) ELSE 0 END AS id_campaign,
                        COALESCE(id_trunk, 0) AS id_trunk,
                        starttime, calledstation, sessionid,
                        COALESCE(sipiax, 0) AS sipiax,
                        COALESCE(terminatecauseid, 0) AS terminatecauseid,
                        COALESCE(hangupcause, 0) AS hangupcause
                 FROM pkg_cdr_failed
                 WHERE id > :startId AND id <= :endId
                 ORDER BY id ASC"
            )
                ->bindValue(':startId', $lastFail, PDO::PARAM_INT)
                ->bindValue(':endId', $batchEnd, PDO::PARAM_INT)
                ->queryAll();

            $buckets = self::aggregateFailedRows($rows, $trunkPrefixes);
            self::upsertDailyBuckets($buckets, true);
            $failProcessed += count($rows);
            $lastFail = $batchEnd;
            self::saveWatermark($lastCdr, $lastFail);
        }

        return array(
            'cdr_processed'  => $cdrProcessed,
            'fail_processed' => $failProcessed,
        );
    }

    /**
     * Rebuilds the last N days of daily buckets safely using keyset pagination on id.
     *
     * @param int $days
     * @return array
     */
    private static function performBackfill($days)
    {
        $db        = Yii::app()->db;
        $days      = max(1, (int) $days);
        $cutoffDay = date('Y-m-d', strtotime("-{$days} days"));
        $cutoffDt  = $cutoffDay . ' 00:00:00';

        $db->createCommand("DELETE FROM pkg_cdr_number_quality_daily WHERE day >= :cutoff")
            ->bindValue(':cutoff', $cutoffDay, PDO::PARAM_STR)
            ->execute();

        $cdrProcessed  = 0;
        $failProcessed = 0;
        $trunkPrefixes = self::getTrunkPrefixMap();

        $lastCdrId = (int) $db->createCommand(
            "SELECT COALESCE(MIN(id) - 1, 0) FROM pkg_cdr WHERE starttime >= :cutoff"
        )->bindValue(':cutoff', $cutoffDt, PDO::PARAM_STR)->queryScalar();
        if ($lastCdrId < 0) {
            $lastCdrId = 0;
        }
        $maxCdrId = (int) $db->createCommand("SELECT COALESCE(MAX(id), 0) FROM pkg_cdr")->queryScalar();

        while ($lastCdrId < $maxCdrId) {
            $rows = $db->createCommand(
                "SELECT id, id_user, COALESCE(id_campaign, 0) AS id_campaign, COALESCE(id_trunk, 0) AS id_trunk,
                        starttime, calledstation, COALESCE(real_sessiontime, sessiontime, 0) AS duration_sec,
                        COALESCE(buycost, 0) AS buycost, COALESCE(terminatecauseid, 1) AS terminatecauseid,
                        COALESCE(sipiax, 0) AS sipiax
                 FROM pkg_cdr
                 WHERE id > :lastId AND id <= :maxId AND starttime >= :cutoff
                 ORDER BY id ASC
                 LIMIT " . self::BATCH_SIZE
            )
                ->bindValue(':lastId', $lastCdrId, PDO::PARAM_INT)
                ->bindValue(':maxId', $maxCdrId, PDO::PARAM_INT)
                ->bindValue(':cutoff', $cutoffDt, PDO::PARAM_STR)
                ->queryAll();

            if (empty($rows)) {
                break;
            }
            $lastRow   = end($rows);
            $lastCdrId = (int) $lastRow['id'];
            $buckets   = self::aggregateCdrRows($rows, $trunkPrefixes);
            self::upsertDailyBuckets($buckets, true);
            $cdrProcessed += count($rows);
        }

        $lastFailId = (int) $db->createCommand(
            "SELECT COALESCE(MIN(id) - 1, 0) FROM pkg_cdr_failed WHERE starttime >= :cutoff"
        )->bindValue(':cutoff', $cutoffDt, PDO::PARAM_STR)->queryScalar();
        if ($lastFailId < 0) {
            $lastFailId = 0;
        }
        $maxFailId = (int) $db->createCommand("SELECT COALESCE(MAX(id), 0) FROM pkg_cdr_failed")->queryScalar();

        while ($lastFailId < $maxFailId) {
            $rows = $db->createCommand(
                "SELECT id, id_user,
                        CASE WHEN sessionid LIKE 'MC-%' THEN CAST(SUBSTRING(sessionid, 4) AS UNSIGNED) ELSE 0 END AS id_campaign,
                        COALESCE(id_trunk, 0) AS id_trunk,
                        starttime, calledstation, sessionid,
                        COALESCE(sipiax, 0) AS sipiax,
                        COALESCE(terminatecauseid, 0) AS terminatecauseid,
                        COALESCE(hangupcause, 0) AS hangupcause
                 FROM pkg_cdr_failed
                 WHERE id > :lastId AND id <= :maxId AND starttime >= :cutoff
                 ORDER BY id ASC
                 LIMIT " . self::BATCH_SIZE
            )
                ->bindValue(':lastId', $lastFailId, PDO::PARAM_INT)
                ->bindValue(':maxId', $maxFailId, PDO::PARAM_INT)
                ->bindValue(':cutoff', $cutoffDt, PDO::PARAM_STR)
                ->queryAll();

            if (empty($rows)) {
                break;
            }
            $lastRow    = end($rows);
            $lastFailId = (int) $lastRow['id'];
            $buckets    = self::aggregateFailedRows($rows, $trunkPrefixes);
            self::upsertDailyBuckets($buckets, true);
            $failProcessed += count($rows);
        }

        self::saveWatermark($maxCdrId, $maxFailId);

        return array(
            'cdr_processed'  => $cdrProcessed,
            'fail_processed' => $failProcessed,
        );
    }

    /**
     * Computes campaign baselines and updates pkg_cdr_number_quality_score from the N-day window.
     *
     * @param int $windowDays
     * @param int $minAttempts
     * @return int Number of scored rows upserted
     */
    private static function recomputeScoresInternal($windowDays, $minAttempts)
    {
        $db        = Yii::app()->db;
        $cutoffDay = date('Y-m-d', strtotime("-{$windowDays} days"));

        // 1. Campaign & global baselines
        $baseRows = $db->createCommand(
            "SELECT
                 id_campaign,
                 SUM(attempts) AS attempts,
                 SUM(answered) AS answered,
                 SUM(cnt_short_5s) AS cnt_short_5s,
                 SUM(cnt_mid_short) AS cnt_mid_short
             FROM pkg_cdr_number_quality_daily
             WHERE day >= :cutoff
             GROUP BY id_campaign"
        )->bindValue(':cutoff', $cutoffDay, PDO::PARAM_STR)->queryAll();

        $baselines = array();
        $globAtt = 0;
        $globAns = 0;
        $glob5s  = 0;
        $globMid = 0;

        foreach ($baseRows as $bRow) {
            $att = (int) $bRow['attempts'];
            $ans = (int) $bRow['answered'];
            $s5  = (int) $bRow['cnt_short_5s'];
            $mid = (int) $bRow['cnt_mid_short'];

            $globAtt += $att;
            $globAns += $ans;
            $glob5s  += $s5;
            $globMid += $mid;

            $baselines[(int) $bRow['id_campaign']] = array(
                'asr'       => $att > 0 ? ($ans / $att) : 0.35,
                'sdr_5s'    => $ans > 0 ? ($s5 / $ans) : 0.25,
                'mid_short' => $ans > 0 ? ($mid / $ans) : 0.40,
            );
        }

        $globalBaseline = array(
            'asr'       => $globAtt > 0 ? ($globAns / $globAtt) : 0.35,
            'sdr_5s'    => $globAns > 0 ? ($glob5s / $globAns) : 0.25,
            'mid_short' => $globAns > 0 ? ($globMid / $globAns) : 0.40,
        );

        // 2. Aggregated rolling metrics per (canonical_number, id_campaign, id_trunk, id_user)
        $aggRows = $db->createCommand(
            "SELECT
                 canonical_number,
                 id_user,
                 id_campaign,
                 id_trunk,
                 SUM(attempts) AS attempts,
                 SUM(answered) AS answered,
                 SUM(failed) AS failed,
                 SUM(cnt_short_5s) AS cnt_short_5s,
                 SUM(cnt_6_30s) AS cnt_6_30s,
                 SUM(cnt_31_179s) AS cnt_31_179s,
                 SUM(cnt_mid_short) AS cnt_mid_short,
                 SUM(cnt_long_180s) AS cnt_long_180s,
                 SUM(sum_duration) AS sum_duration,
                 SUM(sum_duration_sq) AS sum_duration_sq,
                 SUM(fail_no_answer) AS fail_no_answer,
                 SUM(fail_busy) AS fail_busy,
                 SUM(fail_cancel) AS fail_cancel,
                 SUM(fail_congestion) AS fail_congestion,
                 SUM(fail_chanunavail) AS fail_chanunavail,
                 SUM(fail_other) AS fail_other,
                 MAX(last_hangupcause) AS last_hangupcause,
                 SUM(sum_buycost) AS buycost_total,
                 MAX(last_call_at) AS last_call_at
             FROM pkg_cdr_number_quality_daily
             WHERE day >= :cutoff
             GROUP BY canonical_number, id_user, id_campaign, id_trunk"
        )->bindValue(':cutoff', $cutoffDay, PDO::PARAM_STR)->queryAll();

        $evaluated = array();
        foreach ($aggRows as $row) {
            $campId   = (int) $row['id_campaign'];
            $baseline = $baselines[$campId] ?? $globalBaseline;
            $scored   = self::evaluateMetrics($row, $baseline, array('min_attempts' => $minAttempts));

            $evaluated[] = array_merge($scored, array(
                'canonical_number' => (string) $row['canonical_number'],
                'id_user'          => (int) $row['id_user'],
                'id_campaign'      => $campId,
                'id_trunk'         => (int) $row['id_trunk'],
                'window_days'      => $windowDays,
                'last_call_at'     => $row['last_call_at'],
            ));
        }

        $evaluated = self::applyCrossTrunkSeparation($evaluated);

        $syncTimestamp = date('Y-m-d H:i:s');
        self::upsertScoreRows($evaluated, $syncTimestamp);

        // Remove stale scores that dropped out of the rolling window
        $db->createCommand("DELETE FROM pkg_cdr_number_quality_score WHERE updated_at < :syncTs")
            ->bindValue(':syncTs', $syncTimestamp, PDO::PARAM_STR)
            ->execute();

        return count($evaluated);
    }

    /**
     * Groups raw pkg_cdr rows into daily bucket arrays keyed cleanly for batch upsert.
     *
     * @param array $rows
     * @param array $trunkPrefixes Map of id_trunk => numeric trunkprefix
     * @return array
     */
    private static function aggregateCdrRows(array $rows, array $trunkPrefixes = array())
    {
        $buckets = array();
        foreach ($rows as $r) {
            $trunkId     = (int) ($r['id_trunk'] ?? 0);
            $trunkPrefix = $trunkPrefixes[$trunkId] ?? '';
            $sipiax      = (int) ($r['sipiax'] ?? 0);
            // MassiveCall answered CDRs (sipiax=5) originate from .call Extension/dnid without trunkprefix.
            $canonical   = self::normalizeNumberForTrunk($r['calledstation'] ?? '', $trunkPrefix, $sipiax !== 5);
            if ($canonical === '') {
                continue;
            }
            $ts  = (string) ($r['starttime'] ?? '');
            $day = strlen($ts) >= 10 ? substr($ts, 0, 10) : date('Y-m-d');
            $dur = max(0, (int) ($r['duration_sec'] ?? 0));

            $key = $day . '|' . $canonical . '|' . (int) $r['id_campaign'] . '|' . (int) $r['id_trunk'] . '|' . (int) $r['id_user'];
            if (!isset($buckets[$key])) {
                $buckets[$key] = self::initDailyBucket(
                    $day,
                    $canonical,
                    (int) $r['id_user'],
                    (int) $r['id_campaign'],
                    (int) $r['id_trunk']
                );
            }

            $b =& $buckets[$key];
            $b['attempts']        += 1;
            $b['answered']        += 1;
            $b['sum_duration']    += $dur;
            $b['sum_duration_sq'] += ($dur * $dur);
            $b['sum_buycost']     += (float) ($r['buycost'] ?? 0);
            $b['min_duration']     = ($b['min_duration'] === null) ? $dur : min($b['min_duration'], $dur);
            $b['max_duration']     = ($b['max_duration'] === null) ? $dur : max($b['max_duration'], $dur);

            if ($dur <= self::SHORT_5S_MAX) {
                $b['cnt_short_5s'] += 1;
            } elseif ($dur <= 30) {
                $b['cnt_6_30s']     += 1;
                $b['cnt_mid_short'] += 1;
            } elseif ($dur <= self::MID_SHORT_MAX) {
                $b['cnt_31_179s']   += 1;
                $b['cnt_mid_short'] += 1;
            } else {
                $b['cnt_long_180s'] += 1;
            }

            if ($ts !== '' && ($b['last_call_at'] === null || $ts > $b['last_call_at'])) {
                $b['last_call_at'] = $ts;
            }
            $b['last_terminatecauseid'] = (int) ($r['terminatecauseid'] ?? 1);
            unset($b);
        }

        return array_values($buckets);
    }

    /**
     * Groups raw pkg_cdr_failed rows into daily bucket arrays.
     *
     * @param array $rows
     * @param array $trunkPrefixes Map of id_trunk => numeric trunkprefix
     * @return array
     */
    private static function aggregateFailedRows(array $rows, array $trunkPrefixes = array())
    {
        $buckets = array();
        foreach ($rows as $r) {
            $trunkId     = (int) ($r['id_trunk'] ?? 0);
            $trunkPrefix = $trunkPrefixes[$trunkId] ?? '';
            $canonical   = self::normalizeNumberForTrunk($r['calledstation'] ?? '', $trunkPrefix, true);
            if ($canonical === '') {
                continue;
            }
            $ts  = (string) ($r['starttime'] ?? '');
            $day = strlen($ts) >= 10 ? substr($ts, 0, 10) : date('Y-m-d');

            $key = $day . '|' . $canonical . '|' . (int) $r['id_campaign'] . '|' . (int) $r['id_trunk'] . '|' . (int) $r['id_user'];
            if (!isset($buckets[$key])) {
                $buckets[$key] = self::initDailyBucket(
                    $day,
                    $canonical,
                    (int) $r['id_user'],
                    (int) $r['id_campaign'],
                    (int) $r['id_trunk']
                );
            }

            $b =& $buckets[$key];
            $b['attempts'] += 1;
            $b['failed']   += 1;

            $term = (int) ($r['terminatecauseid'] ?? 0);
            if ($term === 2) {
                $b['fail_busy'] += 1;
            } elseif ($term === 3) {
                $b['fail_no_answer'] += 1;
            } elseif ($term === 4) {
                $b['fail_cancel'] += 1;
            } elseif ($term === 5) {
                $b['fail_congestion'] += 1;
            } elseif ($term === 6) {
                $b['fail_chanunavail'] += 1;
            } else {
                $b['fail_other'] += 1;
            }

            if ($ts !== '' && ($b['last_call_at'] === null || $ts >= $b['last_call_at'])) {
                $b['last_call_at']          = $ts;
                $b['last_terminatecauseid'] = $term;
                $b['last_hangupcause']      = (int) ($r['hangupcause'] ?? 0);
            }
            unset($b);
        }

        return array_values($buckets);
    }

    /**
     * Batch upserts daily buckets into pkg_cdr_number_quality_daily inside a transaction.
     *
     * @param array $buckets
     * @param bool $additive
     * @return void
     */
    private static function upsertDailyBuckets(array $buckets, $additive = true)
    {
        if (empty($buckets)) {
            return;
        }

        $db = Yii::app()->db;
        $chunks = array_chunk($buckets, 250);

        foreach ($chunks as $chunk) {
            $placeholders = array();
            $params       = array();

            foreach ($chunk as $i => $b) {
                $p = ":r{$i}_";
                $placeholders[] = "({$p}day, {$p}num, {$p}user, {$p}camp, {$p}trunk,
                    {$p}att, {$p}ans, {$p}fail, {$p}s5, {$p}s630, {$p}s31, {$p}smid, {$p}slong,
                    {$p}sdur, {$p}sdursq, {$p}mindur, {$p}maxdur,
                    {$p}fnoans, {$p}fbusy, {$p}fcanc, {$p}fcong, {$p}fchan, {$p}foth,
                    {$p}lterm, {$p}lhang, {$p}cost, {$p}lcall)";

                $params[$p . 'day']    = $b['day'];
                $params[$p . 'num']    = $b['canonical_number'];
                $params[$p . 'user']   = (int) $b['id_user'];
                $params[$p . 'camp']   = (int) $b['id_campaign'];
                $params[$p . 'trunk']  = (int) $b['id_trunk'];
                $params[$p . 'att']    = (int) $b['attempts'];
                $params[$p . 'ans']    = (int) $b['answered'];
                $params[$p . 'fail']   = (int) $b['failed'];
                $params[$p . 's5']     = (int) $b['cnt_short_5s'];
                $params[$p . 's630']   = (int) $b['cnt_6_30s'];
                $params[$p . 's31']    = (int) $b['cnt_31_179s'];
                $params[$p . 'smid']   = (int) $b['cnt_mid_short'];
                $params[$p . 'slong']  = (int) $b['cnt_long_180s'];
                $params[$p . 'sdur']   = (int) $b['sum_duration'];
                $params[$p . 'sdursq'] = (float) $b['sum_duration_sq'];
                $params[$p . 'mindur'] = $b['min_duration'];
                $params[$p . 'maxdur'] = $b['max_duration'];
                $params[$p . 'fnoans'] = (int) $b['fail_no_answer'];
                $params[$p . 'fbusy']  = (int) $b['fail_busy'];
                $params[$p . 'fcanc']  = (int) $b['fail_cancel'];
                $params[$p . 'fcong']  = (int) $b['fail_congestion'];
                $params[$p . 'fchan']  = (int) $b['fail_chanunavail'];
                $params[$p . 'foth']   = (int) $b['fail_other'];
                $params[$p . 'lterm']  = (int) $b['last_terminatecauseid'];
                $params[$p . 'lhang']  = (int) $b['last_hangupcause'];
                $params[$p . 'cost']   = round((float) $b['sum_buycost'], 6);
                $params[$p . 'lcall']  = $b['last_call_at'];
            }

            $sql = "INSERT INTO pkg_cdr_number_quality_daily (
                        day, canonical_number, id_user, id_campaign, id_trunk,
                        attempts, answered, failed, cnt_short_5s, cnt_6_30s, cnt_31_179s, cnt_mid_short, cnt_long_180s,
                        sum_duration, sum_duration_sq, min_duration, max_duration,
                        fail_no_answer, fail_busy, fail_cancel, fail_congestion, fail_chanunavail, fail_other,
                        last_terminatecauseid, last_hangupcause, sum_buycost, last_call_at
                    ) VALUES " . implode(', ', $placeholders);

            if ($additive) {
                $sql .= " ON DUPLICATE KEY UPDATE
                            attempts = attempts + VALUES(attempts),
                            answered = answered + VALUES(answered),
                            failed = failed + VALUES(failed),
                            cnt_short_5s = cnt_short_5s + VALUES(cnt_short_5s),
                            cnt_6_30s = cnt_6_30s + VALUES(cnt_6_30s),
                            cnt_31_179s = cnt_31_179s + VALUES(cnt_31_179s),
                            cnt_mid_short = cnt_mid_short + VALUES(cnt_mid_short),
                            cnt_long_180s = cnt_long_180s + VALUES(cnt_long_180s),
                            sum_duration = sum_duration + VALUES(sum_duration),
                            sum_duration_sq = sum_duration_sq + VALUES(sum_duration_sq),
                            min_duration = CASE
                                WHEN min_duration IS NULL THEN VALUES(min_duration)
                                WHEN VALUES(min_duration) IS NULL THEN min_duration
                                ELSE LEAST(min_duration, VALUES(min_duration))
                            END,
                            max_duration = CASE
                                WHEN max_duration IS NULL THEN VALUES(max_duration)
                                WHEN VALUES(max_duration) IS NULL THEN max_duration
                                ELSE GREATEST(max_duration, VALUES(max_duration))
                            END,
                            fail_no_answer = fail_no_answer + VALUES(fail_no_answer),
                            fail_busy = fail_busy + VALUES(fail_busy),
                            fail_cancel = fail_cancel + VALUES(fail_cancel),
                            fail_congestion = fail_congestion + VALUES(fail_congestion),
                            fail_chanunavail = fail_chanunavail + VALUES(fail_chanunavail),
                            fail_other = fail_other + VALUES(fail_other),
                            last_terminatecauseid = IF(VALUES(last_call_at) >= COALESCE(last_call_at, '1970-01-01'), VALUES(last_terminatecauseid), last_terminatecauseid),
                            last_hangupcause = IF(VALUES(last_hangupcause) > 0 AND VALUES(last_call_at) >= COALESCE(last_call_at, '1970-01-01'), VALUES(last_hangupcause), last_hangupcause),
                            sum_buycost = sum_buycost + VALUES(sum_buycost),
                            last_call_at = GREATEST(COALESCE(last_call_at, '1970-01-01'), COALESCE(VALUES(last_call_at), '1970-01-01'))";
            }

            $tx = $db->beginTransaction();
            try {
                $cmd = $db->createCommand($sql);
                self::bindParams($cmd, $params);
                $cmd->execute();
                $tx->commit();
            } catch (Exception $e) {
                $tx->rollback();
                throw $e;
            }
        }
    }

    /**
     * Batch upserts evaluated score rows into pkg_cdr_number_quality_score.
     *
     * @param array $rows
     * @param string $syncTimestamp
     * @return void
     */
    private static function upsertScoreRows(array $rows, $syncTimestamp)
    {
        if (empty($rows)) {
            return;
        }

        $db     = Yii::app()->db;
        $chunks = array_chunk($rows, 250);

        foreach ($chunks as $chunk) {
            $placeholders = array();
            $params       = array(':syncTs' => $syncTimestamp);

            foreach ($chunk as $i => $row) {
                $p = ":s{$i}_";
                $placeholders[] = "({$p}num, {$p}user, {$p}camp, {$p}trunk, {$p}win,
                    {$p}att, {$p}ans, {$p}fail, {$p}s5, {$p}s630, {$p}s31, {$p}smid, {$p}slong,
                    {$p}asr, {$p}sdr5, {$p}mids, {$p}acd, {$p}std, {$p}cv,
                    {$p}dfc, {$p}dfp, {$p}lhang, {$p}cost, {$p}qscore, {$p}conf, {$p}qlabel,
                    {$p}reason, {$p}tsusp, {$p}lcall, :syncTs)";

                $params[$p . 'num']    = $row['canonical_number'];
                $params[$p . 'user']   = (int) $row['id_user'];
                $params[$p . 'camp']   = (int) $row['id_campaign'];
                $params[$p . 'trunk']  = (int) $row['id_trunk'];
                $params[$p . 'win']    = (int) $row['window_days'];
                $params[$p . 'att']    = (int) $row['attempts'];
                $params[$p . 'ans']    = (int) $row['answered'];
                $params[$p . 'fail']   = (int) $row['failed'];
                $params[$p . 's5']     = (int) $row['cnt_short_5s'];
                $params[$p . 's630']   = (int) $row['cnt_6_30s'];
                $params[$p . 's31']    = (int) $row['cnt_31_179s'];
                $params[$p . 'smid']   = (int) $row['cnt_mid_short'];
                $params[$p . 'slong']  = (int) $row['cnt_long_180s'];
                $params[$p . 'asr']    = (float) $row['asr_pct'];
                $params[$p . 'sdr5']   = (float) $row['sdr_5s_pct'];
                $params[$p . 'mids']   = (float) $row['mid_short_pct'];
                $params[$p . 'acd']    = (float) $row['acd_sec'];
                $params[$p . 'std']    = (float) $row['duration_stddev'];
                $params[$p . 'cv']     = (float) $row['duration_cv'];
                $params[$p . 'dfc']    = (string) $row['dominant_fail_cause'];
                $params[$p . 'dfp']    = (float) $row['dominant_fail_pct'];
                $params[$p . 'lhang']  = (int) $row['last_hangupcause'];
                $params[$p . 'cost']   = (float) $row['buycost_total'];
                $params[$p . 'qscore'] = (float) $row['quality_score'];
                $params[$p . 'conf']   = (float) $row['confidence'];
                $params[$p . 'qlabel'] = (string) $row['quality_label'];
                $params[$p . 'reason'] = (string) $row['reason_summary'];
                $params[$p . 'tsusp']  = (int) $row['is_trunk_suspect'];
                $params[$p . 'lcall']  = $row['last_call_at'];
            }

            $sql = "INSERT INTO pkg_cdr_number_quality_score (
                        canonical_number, id_user, id_campaign, id_trunk, window_days,
                        attempts, answered, failed, cnt_short_5s, cnt_6_30s, cnt_31_179s, cnt_mid_short, cnt_long_180s,
                        asr_pct, sdr_5s_pct, mid_short_pct, acd_sec, duration_stddev, duration_cv,
                        dominant_fail_cause, dominant_fail_pct, last_hangupcause, buycost_total,
                        quality_score, confidence, quality_label, reason_summary, is_trunk_suspect, last_call_at, updated_at
                    ) VALUES " . implode(', ', $placeholders) . "
                    ON DUPLICATE KEY UPDATE
                        window_days = VALUES(window_days),
                        attempts = VALUES(attempts),
                        answered = VALUES(answered),
                        failed = VALUES(failed),
                        cnt_short_5s = VALUES(cnt_short_5s),
                        cnt_6_30s = VALUES(cnt_6_30s),
                        cnt_31_179s = VALUES(cnt_31_179s),
                        cnt_mid_short = VALUES(cnt_mid_short),
                        cnt_long_180s = VALUES(cnt_long_180s),
                        asr_pct = VALUES(asr_pct),
                        sdr_5s_pct = VALUES(sdr_5s_pct),
                        mid_short_pct = VALUES(mid_short_pct),
                        acd_sec = VALUES(acd_sec),
                        duration_stddev = VALUES(duration_stddev),
                        duration_cv = VALUES(duration_cv),
                        dominant_fail_cause = VALUES(dominant_fail_cause),
                        dominant_fail_pct = VALUES(dominant_fail_pct),
                        last_hangupcause = VALUES(last_hangupcause),
                        buycost_total = VALUES(buycost_total),
                        quality_score = VALUES(quality_score),
                        confidence = VALUES(confidence),
                        quality_label = VALUES(quality_label),
                        reason_summary = VALUES(reason_summary),
                        is_trunk_suspect = VALUES(is_trunk_suspect),
                        last_call_at = VALUES(last_call_at),
                        updated_at = VALUES(updated_at)";

            $tx = $db->beginTransaction();
            try {
                $cmd = $db->createCommand($sql);
                self::bindParams($cmd, $params);
                $cmd->execute();
                $tx->commit();
            } catch (Exception $e) {
                $tx->rollback();
                throw $e;
            }
        }
    }

    private static function getWatermark()
    {
        $row = Yii::app()->db->createCommand(
            "SELECT id, cdr_id, cdr_falide_id, updated_at
             FROM pkg_cdr_summary_ids
             WHERE summary_name = :name
             LIMIT 1"
        )->bindValue(':name', self::SUMMARY_NAME, PDO::PARAM_STR)->queryRow();

        return is_array($row) ? $row : null;
    }

    private static function saveWatermark($cdrId, $failId)
    {
        Yii::app()->db->createCommand(
            "INSERT INTO pkg_cdr_summary_ids (summary_name, day, cdr_id, cdr_falide_id, updated_at)
             VALUES (:name, :day, :cdrId, :failId, NOW())
             ON DUPLICATE KEY UPDATE
                 day = VALUES(day),
                 cdr_id = VALUES(cdr_id),
                 cdr_falide_id = VALUES(cdr_falide_id),
                 updated_at = NOW()"
        )
            ->bindValue(':name', self::SUMMARY_NAME, PDO::PARAM_STR)
            ->bindValue(':day', date('Y-m-d'), PDO::PARAM_STR)
            ->bindValue(':cdrId', (int) $cdrId, PDO::PARAM_INT)
            ->bindValue(':failId', (int) $failId, PDO::PARAM_INT)
            ->execute();
    }

    private static function resolveDominantFailure(array $metrics, $attempts, $failed)
    {
        if ($failed <= 0) {
            return array('cause' => 'NONE', 'pct' => 0.0);
        }

        $counts = array(
            'NO_ANSWER'   => (int) ($metrics['fail_no_answer'] ?? 0),
            'BUSY'        => (int) ($metrics['fail_busy'] ?? 0),
            'CANCEL'      => (int) ($metrics['fail_cancel'] ?? 0),
            'CONGESTION'  => (int) ($metrics['fail_congestion'] ?? 0),
            'CHANUNAVAIL' => (int) ($metrics['fail_chanunavail'] ?? 0),
            'OTHER'       => (int) ($metrics['fail_other'] ?? 0),
        );

        arsort($counts);
        $topCause = (string) key($counts);
        $topCount = (int) current($counts);

        if ($topCount <= 0) {
            return array('cause' => 'FAILED', 'pct' => $attempts > 0 ? round(($failed / $attempts) * 100.0, 2) : 0.0);
        }

        return array(
            'cause' => $topCause,
            'pct'   => $attempts > 0 ? round(($topCount / $attempts) * 100.0, 2) : 0.0,
        );
    }

    private static function classifyNumber(array $d)
    {
        $attempts  = $d['attempts'];
        $answered  = $d['answered'];
        $minAtt    = $d['min_attempts'];
        $asr       = $d['asr_pct'];
        $sdr5      = $d['sdr_5s_pct'];
        $acd       = $d['acd_sec'];
        $cv        = $d['duration_cv'];
        $cnt5      = $d['cnt_short_5s'];
        $cntMid    = $d['cnt_mid_short'];
        $cntLong   = $d['cnt_long_180s'];
        $domCause  = $d['dom_fail_cause'];
        $domPct    = $d['dom_fail_pct'];
        $score     = $d['quality_score'];

        if ($attempts < $minAtt) {
            return array('INSUFFICIENT_DATA', "Muestra insuficiente ({$attempts}/{$minAtt} intentos)");
        }

        if ($answered >= 5 && $cv > 0.0 && $cv < 0.22 && $acd <= self::MID_SHORT_MAX) {
            return array('RIGID_CUT_PATTERN', "Corte repetitivo ~{$acd}s (CV {$cv}, {$answered} resp.)");
        }

        if ($asr < 12.0 && $domPct >= 50.0) {
            return array('UNREACHABLE_FAIL_PATTERN', "ASR {$asr}% · fallo dominante {$domCause} ({$domPct}%)");
        }

        if ($answered >= 3 && $sdr5 >= 60.0) {
            return array('BAD_SHORT_5S', "{$sdr5}% de respuestas cortan <=5s ({$cnt5}/{$answered})");
        }

        if ($answered >= 3 && $cntLong === 0 && ($cnt5 + $cntMid) >= 4 && ($acd < 90.0 || ($cntMid / max(1, $answered)) >= 0.75)) {
            return array('BAD_UNDER_3MIN', "0 llamadas >=3m ({$answered} resp. <180s, ACD {$acd}s)");
        }

        if ($score < 45.0 || $asr < 20.0) {
            return array('WATCHLIST', "Score {$score} · ASR {$asr}%, <=5s {$sdr5}%, ACD {$acd}s");
        }

        return array('HEALTHY', "Saludable · Score {$score}, ASR {$asr}%, >=3m {$cntLong}/{$answered}");
    }

    private static function initDailyBucket($day, $canonical, $idUser, $idCampaign, $idTrunk)
    {
        return array(
            'day'                   => $day,
            'canonical_number'      => $canonical,
            'id_user'               => $idUser,
            'id_campaign'           => $idCampaign,
            'id_trunk'              => $idTrunk,
            'attempts'              => 0,
            'answered'              => 0,
            'failed'                => 0,
            'cnt_short_5s'          => 0,
            'cnt_6_30s'             => 0,
            'cnt_31_179s'           => 0,
            'cnt_mid_short'         => 0,
            'cnt_long_180s'         => 0,
            'sum_duration'          => 0,
            'sum_duration_sq'       => 0.0,
            'min_duration'          => null,
            'max_duration'          => null,
            'fail_no_answer'        => 0,
            'fail_busy'             => 0,
            'fail_cancel'           => 0,
            'fail_congestion'       => 0,
            'fail_chanunavail'      => 0,
            'fail_other'            => 0,
            'last_terminatecauseid' => 0,
            'last_hangupcause'      => 0,
            'sum_buycost'           => 0.0,
            'last_call_at'          => null,
        );
    }

    /**
     * Loads numeric trunkprefix values keyed by pkg_trunk.id.
     *
     * @return array
     */
    private static function getTrunkPrefixMap()
    {
        $map  = array();
        $rows = Yii::app()->db->createCommand("SELECT id, trunkprefix FROM pkg_trunk WHERE trunkprefix IS NOT NULL AND trunkprefix <> ''")->queryAll();
        foreach ($rows as $row) {
            $id     = (int) ($row['id'] ?? 0);
            $digits = preg_replace('/[^0-9]/', '', (string) ($row['trunkprefix'] ?? ''));
            if ($id > 0 && $digits !== '') {
                $map[$id] = $digits;
            }
        }
        return $map;
    }

    /**
     * Normalizes a dialled destination and optionally strips the trunk's outgoing technical prefix.
     *
     * @param string $raw
     * @param string $trunkPrefix
     * @param bool $allowStripTrunkPrefix
     * @return string
     */
    public static function normalizeNumberForTrunk($raw, $trunkPrefix = '', $allowStripTrunkPrefix = true)
    {
        $canonical = self::normalizeNumber($raw);
        if ($canonical === '' || !$allowStripTrunkPrefix) {
            return $canonical;
        }
        $prefixDigits = preg_replace('/[^0-9]/', '', (string) $trunkPrefix);
        if (
            $prefixDigits !== ''
            && strpos($canonical, $prefixDigits) === 0
            && (strlen($canonical) - strlen($prefixDigits)) >= 8
        ) {
            $canonical = substr($canonical, strlen($prefixDigits));
        }
        return $canonical;
    }

    private static function normalizeNumber($raw)
    {
        if (class_exists('CampaignAttemptService', false) || @file_exists(__DIR__ . '/CampaignAttemptService.php')) {
            if (!class_exists('CampaignAttemptService', false)) {
                require_once __DIR__ . '/CampaignAttemptService.php';
            }
            if (method_exists('CampaignAttemptService', 'normalizeCanonicalNumber')) {
                $norm = CampaignAttemptService::normalizeCanonicalNumber((string) $raw);
                $digits = preg_replace('/[^0-9]/', '', (string) $norm);
                return substr((string) $digits, 0, 32);
            }
        }
        $digits = preg_replace('/[^0-9]/', '', trim((string) $raw));
        return substr((string) $digits, 0, 32);
    }

    private static function formatTerminateCause($termId, $hangupCause, $callType)
    {
        if ($callType === 'ANSWERED') {
            return 'ANSWER (1)';
        }
        $map = array(
            2 => 'BUSY',
            3 => 'NOANSWER',
            4 => 'CANCEL',
            5 => 'CONGESTION',
            6 => 'CHANUNAVAIL',
            7 => 'DONTCALL',
            8 => 'TORTURE',
            9 => 'INVALIDARGS',
            10 => 'AMD',
        );
        $label = $map[$termId] ?? 'FAILED';
        return $hangupCause > 0 ? "{$label} (Q.850 {$hangupCause})" : $label;
    }

    private static function bindParams($cmd, array $params)
    {
        foreach ($params as $key => $val) {
            if ($val === null) {
                $cmd->bindValue($key, null, PDO::PARAM_NULL);
            } elseif (is_int($val)) {
                $cmd->bindValue($key, $val, PDO::PARAM_INT);
            } else {
                $cmd->bindValue($key, (string) $val, PDO::PARAM_STR);
            }
        }
    }

    private static function truncateReason($text)
    {
        $text = trim((string) $text);
        if (strlen($text) > 255) {
            return substr($text, 0, 252) . '...';
        }
        return $text;
    }

    private static function clamp($val, $min, $max)
    {
        return max($min, min($max, $val));
    }
}
