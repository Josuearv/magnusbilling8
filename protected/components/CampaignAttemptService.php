<?php
/**
 * Servicio para gestión y ciclo de vida de intentos de campaña.
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
     * Reserva un nuevo intento en pkg_campaign_optimizer_attempt.
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
            $uuid = self::generateUuidV4();
            $canonical = self::normalizeCanonicalNumber($originalNumber);

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
     * Marca un intento como despachado al spooler de Asterisk.
     *
     * @param int $attemptId
     * @param string|null $uniqueid
     * @param int|null $idServer
     * @return bool
     */
    public static function markDispatched($attemptId, $uniqueid = null, $idServer = null)
    {
        try {
            $sql = "UPDATE pkg_campaign_optimizer_attempt
                    SET status = 'DISPATCHED',
                        dispatched_at = :now,
                        uniqueid = COALESCE(:uniqueid, uniqueid),
                        id_server = COALESCE(:idServer, id_server)
                    WHERE id = :id";
            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':id', (int) $attemptId, PDO::PARAM_INT);
            $command->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
            $command->bindValue(':uniqueid', $uniqueid, $uniqueid !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $command->bindValue(':idServer', $idServer, $idServer !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
            return $command->execute() > 0;
        } catch (Exception $e) {
            Yii::log('Error in markDispatched: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return false;
        }
    }

    /**
     * Marca un intento como cancelado antes de publicarse.
     *
     * @param int $attemptId
     * @param string|null $reason
     * @return bool
     */
    public static function markCancelled($attemptId, $reason = null)
    {
        try {
            $sql = "UPDATE pkg_campaign_optimizer_attempt
                    SET status = 'CANCEL',
                        evidence_source = :reason,
                        finalized_at = :now
                    WHERE id = :id";
            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':id', (int) $attemptId, PDO::PARAM_INT);
            $command->bindValue(':reason', $reason, $reason !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $command->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
            return $command->execute() > 0;
        } catch (Exception $e) {
            Yii::log('Error in markCancelled: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return false;
        }
    }

    /**
     * Marca un intento como contestado (invocado por AGI o conciliador).
     *
     * @param int $attemptId
     * @param string|null $uniqueid
     * @return bool
     */
    public static function markAnswered($attemptId, $uniqueid = null)
    {
        try {
            $sql = "UPDATE pkg_campaign_optimizer_attempt
                    SET status = 'ANSWERED',
                        uniqueid = COALESCE(:uniqueid, uniqueid),
                        finalized_at = :now
                    WHERE id = :id";
            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':id', (int) $attemptId, PDO::PARAM_INT);
            $command->bindValue(':uniqueid', $uniqueid, $uniqueid !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $command->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
            return $command->execute() > 0;
        } catch (Exception $e) {
            Yii::log('Error in markAnswered: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return false;
        }
    }

    /**
     * Marca un intento con su estado terminal definitivo.
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
            $command->bindValue(':now', date('Y-m-d H:i:s'), PDO::PARAM_STR);
            return $command->execute() > 0;
        } catch (Exception $e) {
            Yii::log('Error in markFinalized: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return false;
        }
    }
}
