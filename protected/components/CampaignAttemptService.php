<?php
/**
 * Servicio para gestión y ciclo de vida de intentos de campaña y coordinación transaccional.
 *
 * MagnusBilling 8 - Campaign Optimizer
 */
class CampaignAttemptService
{
    /**
     * Genera un UUID versión 4 compatible con RFC 4122.
     *
     * @return string
     */
    public static function generateUuidV4()
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // versión 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variante RFC 4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Normaliza un número telefónico a formato canónico (solo dígitos).
     *
     * @param string $number
     * @return string
     */
    public static function normalizeCanonicalNumber($number)
    {
        $canonical = preg_replace('/[^0-9]/', '', (string) $number);
        return !empty($canonical) ? $canonical : trim((string) $number);
    }

    /**
     * Reserva un nuevo intento en pkg_campaign_optimizer_attempt previa comprobación
     * de elegibilidad y cupos diarios compartidos.
     *
     * @param int $idCampaign
     * @param int $idPhonenumber
     * @param int $idUserOwner
     * @param string $originalNumber
     * @param int|null $idTrunk
     * @param int|null $audioVersionId
     * @return CampaignOptimizerAttempt|null
     */
    public static function reserveAttempt($idCampaign, $idPhonenumber, $idUserOwner, $originalNumber, $idTrunk = null, $audioVersionId = null)
    {
        try {
            $localDate = date('Y-m-d');
            $canonical = self::normalizeCanonicalNumber($originalNumber);

            // 1. Evaluar elegibilidad mediante CampaignRestrictionService
            $eligibility = CampaignRestrictionService::checkEligibility(
                (int) $idCampaign,
                (int) $idPhonenumber,
                (int) $idUserOwner,
                (string) $originalNumber,
                $localDate
            );

            // Si no es elegible y la campaña está en modo ACTIVE o ENFORCING, se rechaza la llamada
            if (!$eligibility['eligible'] && ($eligibility['mode'] === 'ACTIVE' || $eligibility['mode'] === 'ENFORCING')) {
                return null;
            }

            // 2. Reservar cupo diario
            $maxAttempts = 6;
            $config = CampaignOptimizerConfig::model()->find('id_campaign = :c AND activated = 1', [':c' => (int) $idCampaign]);
            if ($config) {
                $maxAttempts = (int) $config->max_daily_attempts;
            }

            CampaignRestrictionService::reserveQuota((int) $idUserOwner, $canonical, $localDate, $maxAttempts);

            // 3. Crear registro de intento
            $uuid = self::generateUuidV4();
            $attempt = new CampaignOptimizerAttempt();
            $attempt->attempt_uuid = $uuid;
            $attempt->id_campaign = (int) $idCampaign;
            $attempt->id_phonenumber = (int) $idPhonenumber;
            $attempt->id_user_owner = (int) $idUserOwner;
            $attempt->original_number = (string) $originalNumber;
            $attempt->canonical_number = $canonical;
            $attempt->id_trunk = $idTrunk !== null ? (int) $idTrunk : null;
            $attempt->audio_version_id = $audioVersionId !== null ? (int) $audioVersionId : null;
            $attempt->status = 'RESERVED';
            $attempt->reserved_at = date('Y-m-d H:i:s');

            if (!$eligibility['eligible'] && $eligibility['mode'] === 'OBSERVATION') {
                $attempt->evidence_source = 'OBSERVATION_FLAG: ' . $eligibility['reason'];
            }

            if ($attempt->save()) {
                return $attempt;
            }

            Yii::log('Failed to save CampaignOptimizerAttempt: ' . json_encode($attempt->getErrors()), CLogger::LEVEL_WARNING);
            return null;
        } catch (Exception $e) {
            Yii::log('Exception in reserveAttempt: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return null;
        }
    }

    /**
     * Marca un intento como despachado al spooler de Asterisk y consolida el cupo.
     *
     * @param int $attemptId
     * @param string|null $uniqueid
     * @param int|null $idServer
     * @return bool
     */
    public static function markDispatched($attemptId, $uniqueid = null, $idServer = null)
    {
        try {
            $now = date('Y-m-d H:i:s');
            $attempt = CampaignOptimizerAttempt::model()->findByPk((int) $attemptId);
            if ($attempt) {
                $localDate = date('Y-m-d', strtotime($attempt->reserved_at));
                CampaignRestrictionService::commitDispatchedQuota(
                    (int) $attempt->id_user_owner,
                    $attempt->canonical_number,
                    $localDate
                );
            }

            $sql = "UPDATE pkg_campaign_optimizer_attempt
                    SET status = 'DISPATCHED',
                        dispatched_at = :now,
                        uniqueid = COALESCE(:uniqueid, uniqueid),
                        id_server = COALESCE(:idServer, id_server)
                    WHERE id = :id";
            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':id', (int) $attemptId, PDO::PARAM_INT);
            $command->bindValue(':now', $now, PDO::PARAM_STR);
            $command->bindValue(':uniqueid', $uniqueid, $uniqueid !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $command->bindValue(':idServer', $idServer, $idServer !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            return $command->execute() > 0;
        } catch (Exception $e) {
            Yii::log('Error in markDispatched: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return false;
        }
    }

    /**
     * Marca un intento como cancelado antes de publicarse y libera el cupo reservado.
     *
     * @param int $attemptId
     * @param string|null $reason
     * @return bool
     */
    public static function markCancelled($attemptId, $reason = null)
    {
        try {
            $now = date('Y-m-d H:i:s');
            $attempt = CampaignOptimizerAttempt::model()->findByPk((int) $attemptId);
            if ($attempt) {
                $localDate = date('Y-m-d', strtotime($attempt->reserved_at));
                CampaignRestrictionService::releaseReservedQuota(
                    (int) $attempt->id_user_owner,
                    $attempt->canonical_number,
                    $localDate
                );
            }

            $sql = "UPDATE pkg_campaign_optimizer_attempt
                    SET status = 'CANCEL',
                        evidence_source = :reason,
                        finalized_at = :now
                    WHERE id = :id";
            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':id', (int) $attemptId, PDO::PARAM_INT);
            $command->bindValue(':reason', $reason, $reason !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $command->bindValue(':now', $now, PDO::PARAM_STR);
            return $command->execute() > 0;
        } catch (Exception $e) {
            Yii::log('Error in markCancelled: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return false;
        }
    }

    /**
     * Marca un intento como contestado y actualiza contadores agregados.
     *
     * @param int $attemptId
     * @param string|null $uniqueid
     * @return bool
     */
    public static function markAnswered($attemptId, $uniqueid = null)
    {
        return self::markFinalized($attemptId, 'ANSWERED', 16, '200', 'AGI_MASSIVE_CALL', $uniqueid);
    }

    /**
     * Marca un intento con su estado terminal definitivo y actualiza cuotas y analítica.
     *
     * @param int $attemptId
     * @param string $status
     * @param int|null $hangupcause
     * @param string|null $sipCode
     * @param string|null $evidenceSource
     * @param string|null $uniqueid
     * @return bool
     */
    public static function markFinalized($attemptId, $status, $hangupcause = null, $sipCode = null, $evidenceSource = null, $uniqueid = null)
    {
        try {
            $now = date('Y-m-d H:i:s');
            $normalizedStatus = ($status === 'TIMED_OUT') ? 'NOANSWER' : $status;
            if ($status === 'TIMED_OUT') {
                $hangupcause = $hangupcause ?? 19;
                $sipCode = $sipCode ?? '480';
            }

            $sql = "UPDATE pkg_campaign_optimizer_attempt
                    SET status = :status,
                        hangupcause = COALESCE(:hangupcause, hangupcause),
                        sip_code = COALESCE(:sipCode, sip_code),
                        evidence_source = COALESCE(:evidenceSource, evidence_source),
                        uniqueid = COALESCE(:uniqueid, uniqueid),
                        finalized_at = :now
                    WHERE id = :id";
            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':id', (int) $attemptId, PDO::PARAM_INT);
            $command->bindValue(':status', $normalizedStatus, PDO::PARAM_STR);
            $command->bindValue(':hangupcause', $hangupcause, $hangupcause !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $command->bindValue(':sipCode', $sipCode, $sipCode !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $command->bindValue(':evidenceSource', $evidenceSource, $evidenceSource !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $command->bindValue(':uniqueid', $uniqueid, $uniqueid !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $command->bindValue(':now', $now, PDO::PARAM_STR);
            $updated = $command->execute() > 0;

            $attempt = CampaignOptimizerAttempt::model()->findByPk((int) $attemptId);
            if ($attempt) {
                $localDate = date('Y-m-d', strtotime($attempt->reserved_at));
                CampaignRestrictionService::recordResultQuota(
                    (int) $attempt->id_user_owner,
                    $attempt->canonical_number,
                    $localDate,
                    $normalizedStatus
                );

                // Si es un fallo terminal, evaluar suspensión y registrar en pkg_campaign_failed_analytics y pkg_cdr_failed
                if (in_array($normalizedStatus, ['BUSY', 'NOANSWER', 'FAILED', 'CONGESTION', 'CANCEL'], true)) {
                    $isSuspended = self::checkAndApplyFailureSuspension($attempt);
                    if (class_exists('CampaignAnalyticsService')) {
                        CampaignAnalyticsService::recordFailedAttempt(
                            (int) $attempt->id_campaign,
                            $attempt->canonical_number,
                            $localDate,
                            $normalizedStatus,
                            $hangupcause,
                            $sipCode,
                            $isSuspended
                        );
                        CampaignAnalyticsService::ensureFailedCdrForAttempt((int) $attempt->id);
                    }
                }
            }

            return $updated;
        } catch (Exception $e) {
            Yii::log('Error in markFinalized: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return false;
        }
    }

    /**
     * Evalúa si un contacto ha alcanzado el límite de fallos en el día local para aplicar suspensión.
     *
     * @param CampaignOptimizerAttempt $attempt
     * @return bool True si se aplicó suspensión, false de lo contrario
     */
    private static function checkAndApplyFailureSuspension($attempt)
    {
        try {
            $localDate = date('Y-m-d', strtotime($attempt->reserved_at));
            $config = CampaignOptimizerConfig::model()->find('id_campaign = :c AND activated = 1', [':c' => (int) $attempt->id_campaign]);
            $maxFailed = $config ? (int) $config->max_failed_attempts : 5;
            $timezone = $config ? $config->timezone : 'America/Bogota';

            // Contar fallos de hoy para este contacto en esta campaña
            $failCount = Yii::app()->db->createCommand(
                "SELECT COUNT(*) FROM pkg_campaign_optimizer_attempt
                 WHERE id_campaign = :c AND id_phonenumber = :p
                   AND status IN ('BUSY', 'NOANSWER', 'FAILED', 'CONGESTION', 'CANCEL', 'TIMED_OUT')
                   AND reserved_at >= :startDay AND reserved_at <= :endDay"
            )->bindValues([
                ':c' => (int) $attempt->id_campaign,
                ':p' => (int) $attempt->id_phonenumber,
                ':startDay' => $localDate . ' 00:00:00',
                ':endDay'   => $localDate . ' 23:59:59',
            ])->queryScalar();

            if ($failCount !== false && (int) $failCount >= $maxFailed) {
                return (bool) CampaignRestrictionService::applyTemporarySuspension(
                    (int) $attempt->id_campaign,
                    (int) $attempt->id_phonenumber,
                    'MAX_FAILURES_REACHED (' . (int) $failCount . ' fails)',
                    $timezone
                );
            }
            return false;
        } catch (Exception $e) {
            Yii::log('Error checking failure suspension: ' . $e->getMessage(), CLogger::LEVEL_WARNING);
            return false;
        }
    }

    /**
     * Reprocesses used (status = 2) phone numbers back to active (status = 1, try = 0)
     * for active campaigns with auto_reprocess = 1 when no eligible status = 1 numbers remain.
     *
     * Respects active Campaign Optimizer policies (TEMP_SUSPENDED / ADMIN_RESTRICTED and daily quota)
     * without using unsafe Yii `t.` table aliases in UPDATE statements.
     *
     * @param int|null $onlyCampaignId Optional campaign ID filter
     * @return int Total phone number rows reactivated
     */
    public static function reprocessEligibleCampaigns($onlyCampaignId = null)
    {
        $totalReprocessed = 0;

        try {
            $db = Yii::app()->db;
            $campSql = "SELECT id, id_user FROM pkg_campaign WHERE status = 1 AND auto_reprocess = 1";
            $campParams = [];
            if ($onlyCampaignId !== null && (int) $onlyCampaignId > 0) {
                $campSql .= " AND id = :onlyCamp";
                $campParams[':onlyCamp'] = (int) $onlyCampaignId;
            }
            $campCmd = $db->createCommand($campSql);
            foreach ($campParams as $k => $v) {
                $campCmd->bindValue($k, $v, PDO::PARAM_INT);
            }
            $campaigns = $campCmd->queryAll();

            if (empty($campaigns)) {
                return 0;
            }

            foreach ($campaigns as $camp) {
                $campaignId = (int) $camp['id'];
                $userId     = (int) $camp['id_user'];

                $pbRows = $db->createCommand(
                    "SELECT id_phonebook FROM pkg_campaign_phonebook WHERE id_campaign = :cid"
                )->bindValue(':cid', $campaignId, PDO::PARAM_INT)->queryColumn();

                $pbIds = array_values(array_unique(array_filter(array_map('intval', (array) $pbRows), function ($v) {
                    return $v > 0;
                })));
                if (empty($pbIds)) {
                    continue;
                }
                $pbListSql = implode(',', $pbIds);

                $optConfig = null;
                if (class_exists('CampaignOptimizerConfig')) {
                    $optConfig = CampaignOptimizerConfig::model()->find(
                        'id_campaign = :c AND activated = 1',
                        [':c' => $campaignId]
                    );
                }

                $enforceOptimizer = ($optConfig && in_array($optConfig->mode, ['ACTIVE', 'ENFORCING'], true));

                if (! $enforceOptimizer) {
                    $hasPending = $db->createCommand(
                        "SELECT 1 FROM pkg_phonenumber WHERE status = 1 AND id_phonebook IN ({$pbListSql}) LIMIT 1"
                    )->queryScalar();
                    if ($hasPending) {
                        continue;
                    }

                    $affected = (int) $db->createCommand(
                        "UPDATE pkg_phonenumber SET status = 1, try = 0 WHERE status = 2 AND id_phonebook IN ({$pbListSql})"
                    )->execute();
                    $totalReprocessed += $affected;
                    continue;
                }

                // Sync timezone for local_date comparison matching CampaignAttemptService
                if (class_exists('LoadConfig')) {
                    LoadConfig::syncSystemTimezone($optConfig->timezone ?: null);
                } elseif (! empty($optConfig->timezone) && @in_array($optConfig->timezone, timezone_identifiers_list(), true)) {
                    @date_default_timezone_set($optConfig->timezone);
                }
                $localDate = date('Y-m-d');
                $maxDaily  = max(1, (int) $optConfig->max_daily_attempts);

                $exclusionSql = "
                    AND NOT EXISTS (
                        SELECT 1 FROM pkg_campaign_optimizer_state s
                        WHERE s.id_campaign = :cid
                          AND s.id_phonenumber = p.id
                          AND s.policy_status != 'ELIGIBLE'
                          AND (s.suspension_expires_at IS NULL OR s.suspension_expires_at > NOW())
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM pkg_campaign_optimizer_daily d
                        WHERE d.id_user_owner = :uid
                          AND d.local_date = :localDate
                          AND d.canonical_number = REPLACE(REPLACE(REPLACE(REPLACE(p.number, '+', ''), '-', ''), ' ', ''), '.', '')
                          AND (d.reserved_count + d.initiated_count) >= :maxDaily
                    )";

                // Only skip reprocess if there is at least one TRULY dialable status=1 number
                $pendingCmd = $db->createCommand(
                    "SELECT 1 FROM pkg_phonenumber p
                     WHERE p.status = 1
                       AND p.id_phonebook IN ({$pbListSql})
                       {$exclusionSql}
                     LIMIT 1"
                );
                $pendingCmd->bindValue(':cid', $campaignId, PDO::PARAM_INT);
                $pendingCmd->bindValue(':uid', $userId, PDO::PARAM_INT);
                $pendingCmd->bindValue(':localDate', $localDate, PDO::PARAM_STR);
                $pendingCmd->bindValue(':maxDaily', $maxDaily, PDO::PARAM_INT);
                if ($pendingCmd->queryScalar()) {
                    continue;
                }

                $updateCmd = $db->createCommand(
                    "UPDATE pkg_phonenumber p
                     SET p.status = 1, p.try = 0
                     WHERE p.status = 2
                       AND p.id_phonebook IN ({$pbListSql})
                       {$exclusionSql}"
                );
                $updateCmd->bindValue(':cid', $campaignId, PDO::PARAM_INT);
                $updateCmd->bindValue(':uid', $userId, PDO::PARAM_INT);
                $updateCmd->bindValue(':localDate', $localDate, PDO::PARAM_STR);
                $updateCmd->bindValue(':maxDaily', $maxDaily, PDO::PARAM_INT);
                $affected = (int) $updateCmd->execute();
                $totalReprocessed += $affected;
            }
        } catch (Exception $e) {
            Yii::log('Error in reprocessEligibleCampaigns: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
        }

        return $totalReprocessed;
    }
}
