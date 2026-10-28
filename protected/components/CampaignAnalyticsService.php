<?php
/**
 * Servicio para gestión y proyección de analítica de llamadas fallidas.
 *
 * Mantiene la tabla agregada pkg_campaign_failed_analytics para consultas
 * de alto rendimiento en la interfaz ExtJS y auditoría forense.
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
     * @param string $status 'BUSY' | 'NOANSWER' | 'FAILED' | 'CONGESTION'
     * @param int|null $hangupcause
     * @param string|null $sipCode
     * @param bool $isSuspended
     * @return bool
     */
    public static function recordFailedAttempt($idCampaign, $canonicalNumber, $localDate, $status, $hangupcause = null, $sipCode = null, $isSuspended = false)
    {
        try {
            $isNoAnswer = ($status === 'NOANSWER') ? 1 : 0;
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
                        SUM(IF(status = 'NOANSWER', 1, 0)) AS fails_noanswer,
                        SUM(IF(status = 'BUSY', 1, 0)) AS fails_busy,
                        SUM(IF(status IN ('FAILED', 'CANCEL'), 1, 0)) AS fails_failed,
                        SUM(IF(status = 'CONGESTION', 1, 0)) AS fails_congestion,
                        SUBSTRING_INDEX(GROUP_CONCAT(hangupcause ORDER BY id DESC SEPARATOR ','), ',', 1) AS last_hangupcause,
                        SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(sip_code, '') ORDER BY id DESC SEPARATOR ','), ',', 1) AS last_sip_code,
                        MAX(finalized_at) AS last_attempt_at,
                        'ACTIVE' AS status_cache
                    FROM pkg_campaign_optimizer_attempt
                    WHERE id_campaign = :idCampaign
                      AND status IN ('BUSY', 'NOANSWER', 'FAILED', 'CANCEL', 'CONGESTION')
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

        // Contar contactos suspendidos hoy
        $suspendedSql = "SELECT COUNT(*) FROM pkg_campaign_optimizer_state
                         WHERE policy_status = 'TEMP_SUSPENDED'
                           AND suspension_expires_at > NOW()";
        if (!empty($idCampaign) && (int) $idCampaign > 0) {
            $suspendedSql .= " AND id_campaign = " . (int) $idCampaign;
        }
        $suspendedCount = (int) Yii::app()->db->createCommand($suspendedSql)->queryScalar();

        $totalFails = (int) $row['total_fails'];
        $pctNoAnswer = $totalFails > 0 ? round(($row['fails_noanswer'] / $totalFails) * 100, 1) : 0;
        $pctBusy = $totalFails > 0 ? round(($row['fails_busy'] / $totalFails) * 100, 1) : 0;
        $pctFailed = $totalFails > 0 ? round(($row['fails_failed'] / $totalFails) * 100, 1) : 0;
        $pctCongestion = $totalFails > 0 ? round(($row['fails_congestion'] / $totalFails) * 100, 1) : 0;

        return [
            'total_fails' => $totalFails,
            'unique_failed_numbers' => (int) $row['total_unique_failed_numbers'],
            'fails_noanswer' => (int) $row['fails_noanswer'],
            'fails_busy' => (int) $row['fails_busy'],
            'fails_failed' => (int) $row['fails_failed'],
            'fails_congestion' => (int) $row['fails_congestion'],
            'pct_noanswer' => $pctNoAnswer,
            'pct_busy' => $pctBusy,
            'pct_failed' => $pctFailed,
            'pct_congestion' => $pctCongestion,
            'suspended_today' => $suspendedCount,
            'local_date' => $localDate,
        ];
    }
}
