<?php
/**
 * Servicio para gestión y proyección de analítica de llamadas fallidas.
 *
 * Mantiene la tabla agregada pkg_campaign_failed_analytics para consultas
 * de alto rendimiento en la interfaz ExtJS y auditoría forense, además de
 * sincronizar intentos fallidos de campaña hacia pkg_cdr_failed (Call Failed).
 *
 * MagnusBilling 8 - Campaign Optimizer
 */
class CampaignAnalyticsService
{
    /**
     * Registra o actualiza la proyección de un intento fallido en el resumen diario.
     *
     * @param int $idCampaign
     * @param string $canonicalNumber
     * @param string $localDate
     * @param string $status 'BUSY' | 'NOANSWER' | 'FAILED' | 'CONGESTION' | 'CANCEL' | 'TIMED_OUT'
     * @param int|null $hangupcause
     * @param string|null $sipCode
     * @param bool $isSuspended
     * @return bool
     */
    public static function recordFailedAttempt($idCampaign, $canonicalNumber, $localDate, $status, $hangupcause = null, $sipCode = null, $isSuspended = false)
    {
        try {
            $isNoAnswer = ($status === 'NOANSWER' || $status === 'TIMED_OUT') ? 1 : 0;
            $isBusy = ($status === 'BUSY') ? 1 : 0;
            $isFailed = ($status === 'FAILED' || $status === 'CANCEL') ? 1 : 0;
            $isCongestion = ($status === 'CONGESTION') ? 1 : 0;
            $statusCache = $isSuspended ? 'TEMP_SUSPENDED' : 'ACTIVE';

            $sql = "INSERT INTO pkg_campaign_failed_analytics
                        (id_campaign, canonical_number, local_date, total_fails,
                         fails_noanswer, fails_busy, fails_failed, fails_congestion,
                         last_hangupcause, last_sip_code, last_attempt_at, status_cache)
                    VALUES
                        (:idCampaign, :canonical, :localDate, 1,
                         :isNoAnswer, :isBusy, :isFailed, :isCongestion,
                         :hangupcause, :sipCode, NOW(), :statusCache)
                    ON DUPLICATE KEY UPDATE
                        total_fails = total_fails + 1,
                        fails_noanswer = fails_noanswer + :isNoAnswer,
                        fails_busy = fails_busy + :isBusy,
                        fails_failed = fails_failed + :isFailed,
                        fails_congestion = fails_congestion + :isCongestion,
                        last_hangupcause = COALESCE(:hangupcause, last_hangupcause),
                        last_sip_code = COALESCE(:sipCode, last_sip_code),
                        last_attempt_at = NOW(),
                        status_cache = IF(:isSuspended = 1, 'TEMP_SUSPENDED', status_cache)";

            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':idCampaign', (int) $idCampaign, PDO::PARAM_INT);
            $command->bindValue(':canonical', $canonicalNumber, PDO::PARAM_STR);
            $command->bindValue(':localDate', $localDate, PDO::PARAM_STR);
            $command->bindValue(':isNoAnswer', $isNoAnswer, PDO::PARAM_INT);
            $command->bindValue(':isBusy', $isBusy, PDO::PARAM_INT);
            $command->bindValue(':isFailed', $isFailed, PDO::PARAM_INT);
            $command->bindValue(':isCongestion', $isCongestion, PDO::PARAM_INT);
            $command->bindValue(':hangupcause', $hangupcause, $hangupcause !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $command->bindValue(':sipCode', $sipCode, $sipCode !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $command->bindValue(':statusCache', $statusCache, PDO::PARAM_STR);
            $command->bindValue(':isSuspended', $isSuspended ? 1 : 0, PDO::PARAM_INT);

            return $command->execute() > 0;
        } catch (Exception $e) {
            Yii::log('Error in recordFailedAttempt: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return false;
        }
    }

    /**
     * Registra un intento fallido de campaña en pkg_cdr_failed si aún no existe.
     *
     * @param int $attemptId
     * @return bool
     */
    public static function ensureFailedCdrForAttempt($attemptId)
    {
        try {
            $sql = "INSERT INTO pkg_cdr_failed
                        (id_user, id_plan, id_trunk, id_prefix, id_server, sessionid, uniqueid,
                         starttime, calledstation, sipiax, src, callerid, terminatecauseid, hangupcause)
                    SELECT
                        a.id_user_owner,
                        u.id_plan,
                        a.id_trunk,
                        (SELECT pr.id FROM pkg_prefix pr
                         WHERE pr.prefix IN (
                             LEFT(a.canonical_number, 1),
                             LEFT(a.canonical_number, 2),
                             LEFT(a.canonical_number, 3),
                             LEFT(a.canonical_number, 4),
                             LEFT(a.canonical_number, 5),
                             LEFT(a.canonical_number, 6),
                             LEFT(a.canonical_number, 7)
                         )
                         ORDER BY LENGTH(pr.prefix) DESC LIMIT 1) AS id_prefix,
                        COALESCE(a.id_server, 1),
                        CONCAT('MC-', a.id_campaign),
                        LEFT(COALESCE(NULLIF(a.uniqueid, ''), CONCAT('mc-att-', a.id)), 30),
                        COALESCE(a.dispatched_at, a.reserved_at),
                        LEFT(a.original_number, 30),
                        0,
                        LEFT(COALESCE(c.name, u.username, 'Campaign'), 40),
                        LEFT(COALESCE(c.name, u.username, 'Campaign'), 40),
                        CASE
                            WHEN a.sip_code = 'AMD' THEN 10
                            WHEN a.status = 'BUSY' THEN 2
                            WHEN a.status IN ('NOANSWER', 'TIMED_OUT') THEN 3
                            WHEN a.status = 'CANCEL' THEN 4
                            WHEN a.status = 'CONGESTION' THEN 5
                            ELSE 6
                        END,
                        COALESCE(a.hangupcause, CASE
                            WHEN a.status = 'BUSY' THEN 17
                            WHEN a.status IN ('NOANSWER', 'TIMED_OUT') THEN 19
                            WHEN a.status = 'CANCEL' THEN 21
                            WHEN a.status = 'CONGESTION' THEN 34
                            ELSE 38
                        END)
                    FROM pkg_campaign_optimizer_attempt a
                    INNER JOIN pkg_user u ON u.id = a.id_user_owner
                    LEFT JOIN pkg_campaign c ON c.id = a.id_campaign
                    LEFT JOIN pkg_cdr_failed cf
                        ON cf.calledstation = LEFT(a.original_number, 30)
                       AND cf.starttime = COALESCE(a.dispatched_at, a.reserved_at)
                    WHERE a.id = :attemptId
                      AND a.status IN ('BUSY', 'NOANSWER', 'FAILED', 'CANCEL', 'CONGESTION', 'TIMED_OUT')
                      AND cf.id IS NULL";

            return Yii::app()->db->createCommand($sql)
                ->bindValue(':attemptId', (int) $attemptId, PDO::PARAM_INT)
                ->execute() > 0;
        } catch (Exception $e) {
            Yii::log('Error in ensureFailedCdrForAttempt: ' . $e->getMessage(), CLogger::LEVEL_WARNING);
            return false;
        }
    }

    /**
     * Sincroniza integralmente intentos de campaña, analítica de fallos,
     * suspensiones temporales y registros de Call Failed (pkg_cdr_failed).
     *
     * @param int $throttleSeconds Segundos mínimos entre sincronizaciones completas (0 = forzar)
     * @return void
     */
    public static function syncAllAnalyticsAndFailedCdr($throttleSeconds = 3)
    {
        try {
            $stampFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mbilling_failed_analytics_sync.stamp';
            if ($throttleSeconds > 0 && @is_file($stampFile)) {
                $mtime = (int) @filemtime($stampFile);
                if ($mtime > 0 && (time() - $mtime) < $throttleSeconds) {
                    return;
                }
            }
            @touch($stampFile);

            $db = Yii::app()->db;

            // 1. Normalizar intentos TIMED_OUT previos hacia NOANSWER (hangupcause 19 / SIP 480)
            $db->createCommand(
                "UPDATE pkg_campaign_optimizer_attempt
                 SET status = 'NOANSWER',
                     hangupcause = COALESCE(hangupcause, 19),
                     sip_code = COALESCE(NULLIF(sip_code, ''), '480'),
                     evidence_source = COALESCE(NULLIF(evidence_source, ''), 'SPOOL_NO_ANSWER')
                 WHERE status = 'TIMED_OUT'"
            )->execute();

            // 2. Backfill de pkg_campaign_report hacia pkg_campaign_optimizer_attempt
            //    para llamadas despachadas hace > 65s que quedaron en status 2 (Sent), 4 (Busy/AMD), 5 (No Answer) o 7 (Congestion)
            $db->createCommand(
                "INSERT INTO pkg_campaign_optimizer_attempt
                    (attempt_uuid, id_campaign, id_phonenumber, id_user_owner,
                     original_number, canonical_number, id_server, uniqueid, id_trunk,
                     status, hangupcause, sip_code, evidence_source,
                     reserved_at, dispatched_at, finalized_at)
                 SELECT
                    UUID(),
                    r.id_campaign,
                    r.id_phonenumber,
                    r.id_user,
                    LEFT(TRIM(p.number), 30),
                    LEFT(TRIM(REPLACE(REPLACE(REPLACE(REPLACE(p.number, '+', ''), '-', ''), ' ', ''), '(', '')), 30),
                    1,
                    CONCAT('mc-rep-', r.id),
                    r.id_trunk,
                    CASE
                        WHEN r.status = 4 THEN 'BUSY'
                        WHEN r.status = 7 THEN 'CONGESTION'
                        ELSE 'NOANSWER'
                    END,
                    CASE
                        WHEN r.status = 4 THEN 17
                        WHEN r.status = 7 THEN 34
                        ELSE 19
                    END,
                    CASE
                        WHEN r.status = 4 THEN '486'
                        WHEN r.status = 7 THEN '503'
                        ELSE '480'
                    END,
                    'pkg_campaign_report_sync',
                    FROM_UNIXTIME(r.unix_timestamp),
                    FROM_UNIXTIME(r.unix_timestamp),
                    FROM_UNIXTIME(r.unix_timestamp + 45)
                 FROM pkg_campaign_report r
                 INNER JOIN pkg_campaign c ON c.id = r.id_campaign
                 INNER JOIN pkg_phonenumber p ON p.id = r.id_phonenumber
                 LEFT JOIN pkg_campaign_optimizer_attempt a
                    ON a.id_campaign = r.id_campaign
                   AND a.id_phonenumber = r.id_phonenumber
                   AND ABS(UNIX_TIMESTAMP(a.reserved_at) - r.unix_timestamp) <= 180
                 WHERE r.status IN (2, 4, 5, 7)
                   AND r.unix_timestamp > 0
                   AND r.unix_timestamp <= UNIX_TIMESTAMP() - 65
                   AND a.id IS NULL
                 LIMIT 2000"
            )->execute();

            // 3. Reconciliar intentos en estado DISPATCHED
            if (class_exists('CampaignResultAdapter')) {
                CampaignResultAdapter::reconcileDispatchedAttempts(1000);
            }

            // 4. Sincronizar y consolidar pkg_campaign_failed_analytics desde pkg_campaign_optimizer_attempt
            $db->createCommand(
                "INSERT INTO pkg_campaign_failed_analytics
                    (id_campaign, canonical_number, local_date, total_fails,
                     fails_noanswer, fails_busy, fails_failed, fails_congestion,
                     last_hangupcause, last_sip_code, last_attempt_at, status_cache)
                 SELECT
                    a.id_campaign,
                    a.canonical_number,
                    DATE(a.reserved_at) AS local_date,
                    COUNT(*) AS total_fails,
                    SUM(IF(a.status IN ('NOANSWER', 'TIMED_OUT'), 1, 0)) AS fails_noanswer,
                    SUM(IF(a.status = 'BUSY', 1, 0)) AS fails_busy,
                    SUM(IF(a.status IN ('FAILED', 'CANCEL'), 1, 0)) AS fails_failed,
                    SUM(IF(a.status = 'CONGESTION', 1, 0)) AS fails_congestion,
                    CAST(SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(a.hangupcause, 19) ORDER BY a.id DESC SEPARATOR ','), ',', 1) AS UNSIGNED) AS last_hangupcause,
                    SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(NULLIF(a.sip_code, ''), '480') ORDER BY a.id DESC SEPARATOR ','), ',', 1) AS last_sip_code,
                    MAX(COALESCE(a.finalized_at, a.dispatched_at, a.reserved_at)) AS last_attempt_at,
                    'ACTIVE' AS status_cache
                 FROM pkg_campaign_optimizer_attempt a
                 WHERE a.status IN ('BUSY', 'NOANSWER', 'FAILED', 'CANCEL', 'CONGESTION', 'TIMED_OUT')
                   AND a.canonical_number != ''
                 GROUP BY a.id_campaign, a.canonical_number, DATE(a.reserved_at)
                 ON DUPLICATE KEY UPDATE
                    total_fails = VALUES(total_fails),
                    fails_noanswer = VALUES(fails_noanswer),
                    fails_busy = VALUES(fails_busy),
                    fails_failed = VALUES(fails_failed),
                    fails_congestion = VALUES(fails_congestion),
                    last_hangupcause = COALESCE(VALUES(last_hangupcause), last_hangupcause),
                    last_sip_code = COALESCE(NULLIF(VALUES(last_sip_code), ''), last_sip_code),
                    last_attempt_at = VALUES(last_attempt_at)"
            )->execute();

            // 5. Evaluar y aplicar suspensiones automáticas en pkg_campaign_optimizer_state
            //    para contactos cuyos fallos de hoy alcancen o superen max_failed_attempts
            $today = date('Y-m-d');
            $endOfDay = $today . ' 23:59:59';
            $db->createCommand(
                "INSERT INTO pkg_campaign_optimizer_state
                    (id_campaign, id_phonenumber, policy_status, suspension_reason, suspension_expires_at, updated_by)
                 SELECT
                    a.id_campaign,
                    a.id_phonenumber,
                    'TEMP_SUSPENDED',
                    CONCAT('MAX_FAILURES_REACHED (', COUNT(*), ' fails)'),
                    :endOfDay,
                    'SYSTEM'
                 FROM pkg_campaign_optimizer_attempt a
                 INNER JOIN pkg_phonenumber p ON p.id = a.id_phonenumber
                 LEFT JOIN pkg_campaign_optimizer_config cfg
                    ON cfg.id_campaign = a.id_campaign AND cfg.activated = 1
                 WHERE a.status IN ('BUSY', 'NOANSWER', 'FAILED', 'CANCEL', 'CONGESTION', 'TIMED_OUT')
                   AND a.reserved_at >= :startOfDay AND a.reserved_at <= :endOfDay2
                 GROUP BY a.id_campaign, a.id_phonenumber, cfg.max_failed_attempts
                 HAVING COUNT(*) >= COALESCE(cfg.max_failed_attempts, 5)
                 ON DUPLICATE KEY UPDATE
                    policy_status = IF(policy_status = 'ADMIN_RESTRICTED', 'ADMIN_RESTRICTED', 'TEMP_SUSPENDED'),
                    suspension_reason = IF(policy_status = 'ADMIN_RESTRICTED', suspension_reason, VALUES(suspension_reason)),
                    suspension_expires_at = IF(policy_status = 'ADMIN_RESTRICTED', suspension_expires_at, VALUES(suspension_expires_at))"
            )->bindValues([
                ':startOfDay' => $today . ' 00:00:00',
                ':endOfDay'   => $endOfDay,
                ':endOfDay2'  => $endOfDay,
            ])->execute();

            // Sincronizar status_cache en pkg_campaign_failed_analytics desde pkg_campaign_optimizer_state
            $db->createCommand(
                "UPDATE pkg_campaign_failed_analytics fa
                 INNER JOIN pkg_phonenumber p ON p.number = fa.canonical_number
                 INNER JOIN pkg_campaign_optimizer_state st
                    ON st.id_campaign = fa.id_campaign AND st.id_phonenumber = p.id
                 SET fa.status_cache = CASE
                    WHEN st.policy_status = 'ADMIN_RESTRICTED' THEN 'ADMIN_RESTRICTED'
                    WHEN st.policy_status = 'TEMP_SUSPENDED' AND (st.suspension_expires_at IS NULL OR st.suspension_expires_at > NOW()) THEN 'TEMP_SUSPENDED'
                    ELSE 'ACTIVE'
                 END"
            )->execute();

            // 6. Backfill hacia pkg_cdr_failed (Call Failed) para intentos fallidos de campaña
            $db->createCommand(
                "INSERT INTO pkg_cdr_failed
                    (id_user, id_plan, id_trunk, id_prefix, id_server, sessionid, uniqueid,
                     starttime, calledstation, sipiax, src, callerid, terminatecauseid, hangupcause)
                 SELECT
                    a.id_user_owner,
                    u.id_plan,
                    a.id_trunk,
                    (SELECT pr.id FROM pkg_prefix pr
                     WHERE pr.prefix IN (
                         LEFT(a.canonical_number, 1),
                         LEFT(a.canonical_number, 2),
                         LEFT(a.canonical_number, 3),
                         LEFT(a.canonical_number, 4),
                         LEFT(a.canonical_number, 5),
                         LEFT(a.canonical_number, 6),
                         LEFT(a.canonical_number, 7)
                     )
                     ORDER BY LENGTH(pr.prefix) DESC LIMIT 1) AS id_prefix,
                    COALESCE(a.id_server, 1),
                    CONCAT('MC-', a.id_campaign),
                    LEFT(COALESCE(NULLIF(a.uniqueid, ''), CONCAT('mc-att-', a.id)), 30),
                    COALESCE(a.dispatched_at, a.reserved_at),
                    LEFT(a.original_number, 30),
                    0,
                    LEFT(COALESCE(c.name, u.username, 'Campaign'), 40),
                    LEFT(COALESCE(c.name, u.username, 'Campaign'), 40),
                    CASE
                        WHEN a.sip_code = 'AMD' THEN 10
                        WHEN a.status = 'BUSY' THEN 2
                        WHEN a.status IN ('NOANSWER', 'TIMED_OUT') THEN 3
                        WHEN a.status = 'CANCEL' THEN 4
                        WHEN a.status = 'CONGESTION' THEN 5
                        ELSE 6
                    END,
                    COALESCE(a.hangupcause, CASE
                        WHEN a.status = 'BUSY' THEN 17
                        WHEN a.status IN ('NOANSWER', 'TIMED_OUT') THEN 19
                        WHEN a.status = 'CANCEL' THEN 21
                        WHEN a.status = 'CONGESTION' THEN 34
                        ELSE 38
                    END)
                 FROM pkg_campaign_optimizer_attempt a
                 INNER JOIN pkg_user u ON u.id = a.id_user_owner
                 LEFT JOIN pkg_campaign c ON c.id = a.id_campaign
                 LEFT JOIN pkg_cdr_failed cf
                    ON cf.calledstation = LEFT(a.original_number, 30)
                   AND cf.starttime = COALESCE(a.dispatched_at, a.reserved_at)
                 WHERE a.status IN ('BUSY', 'NOANSWER', 'FAILED', 'CANCEL', 'CONGESTION', 'TIMED_OUT')
                   AND cf.id IS NULL
                 ORDER BY a.id DESC
                 LIMIT 1000"
            )->execute();
        } catch (Exception $e) {
            Yii::log('Error in syncAllAnalyticsAndFailedCdr: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
        }
    }

    /**
     * Reconstruye de forma determinista el resumen diario de analítica para una campaña
     * a partir de los intentos registrados en pkg_campaign_optimizer_attempt.
     *
     * @param int $idCampaign
     * @param string $localDate
     * @return int Cantidad de registros agregados reconstruidos
     */
    public static function rebuildAnalyticsForDay($idCampaign, $localDate)
    {
        try {
            $start = $localDate . ' 00:00:00';
            $end = $localDate . ' 23:59:59';

            // Limpiar registros del día para la campaña
            Yii::app()->db->createCommand(
                "DELETE FROM pkg_campaign_failed_analytics 
                 WHERE id_campaign = :idCampaign AND local_date = :localDate"
            )->bindValues([
                ':idCampaign' => (int) $idCampaign,
                ':localDate' => $localDate,
            ])->execute();

            $sql = "INSERT INTO pkg_campaign_failed_analytics
                        (id_campaign, canonical_number, local_date, total_fails,
                         fails_noanswer, fails_busy, fails_failed, fails_congestion,
                         last_hangupcause, last_sip_code, last_attempt_at, status_cache)
                    SELECT 
                        id_campaign,
                        canonical_number,
                        :localDate AS local_date,
                        COUNT(*) AS total_fails,
                        SUM(IF(status IN ('NOANSWER', 'TIMED_OUT'), 1, 0)) AS fails_noanswer,
                        SUM(IF(status = 'BUSY', 1, 0)) AS fails_busy,
                        SUM(IF(status IN ('FAILED', 'CANCEL'), 1, 0)) AS fails_failed,
                        SUM(IF(status = 'CONGESTION', 1, 0)) AS fails_congestion,
                        SUBSTRING_INDEX(GROUP_CONCAT(hangupcause ORDER BY id DESC SEPARATOR ','), ',', 1) AS last_hangupcause,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(sip_code, '') ORDER BY id DESC SEPARATOR ','), ',', 1) AS last_sip_code,
                        MAX(COALESCE(finalized_at, dispatched_at, reserved_at)) AS last_attempt_at,
                        'ACTIVE' AS status_cache
                    FROM pkg_campaign_optimizer_attempt
                    WHERE id_campaign = :idCampaign
                      AND status IN ('BUSY', 'NOANSWER', 'FAILED', 'CANCEL', 'CONGESTION', 'TIMED_OUT')
                      AND reserved_at >= :start AND reserved_at <= :end
                    GROUP BY canonical_number";

            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':idCampaign', (int) $idCampaign, PDO::PARAM_INT);
            $command->bindValue(':localDate', $localDate, PDO::PARAM_STR);
            $command->bindValue(':start', $start, PDO::PARAM_STR);
            $command->bindValue(':end', $end, PDO::PARAM_STR);

            return $command->execute();
        } catch (Exception $e) {
            Yii::log('Error rebuilding analytics: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return 0;
        }
    }

    /**
     * Calcula métricas clave (KPIs) para las tarjetas de resumen en la cabecera de la UI.
     *
     * @param int|null $idCampaign
     * @param string|null $localDate
     * @return array
     */
    public static function getSummaryKpis($idCampaign = null, $localDate = null)
    {
        $localDate = $localDate ?: date('Y-m-d');
        $params = [':localDate' => $localDate];
        $campaignFilter = '';

        if (!empty($idCampaign) && (int) $idCampaign > 0) {
            $campaignFilter = ' AND id_campaign = :idCampaign';
            $params[':idCampaign'] = (int) $idCampaign;
        }

        $sql = "SELECT 
                    COALESCE(SUM(total_fails), 0) AS total_fails,
                    COALESCE(SUM(fails_noanswer), 0) AS fails_noanswer,
                    COALESCE(SUM(fails_busy), 0) AS fails_busy,
                    COALESCE(SUM(fails_failed), 0) AS fails_failed,
                    COALESCE(SUM(fails_congestion), 0) AS fails_congestion,
                    COUNT(DISTINCT canonical_number) AS total_unique_failed_numbers
                FROM pkg_campaign_failed_analytics
                WHERE local_date = :localDate {$campaignFilter}";

        $row = Yii::app()->db->createCommand($sql)->bindValues($params)->queryRow();

        // Si en la fecha actual no hay registros pero existen registros históricos en la grilla,
        // mostrar el acumulado general para que los KPIs coincidan con la tabla visible
        if ((int) ($row['total_fails'] ?? 0) === 0) {
            $allParams = [];
            $allFilter = '1=1';
            if (!empty($idCampaign) && (int) $idCampaign > 0) {
                $allFilter .= ' AND id_campaign = :idCampaign';
                $allParams[':idCampaign'] = (int) $idCampaign;
            }
            $rowAll = Yii::app()->db->createCommand(
                "SELECT 
                    COALESCE(SUM(total_fails), 0) AS total_fails,
                    COALESCE(SUM(fails_noanswer), 0) AS fails_noanswer,
                    COALESCE(SUM(fails_busy), 0) AS fails_busy,
                    COALESCE(SUM(fails_failed), 0) AS fails_failed,
                    COALESCE(SUM(fails_congestion), 0) AS fails_congestion,
                    COUNT(DISTINCT canonical_number) AS total_unique_failed_numbers
                 FROM pkg_campaign_failed_analytics
                 WHERE {$allFilter}"
            )->bindValues($allParams)->queryRow();
            if ($rowAll && (int) ($rowAll['total_fails'] ?? 0) > 0) {
                $row = $rowAll;
            }
        }

        // Contar contactos suspendidos temporalmente
        $suspendedSql = "SELECT COUNT(*) FROM pkg_campaign_optimizer_state
                         WHERE policy_status = 'TEMP_SUSPENDED'
                           AND (suspension_expires_at IS NULL OR suspension_expires_at > NOW())";
        if (!empty($idCampaign) && (int) $idCampaign > 0) {
            $suspendedSql .= " AND id_campaign = " . (int) $idCampaign;
        }
        $suspendedCount = (int) Yii::app()->db->createCommand($suspendedSql)->queryScalar();

        // Contar contactos restringidos permanentemente
        $restrictedSql = "SELECT COUNT(*) FROM pkg_campaign_optimizer_state
                          WHERE policy_status = 'ADMIN_RESTRICTED'";
        if (!empty($idCampaign) && (int) $idCampaign > 0) {
            $restrictedSql .= " AND id_campaign = " . (int) $idCampaign;
        }
        $restrictedCount = (int) Yii::app()->db->createCommand($restrictedSql)->queryScalar();

        $totalFails = (int) ($row['total_fails'] ?? 0);
        $uniqueFailed = (int) ($row['total_unique_failed_numbers'] ?? 0);
        $pctNoAnswer = $totalFails > 0 ? round(($row['fails_noanswer'] / $totalFails) * 100, 1) : 0;
        $pctBusy = $totalFails > 0 ? round(($row['fails_busy'] / $totalFails) * 100, 1) : 0;
        $pctFailed = $totalFails > 0 ? round(($row['fails_failed'] / $totalFails) * 100, 1) : 0;
        $pctCongestion = $totalFails > 0 ? round(($row['fails_congestion'] / $totalFails) * 100, 1) : 0;

        return [
            'total_fails' => $totalFails,
            'total_failures_today' => $totalFails,
            'unique_failed_numbers' => $uniqueFailed,
            'unique_numbers_failed' => $uniqueFailed,
            'distinct_contacts_affected' => $uniqueFailed,
            'fails_noanswer' => (int) ($row['fails_noanswer'] ?? 0),
            'fails_busy' => (int) ($row['fails_busy'] ?? 0),
            'fails_failed' => (int) ($row['fails_failed'] ?? 0),
            'fails_congestion' => (int) ($row['fails_congestion'] ?? 0),
            'pct_noanswer' => $pctNoAnswer,
            'pct_busy' => $pctBusy,
            'pct_failed' => $pctFailed,
            'pct_congestion' => $pctCongestion,
            'suspended_today' => $suspendedCount,
            'temp_suspended' => $suspendedCount,
            'temporary_suspensions' => $suspendedCount,
            'admin_restricted' => $restrictedCount,
            'permanent_restrictions' => $restrictedCount,
            'local_date' => $localDate,
        ];
    }
}
