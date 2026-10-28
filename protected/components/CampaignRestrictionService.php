<?php
/**
 * Servicio para evaluación de elegibilidad, restricciones y gestión de cupos diarios compartidos.
 *
 * MagnusBilling 8 - Campaign Optimizer
 */
class CampaignRestrictionService
{
    /**
     * Evalúa si un contacto es elegible para ser marcado por la campaña.
     *
     * @param int $idCampaign
     * @param int $idPhonenumber
     * @param int $idUserOwner
     * @param string $destination
     * @param string|null $localDate
     * @return array ['eligible' => bool, 'reason' => string|null, 'mode' => string]
     */
    public static function checkEligibility($idCampaign, $idPhonenumber, $idUserOwner, $destination, $localDate = null)
    {
        $localDate = $localDate ?: date('Y-m-d');
        $canonical = CampaignAttemptService::normalizeCanonicalNumber($destination);

        // 1. Obtener configuración del optimizador para la campaña
        $config = CampaignOptimizerConfig::model()->find('id_campaign = :c AND activated = 1', [':c' => (int) $idCampaign]);
        if (!$config) {
            return ['eligible' => true, 'reason' => null, 'mode' => 'NONE'];
        }

        $mode = $config->mode; // 'OBSERVATION' o 'ACTIVE'
        $maxAttempts = (int) $config->max_daily_attempts;

        // 2. Verificar estado de política de la campaña (pkg_campaign_optimizer_state)
        $state = CampaignOptimizerState::model()->find(
            'id_campaign = :c AND id_phonenumber = :p',
            [':c' => (int) $idCampaign, ':p' => (int) $idPhonenumber]
        );

        if ($state) {
            if ($state->policy_status === 'ADMIN_RESTRICTED') {
                return ['eligible' => false, 'reason' => 'ADMIN_RESTRICTED', 'mode' => $mode];
            }
            if ($state->policy_status === 'TEMP_SUSPENDED') {
                if ($state->suspension_expires_at && strtotime($state->suspension_expires_at) > time()) {
                    return ['eligible' => false, 'reason' => 'TEMP_SUSPENDED', 'mode' => $mode];
                } else {
                    // La suspensión expiró, restaurar elegibilidad
                    $state->policy_status = 'ELIGIBLE';
                    $state->save();
                }
            }
        }

        // 3. Verificar límite de cupo diario compartido (pkg_campaign_optimizer_daily)
        $daily = Yii::app()->db->createCommand(
            "SELECT (reserved_count + initiated_count) AS total_today
             FROM pkg_campaign_optimizer_daily
             WHERE id_user_owner = :owner AND canonical_number = :canon AND local_date = :date"
        )->bindValues([
            ':owner' => (int) $idUserOwner,
            ':canon' => $canonical,
            ':date'  => $localDate,
        ])->queryScalar();

        if ($daily !== false && (int) $daily >= $maxAttempts) {
            return ['eligible' => false, 'reason' => 'DAILY_LIMIT_REACHED', 'mode' => $mode];
        }

        // 4. Verificar restricciones de la campaña si restrict_phone está habilitado
        $campaign = Campaign::model()->findByPk((int) $idCampaign);
        if ($campaign && $campaign->restrict_phone == 1) {
            $isRestrictedCampaign = Yii::app()->db->createCommand(
                "SELECT COUNT(*) FROM pkg_campaign_restrict_phone WHERE number = :dest"
            )->bindValue(':dest', $destination, PDO::PARAM_STR)->queryScalar();

            if ((int) $isRestrictedCampaign > 0) {
                return ['eligible' => false, 'reason' => 'CAMPAIGN_RESTRICT_PHONE', 'mode' => $mode];
            }
        }

        // 5. Verificar restricciones globales jerárquicas en pkg_restrict_phone
        // Reconoce dirección saliente (2 o 3), usuario propietario o admin (id_user = 1),
        // y coincidencia por longitud de prefijo descendente.
        $restrictPhoneSql = "SELECT id FROM pkg_restrict_phone
                             WHERE direction IN (2, 3)
                               AND (id_user = :owner OR id_user = 1)
                               AND number = SUBSTRING(:dest, 1, LENGTH(number))
                             ORDER BY LENGTH(number) DESC
                             LIMIT 1";
        $restrictedPhoneId = Yii::app()->db->createCommand($restrictPhoneSql)
            ->bindValue(':owner', (int) $idUserOwner, PDO::PARAM_INT)
            ->bindValue(':dest', $destination, PDO::PARAM_STR)
            ->queryScalar();

        if ($restrictedPhoneId) {
            return ['eligible' => false, 'reason' => 'GLOBAL_RESTRICT_PHONE', 'mode' => $mode];
        }

        return ['eligible' => true, 'reason' => null, 'mode' => $mode];
    }

    /**
     * Reserva atómicamente un cupo diario en pkg_campaign_optimizer_daily.
     *
     * @param int $idUserOwner
     * @param string $canonicalNumber
     * @param string $localDate
     * @param int $maxAttempts
     * @return bool
     */
    public static function reserveQuota($idUserOwner, $canonicalNumber, $localDate, $maxAttempts = 6)
    {
        try {
            $sql = "INSERT INTO pkg_campaign_optimizer_daily 
                        (id_user_owner, canonical_number, local_date, reserved_count, initiated_count, failed_count, answered_count)
                    VALUES 
                        (:owner, :canon, :date, 1, 0, 0, 0)
                    ON DUPLICATE KEY UPDATE 
                        reserved_count = IF((reserved_count + initiated_count) < :maxAttempts, reserved_count + 1, reserved_count)";

            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':owner', (int) $idUserOwner, PDO::PARAM_INT);
            $command->bindValue(':canon', $canonicalNumber, PDO::PARAM_STR);
            $command->bindValue(':date', $localDate, PDO::PARAM_STR);
            $command->bindValue(':maxAttempts', (int) $maxAttempts, PDO::PARAM_INT);
            $affected = $command->execute();

            return $affected > 0;
        } catch (Exception $e) {
            Yii::log('Error in reserveQuota: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return false;
        }
    }

    /**
     * Consolida la reserva a intento iniciado una vez publicado el .call.
     *
     * @param int $idUserOwner
     * @param string $canonicalNumber
     * @param string $localDate
     * @return bool
     */
    public static function commitDispatchedQuota($idUserOwner, $canonicalNumber, $localDate)
    {
        try {
            $sql = "UPDATE pkg_campaign_optimizer_daily 
                    SET reserved_count = GREATEST(0, reserved_count - 1),
                        initiated_count = initiated_count + 1
                    WHERE id_user_owner = :owner AND canonical_number = :canon AND local_date = :date";

            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':owner', (int) $idUserOwner, PDO::PARAM_INT);
            $command->bindValue(':canon', $canonicalNumber, PDO::PARAM_STR);
            $command->bindValue(':date', $localDate, PDO::PARAM_STR);
            return $command->execute() > 0;
        } catch (Exception $e) {
            Yii::log('Error in commitDispatchedQuota: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return false;
        }
    }

    /**
     * Libera una reserva si falló la publicación del archivo .call.
     *
     * @param int $idUserOwner
     * @param string $canonicalNumber
     * @param string $localDate
     * @return bool
     */
    public static function releaseReservedQuota($idUserOwner, $canonicalNumber, $localDate)
    {
        try {
            $sql = "UPDATE pkg_campaign_optimizer_daily 
                    SET reserved_count = GREATEST(0, reserved_count - 1)
                    WHERE id_user_owner = :owner AND canonical_number = :canon AND local_date = :date";

            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':owner', (int) $idUserOwner, PDO::PARAM_INT);
            $command->bindValue(':canon', $canonicalNumber, PDO::PARAM_STR);
            $command->bindValue(':date', $localDate, PDO::PARAM_STR);
            return $command->execute() > 0;
        } catch (Exception $e) {
            Yii::log('Error in releaseReservedQuota: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return false;
        }
    }

    /**
     * Registra el resultado terminal de la llamada en los contadores agregados del día.
     *
     * @param int $idUserOwner
     * @param string $canonicalNumber
     * @param string $localDate
     * @param string $status 'ANSWERED' | 'BUSY' | 'NOANSWER' | 'FAILED' | 'CONGESTION'
     * @return bool
     */
    public static function recordResultQuota($idUserOwner, $canonicalNumber, $localDate, $status)
    {
        try {
            $isAnswered = ($status === 'ANSWERED') ? 1 : 0;
            $isFailed = in_array($status, ['BUSY', 'NOANSWER', 'FAILED', 'CONGESTION', 'CANCEL'], true) ? 1 : 0;

            $sql = "UPDATE pkg_campaign_optimizer_daily 
                    SET answered_count = answered_count + :isAns,
                        failed_count = failed_count + :isFail
                    WHERE id_user_owner = :owner AND canonical_number = :canon AND local_date = :date";

            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':owner', (int) $idUserOwner, PDO::PARAM_INT);
            $command->bindValue(':canon', $canonicalNumber, PDO::PARAM_STR);
            $command->bindValue(':date', $localDate, PDO::PARAM_STR);
            $command->bindValue(':isAns', $isAnswered, PDO::PARAM_INT);
            $command->bindValue(':isFail', $isFailed, PDO::PARAM_INT);
            return $command->execute() > 0;
        } catch (Exception $e) {
            Yii::log('Error in recordResultQuota: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return false;
        }
    }

    /**
     * Aplica suspensión temporal de contacto por fallos reiterados en el día local.
     *
     * @param int $idCampaign
     * @param int $idPhonenumber
     * @param string $reason
     * @param string|null $timezone
     * @return bool
     */
    public static function applyTemporarySuspension($idCampaign, $idPhonenumber, $reason, $timezone = null)
    {
        try {
            $timezone = $timezone ?: 'America/Mexico_City';
            $tz = new DateTimeZone($timezone);
            $nowLocal = new DateTime('now', $tz);
            // Expira a las 23:59:59 del día local
            $endOfDay = new DateTime($nowLocal->format('Y-m-d') . ' 23:59:59', $tz);
            $endOfDay->setTimezone(new DateTimeZone('UTC'));
            $expiresUtc = $endOfDay->format('Y-m-d H:i:s');

            $sql = "INSERT INTO pkg_campaign_optimizer_state 
                        (id_campaign, id_phonenumber, policy_status, suspension_reason, suspension_expires_at, updated_by)
                    VALUES 
                        (:c, :p, 'TEMP_SUSPENDED', :reason, :expires, 'SYSTEM')
                    ON DUPLICATE KEY UPDATE 
                        policy_status = 'TEMP_SUSPENDED',
                        suspension_reason = :reason,
                        suspension_expires_at = :expires,
                        updated_by = 'SYSTEM'";

            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':c', (int) $idCampaign, PDO::PARAM_INT);
            $command->bindValue(':p', (int) $idPhonenumber, PDO::PARAM_INT);
            $command->bindValue(':reason', $reason, PDO::PARAM_STR);
            $command->bindValue(':expires', $expiresUtc, PDO::PARAM_STR);
            return $command->execute() > 0;
        } catch (Exception $e) {
            Yii::log('Error in applyTemporarySuspension: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return false;
        }
    }
}
