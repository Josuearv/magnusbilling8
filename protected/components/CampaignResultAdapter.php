<?php
/**
 * Adaptador y reconciliador de resultados para intentos de campaña.
 *
 * Correlaciona intentos en estado DISPATCHED contra evidencias de llamadas
 * en pkg_campaign_report, pkg_cdr, pkg_cdr_failed y pkg_magnus_sentinel_trunk_event.
 *
 * MagnusBilling 8 - Campaign Optimizer
 */
class CampaignResultAdapter
{
    /**
     * Reconcilia intentos abiertos buscando evidencias de resolución.
     *
     * @param int $limit Número máximo de intentos a procesar por ciclo
     * @return array Resumen de intentos reconciliados
     */
    public static function reconcileDispatchedAttempts($limit = 500)
    {
        $stats = [
            'processed' => 0,
            'answered' => 0,
            'busy' => 0,
            'noanswer' => 0,
            'congestion' => 0,
            'failed' => 0,
            'timed_out' => 0,
        ];

        try {
            $cutoff = date('Y-m-d H:i:s', time() - 10); // mínimo 10 segundos tras despacho
            $timeoutCutoff = date('Y-m-d H:i:s', time() - 600); // 10 minutos sin resolución

            $sql = "SELECT id, attempt_uuid, id_campaign, id_phonenumber, id_user_owner,
                           original_number, canonical_number, dispatched_at
                    FROM pkg_campaign_optimizer_attempt
                    WHERE status = 'DISPATCHED' AND dispatched_at <= :cutoff
                    ORDER BY id ASC
                    LIMIT :limit";

            $command = Yii::app()->db->createCommand($sql);
            $command->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
            $command->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
            $attempts = $command->queryAll();

            if (empty($attempts)) {
                return $stats;
            }

            $sentinelTableExists = (int) Yii::app()->db->createCommand(
                "SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pkg_magnus_sentinel_trunk_event'"
            )->queryScalar() === 1;

            foreach ($attempts as $attempt) {
                $stats['processed']++;
                $attemptId = (int) $attempt['id'];
                $idCampaign = (int) $attempt['id_campaign'];
                $idPhone = (int) $attempt['id_phonenumber'];
                $destination = $attempt['original_number'];
                $canonical = $attempt['canonical_number'];
                $dispatchedAt = $attempt['dispatched_at'];
                $dispatchedTime = strtotime($dispatchedAt);

                $resolved = false;

                // 1. Verificar pkg_campaign_report (resultado registrado por AGI/despacho)
                $reportSql = "SELECT status, unix_timestamp
                              FROM pkg_campaign_report
                              WHERE id_campaign = :idCampaign AND id_phonenumber = :idPhone
                                AND unix_timestamp >= :minTime
                              ORDER BY id DESC LIMIT 1";
                $reportCmd = Yii::app()->db->createCommand($reportSql);
                $reportCmd->bindValue(':idCampaign', $idCampaign, PDO::PARAM_INT);
                $reportCmd->bindValue(':idPhone', $idPhone, PDO::PARAM_INT);
                $reportCmd->bindValue(':minTime', $dispatchedTime - 10, PDO::PARAM_INT);
                $report = $reportCmd->queryRow();

                if ($report) {
                    $repStatus = (int) $report['status'];
                    if ($repStatus === 3) {
                        CampaignAttemptService::markAnswered($attemptId);
                        $stats['answered']++;
                        $resolved = true;
                    } elseif ($repStatus === 4) {
                        CampaignAttemptService::markFinalized($attemptId, 'BUSY', 17, '486', 'pkg_campaign_report');
                        $stats['busy']++;
                        $resolved = true;
                    } elseif ($repStatus === 7) {
                        CampaignAttemptService::markFinalized($attemptId, 'CONGESTION', 34, '503', 'pkg_campaign_report');
                        $stats['congestion']++;
                        $resolved = true;
                    }
                }

                if ($resolved) {
                    continue;
                }

                // 2. Verificar pkg_cdr (llamada contestada registrada en CDR)
                $cdrSql = "SELECT id, uniqueid, id_trunk, terminatecauseid
                           FROM pkg_cdr
                           WHERE (calledstation = :dest OR calledstation = :canonical)
                             AND starttime >= :minDate AND starttime <= :maxDate
                           ORDER BY id DESC LIMIT 1";
                $cdrCmd = Yii::app()->db->createCommand($cdrSql);
                $cdrCmd->bindValue(':dest', $destination, PDO::PARAM_STR);
                $cdrCmd->bindValue(':canonical', $canonical, PDO::PARAM_STR);
                $cdrCmd->bindValue(':minDate', date('Y-m-d H:i:s', $dispatchedTime - 10), PDO::PARAM_STR);
                $cdrCmd->bindValue(':maxDate', date('Y-m-d H:i:s', $dispatchedTime + 180), PDO::PARAM_STR);
                $cdr = $cdrCmd->queryRow();

                if ($cdr) {
                    CampaignAttemptService::markFinalized(
                        $attemptId,
                        'ANSWERED',
                        16,
                        '200',
                        'pkg_cdr',
                        $cdr['uniqueid']
                    );
                    $stats['answered']++;
                    $resolved = true;
                    continue;
                }

                // 3. Verificar pkg_cdr_failed (llamada fallida pre-AGI o rechazada)
                $failedSql = "SELECT id, uniqueid, id_trunk, id_server, terminatecauseid, hangupcause
                              FROM pkg_cdr_failed
                              WHERE (calledstation = :dest OR calledstation = :canonical)
                                AND starttime >= :minDate AND starttime <= :maxDate
                              ORDER BY id DESC LIMIT 1";
                $failedCmd = Yii::app()->db->createCommand($failedSql);
                $failedCmd->bindValue(':dest', $destination, PDO::PARAM_STR);
                $failedCmd->bindValue(':canonical', $canonical, PDO::PARAM_STR);
                $failedCmd->bindValue(':minDate', date('Y-m-d H:i:s', $dispatchedTime - 10), PDO::PARAM_STR);
                $failedCmd->bindValue(':maxDate', date('Y-m-d H:i:s', $dispatchedTime + 180), PDO::PARAM_STR);
                $failedCdr = $failedCmd->queryRow();

                if ($failedCdr) {
                    $termCause = (int) $failedCdr['terminatecauseid'];
                    $hangup = $failedCdr['hangupcause'] !== null ? (int) $failedCdr['hangupcause'] : null;
                    $uniqueid = $failedCdr['uniqueid'];
                    $idServer = $failedCdr['id_server'];
                    $sipCode = null;

                    // Enriquecimiento opcional con Sentinel si existe
                    if ($sentinelTableExists && !empty($uniqueid) && $idServer !== null) {
                        $sentinelSql = "SELECT response FROM pkg_magnus_sentinel_trunk_event
                                        WHERE uniqueid = :uid AND id_server = :srv
                                        ORDER BY event_time DESC, id DESC LIMIT 1";
                        $sentinelCmd = Yii::app()->db->createCommand($sentinelSql);
                        $sentinelCmd->bindValue(':uid', $uniqueid, PDO::PARAM_STR);
                        $sentinelCmd->bindValue(':srv', (int) $idServer, PDO::PARAM_INT);
                        $response = $sentinelCmd->queryScalar();
                        if ($response) {
                            $sipCode = substr((string) $response, 0, 20);
                        }
                    }

                    if ($termCause === 1) {
                        $status = 'ANSWERED';
                        $stats['answered']++;
                    } elseif ($termCause === 2 || $hangup === 17) {
                        $status = 'BUSY';
                        $stats['busy']++;
                    } elseif ($termCause === 3 || $hangup === 19) {
                        $status = 'NOANSWER';
                        $stats['noanswer']++;
                    } elseif ($termCause === 4) {
                        $status = 'CANCEL';
                        $stats['failed']++;
                    } elseif ($hangup === 34 || strpos((string) $sipCode, '503') !== false) {
                        $status = 'CONGESTION';
                        $stats['congestion']++;
                    } else {
                        $status = 'FAILED';
                        $stats['failed']++;
                    }

                    CampaignAttemptService::markFinalized(
                        $attemptId,
                        $status,
                        $hangup,
                        $sipCode,
                        'pkg_cdr_failed',
                        $uniqueid
                    );
                    $resolved = true;
                    continue;
                }

                // 4. Si ha superado los 10 minutos sin ninguna evidencia, marcar como TIMED_OUT
                if (!$resolved && $dispatchedAt <= $timeoutCutoff) {
                    CampaignAttemptService::markFinalized(
                        $attemptId,
                        'TIMED_OUT',
                        null,
                        null,
                        'TIMEOUT_NO_CDR',
                        null
                    );
                    $stats['timed_out']++;
                }
            }

            return $stats;
        } catch (Exception $e) {
            Yii::log('Error in CampaignResultAdapter: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            return $stats;
        }
    }
}
