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

            // Si no es elegible y la campaña está en modo ACTIVE, se rechaza la llamada
            if (!$eligibility['eligible'] && $eligibility['mode'] === 'ACTIVE') {
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
            $attempt = CampaignOptimizerAttempt::model()->findByPk((int) $attemptId);
            if ($attempt) {
                $localDate = date('Y-m-d', strtotime($attempt->reserved_at));
                CampaignRestrictionService::recordResultQuota(
                    (int) $attempt->id_user_owner,
                    $attempt->canonical_number,
                    $localDate,
                    $status
                );

                // Si es un fallo terminal, evaluar suspensión y registrar en pkg_campaign_failed_analytics
                if (in_array($status, ['BUSY', 'NOANSWER', 'FAILED', 'CONGESTION'], true)) {
                    $isSuspended = self::checkAndApplyFailureSuspension($attempt);
                    if (class_exists('CampaignAnalyticsService')) {
                        CampaignAnalyticsService::recordFailedAttempt(
                            (int) $attempt->id_campaign,
                            $attempt->canonical_number,
                            $localDate,
                            $status,
                            $hangupcause,
                            $sipCode,
                            $isSuspended
                        );
                    }
                }
            }

            $sql = "UPDATE pkg_campaign_optimizer_attempt
                    SET status = :status,
                        hangupcause = :hangupcause,
                        sip_code = :sipCode,
                        evidence_source = :evidenceSource,
                        uniqueid = COALESCE(:uniqueid, uniqueid),
                        finalized_at = :now
                    WHERE id = :id";
            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':id', (int) $attemptId, PDO::PARAM_INT);
            $command->bindValue(':status', $status, PDO::PARAM_STR);
            $command->bindValue(':hangupcause', $hangupcause, $hangupcause !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $command->bindValue(':sipCode', $sipCode, $sipCode !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $command->bindValue(':evidenceSource', $evidenceSource, $evidenceSource !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $command->bindValue(':uniqueid', $uniqueid, $uniqueid !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $command->bindValue(':now', $now, PDO::PARAM_STR);
            return $command->execute() > 0;
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
            $timezone = $config ? $config->timezone : 'America/Mexico_City';

            // Contar fallos de hoy para este contacto en esta campaña
            $failCount = Yii::app()->db->createCommand(
                "SELECT COUNT(*) FROM pkg_campaign_optimizer_attempt
                 WHERE id_campaign = :c AND id_phonenumber = :p
                   AND status IN ('BUSY', 'NOANSWER', 'FAILED', 'CONGESTION')
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
}
