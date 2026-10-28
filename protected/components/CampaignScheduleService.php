<?php
/**
 * Servicio para cálculo de horarios dinámicos con variación de inicio (jitter)
 * y adaptación de frecuencias de marcación por día de la semana.
 *
 * MagnusBilling 8 - Campaign Optimizer
 */
class CampaignScheduleService
{
    /**
     * Calcula y aplica el horario efectivo del día y la frecuencia para una campaña.
     *
     * @param int $idCampaign
     * @param string|null $date Fecha en formato YYYY-MM-DD (por defecto hoy)
     * @return array|null Datos del horario efectivo aplicado o null si no está configurada
     */
    public static function calculateScheduleForDay($idCampaign, $date = null)
    {
        try {
            $date = $date ?: date('Y-m-d');
            $config = CampaignOptimizerConfig::model()->find(
                'id_campaign = :c AND activated = 1',
                [':c' => (int) $idCampaign]
            );

            if (!$config) {
                return null;
            }

            $dayOfWeek = (int) date('N', strtotime($date)); // 1=Lunes, 7=Domingo

            // 1. Determinar base horaria y frecuencia según día de la semana
            if ($dayOfWeek >= 1 && $dayOfWeek <= 5) {
                // Lunes a Viernes
                $startBase = $config->weekday_start_time ?: '07:00:00';
                $stopBase = $config->weekday_stop_time ?: '21:00:00';
                $targetFreq = (int) $config->weekday_frequency ?: 80;
                $maxFreq = (int) $config->weekday_max_frequency ?: 100;
            } elseif ($dayOfWeek === 6) {
                // Sábado
                $startBase = $config->saturday_start_time ?: '07:00:00';
                $stopBase = $config->saturday_stop_time ?: '14:00:00';
                $targetFreq = (int) $config->weekend_frequency ?: 20;
                $maxFreq = (int) $config->weekend_max_frequency ?: 50;
            } else {
                // Domingo
                $startBase = $config->sunday_start_time ?: '09:00:00';
                $stopBase = $config->sunday_stop_time ?: '14:00:00';
                $targetFreq = (int) $config->weekend_frequency ?: 20;
                $maxFreq = (int) $config->weekend_max_frequency ?: 50;
            }

            // 2. Cálculo determinista de la variación de inicio (Jitter)
            $jitterMinutes = 0;
            $effectiveStart = $startBase;
            $effectiveStop = $stopBase;

            if ($config->enable_schedule_jitter == 1) {
                $maxJitter = max(1, (int) $config->jitter_max_minutes);
                // Semilla determinista: asegura el mismo desplazamiento para esta campaña y fecha específica
                $seed = abs(crc32($idCampaign . ':' . $date . ':start_jitter'));
                $jitterMinutes = $seed % ($maxJitter + 1);

                $startTimestamp = strtotime($date . ' ' . $startBase) + ($jitterMinutes * 60);
                $stopTimestamp = strtotime($date . ' ' . $stopBase);

                // Asegurar que la hora de inicio desplazada no sobrepase la hora de fin
                if ($startTimestamp < $stopTimestamp) {
                    $effectiveStart = date('H:i:s', $startTimestamp);
                }
            }

            // 3. Persistir en pkg_campaign_optimizer_schedule
            $sqlSchedule = "INSERT INTO pkg_campaign_optimizer_schedule
                                (id_campaign, schedule_date, start_time_base, stop_time_base,
                                 jitter_minutes_applied, effective_start_time, effective_stop_time,
                                 target_frequency, max_frequency)
                            VALUES
                                (:idCampaign, :scheduleDate, :startBase, :stopBase,
                                 :jitter, :effectiveStart, :effectiveStop,
                                 :targetFreq, :maxFreq)
                            ON DUPLICATE KEY UPDATE
                                start_time_base = :startBase,
                                stop_time_base = :stopBase,
                                jitter_minutes_applied = :jitter,
                                effective_start_time = :effectiveStart,
                                effective_stop_time = :effectiveStop,
                                target_frequency = :targetFreq,
                                max_frequency = :maxFreq";

            $cmd = Yii::app()->db->createCommand($sqlSchedule);
            $cmd->bindValue(':idCampaign', (int) $idCampaign, PDO::PARAM_INT);
            $cmd->bindValue(':scheduleDate', $date, PDO::PARAM_STR);
            $cmd->bindValue(':startBase', $startBase, PDO::PARAM_STR);
            $cmd->bindValue(':stopBase', $stopBase, PDO::PARAM_STR);
            $cmd->bindValue(':jitter', (int) $jitterMinutes, PDO::PARAM_INT);
            $cmd->bindValue(':effectiveStart', $effectiveStart, PDO::PARAM_STR);
            $cmd->bindValue(':effectiveStop', $effectiveStop, PDO::PARAM_STR);
            $cmd->bindValue(':targetFreq', (int) $targetFreq, PDO::PARAM_INT);
            $cmd->bindValue(':maxFreq', (int) $maxFreq, PDO::PARAM_INT);
            $cmd->execute();

            // 4. Aplicar en pkg_campaign directamente
            $updateCampaignSql = "UPDATE pkg_campaign 
                                  SET daily_start_time = :start,
                                      daily_stop_time = :stop,
                                      frequency = :freq,
                                      max_frequency = :maxFreq
                                  WHERE id = :id";
            $cmdUpdate = Yii::app()->db->createCommand($updateCampaignSql);
            $cmdUpdate->bindValue(':start', $effectiveStart, PDO::PARAM_STR);
            $cmdUpdate->bindValue(':stop', $effectiveStop, PDO::PARAM_STR);
            $cmdUpdate->bindValue(':freq', (int) $targetFreq, PDO::PARAM_INT);
            $cmdUpdate->bindValue(':maxFreq', (int) $maxFreq, PDO::PARAM_INT);
            $cmdUpdate->bindValue(':id', (int) $idCampaign, PDO::PARAM_INT);
            $cmdUpdate->execute();

            return [
                'id_campaign' => (int) $idCampaign,
                'schedule_date' => $date,
                'start_base' => $startBase,
                'stop_base' => $stopBase,
                'jitter_minutes' => (int) $jitterMinutes,
                'effective_start' => $effectiveStart,
                'effective_stop' => $effectiveStop,
                'target_frequency' => (int) $targetFreq,
                'max_frequency' => (int) $maxFreq,
            ];
        } catch (Exception $e) {
            Yii::log('Error in calculateScheduleForDay: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return null;
        }
    }

    /**
     * Aplica el cálculo diario a todas las campañas activas gestionadas por el optimizador.
     *
     * @param string|null $date
     * @return array Resumen de campañas procesadas
     */
    public static function applyDailySchedulesForAllActiveCampaigns($date = null)
    {
        $date = $date ?: date('Y-m-d');
        $updated = [];

        try {
            $configs = CampaignOptimizerConfig::model()->findAll('activated = 1');
            foreach ($configs as $config) {
                $result = self::calculateScheduleForDay($config->id_campaign, $date);
                if ($result) {
                    $updated[] = $result;
                }
            }
        } catch (Exception $e) {
            Yii::log('Error in applyDailySchedulesForAllActiveCampaigns: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
        }

        return $updated;
    }
}
