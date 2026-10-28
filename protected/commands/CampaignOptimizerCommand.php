<?php
/**
 * Campaign Optimizer Engine Console Command
 *
 * Replaces fragile bash/python cron scripts with native, robust, transactional,
 * and audited scheduling, audio rotation, attempt reconciliation, and failure tracking.
 *
 * MagnusBilling 8 - Fork Josuearv
 */
class CampaignOptimizerCommand extends CConsoleCommand
{
    private $lockHandle = null;

    /**
     * Entrypoint for CLI invocation
     *
     * @param array $args Optional CLI arguments (e.g., 'all', 'sync-schedules', 'rotate-audios', 'reconcile', 'cleanup')
     * @return int Exit code
     */
    public function run($args)
    {
        if (! $this->acquireLock()) {
            echo '[' . date('Y-m-d H:i:s') . "] CampaignOptimizer already running. Skipping execution.\n";
            return 0;
        }

        $action    = $args[0] ?? 'all';
        $startTime = microtime(true);

        // Create log record
        $logId = $this->createLogRecord('CRON', 'SYSTEM');

        $metrics = [
            'attempts_reserved'   => 0,
            'attempts_reconciled' => 0,
            'suspensions_applied' => 0,
            'suspensions_expired' => 0,
            'schedules_updated'   => 0,
            'audios_rotated'      => 0,
            'error_details'       => null,
        ];

        try {
            echo '[' . date('Y-m-d H:i:s') . "] Starting CampaignOptimizer execution (Action: $action)...\n";

            // 1. Expire temporary suspensions that have passed their suspended_until deadline
            if ($action === 'all' || $action === 'reconcile' || $action === 'cleanup') {
                $expiredCount                    = $this->expireTemporarySuspensions();
                $metrics['suspensions_expired'] = $expiredCount;
                if ($expiredCount > 0) {
                    echo "  - Expired suspensions lifted: $expiredCount\n";
                }
            }

            // 2. Synchronize schedules with daily jitter and day-of-week rules
            if ($action === 'all' || $action === 'sync-schedules') {
                $schedUpdated                 = CampaignScheduleService::syncAllActiveCampaignSchedules();
                $metrics['schedules_updated'] = $schedUpdated;
                echo "  - Schedules calculated & synced: $schedUpdated\n";
            }

            // 3. Audio discovery and atomic rotation
            if ($action === 'all' || $action === 'rotate-audios') {
                $audiosRotated             = CampaignAudioService::rotateAudiosForAllActiveCampaigns();
                $metrics['audios_rotated'] = $audiosRotated;
                echo "  - Audios discovered & rotated: $audiosRotated\n";
            }

            // 4. Reconcile pending/dispatched attempts against Asterisk reports and CDRs
            if ($action === 'all' || $action === 'reconcile') {
                $reconciledCount                = CampaignResultAdapter::reconcilePendingAttempts();
                $metrics['attempts_reconciled'] = $reconciledCount;
                echo "  - Pending attempts reconciled: $reconciledCount\n";
            }

            // 5. Cleanup old records according to retention policy
            if ($action === 'all' || $action === 'cleanup') {
                $this->pruneOldRecords();
            }

            $duration = round(microtime(true) - $startTime, 3);
            $this->finalizeLogRecord($logId, 'COMPLETED', $duration, $metrics);

            echo '[' . date('Y-m-d H:i:s') . "] CampaignOptimizer completed in {$duration}s.\n";
        } catch (Throwable $e) {
            $duration                 = round(microtime(true) - $startTime, 3);
            $metrics['error_details'] = $e->getMessage() . "\n" . $e->getTraceAsString();
            $this->finalizeLogRecord($logId, 'FAILED', $duration, $metrics);

            echo '[' . date('Y-m-d H:i:s') . "] CampaignOptimizer FAILED in {$duration}s: " . $e->getMessage() . "\n";
        } finally {
            $this->releaseLock();
        }

        return 0;
    }

    /**
     * Acquires non-blocking exclusive file lock
     *
     * @return bool True if acquired, false if already locked
     */
    private function acquireLock()
    {
        $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'CampaignOptimizerCommand.lock';
        $handle   = @fopen($lockFile, 'c+');

        if (! $handle) {
            return false;
        }

        if (! @flock($handle, LOCK_EX | LOCK_NB)) {
            @fclose($handle);
            return false;
        }

        $this->lockHandle = $handle;
        return true;
    }

    /**
     * Releases file lock
     */
    private function releaseLock()
    {
        if ($this->lockHandle) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    /**
     * Lifts expired temporary suspensions
     *
     * @return int Number of expired suspensions lifted
     */
    private function expireTemporarySuspensions()
    {
        $db = Yii::app()->db;

        // Lift in state table
        $sql = "UPDATE pkg_campaign_optimizer_state
                SET state = 'ACTIVE', reason = 'Suspensión temporal expirada', updated_at = NOW()
                WHERE state = 'SUSPENDED_TEMP'
                  AND is_permanent = 0
                  AND suspended_until IS NOT NULL
                  AND suspended_until <= NOW()";
        $affected = $db->createCommand($sql)->execute();

        // Sync with failed analytics table
        $sqlAnalytics = "UPDATE pkg_campaign_failed_analytics
                         SET current_state = 'ACTIVE'
                         WHERE current_state = 'SUSPENDED_TEMP'
                           AND suspended_until IS NOT NULL
                           AND suspended_until <= NOW()";
        $db->createCommand($sqlAnalytics)->execute();

        return (int) $affected;
    }

    /**
     * Prunes expired logs and daily quotas older than 30 days
     */
    private function pruneOldRecords()
    {
        $db = Yii::app()->db;

        // Keep 30 days of execution logs
        $db->createCommand(
            'DELETE FROM pkg_campaign_optimizer_log WHERE start_time < DATE_SUB(NOW(), INTERVAL 30 DAY)'
        )->execute();

        // Keep 30 days of daily quota counters
        $db->createCommand(
            'DELETE FROM pkg_campaign_optimizer_daily WHERE date_day < DATE_SUB(CURDATE(), INTERVAL 30 DAY)'
        )->execute();
    }

    /**
     * Creates an initial RUNNING log entry in pkg_campaign_optimizer_log
     */
    private function createLogRecord($executionType, $requestedBy)
    {
        $db  = Yii::app()->db;
        $sql = "INSERT INTO pkg_campaign_optimizer_log
                (execution_type, requested_by, status, start_time)
                VALUES (:type, :by, 'RUNNING', NOW())";

        $db->createCommand($sql)
            ->bindValue(':type', $executionType, PDO::PARAM_STR)
            ->bindValue(':by', $requestedBy, PDO::PARAM_STR)
            ->execute();

        return (int) $db->getLastInsertID();
    }

    /**
     * Finalizes log entry with completion metrics and duration
     */
    private function finalizeLogRecord($logId, $status, $duration, array $metrics)
    {
        if ($logId <= 0) {
            return;
        }

        $db  = Yii::app()->db;
        $sql = 'UPDATE pkg_campaign_optimizer_log
                SET status = :status,
                    duration_seconds = :duration,
                    attempts_reserved = :reserved,
                    attempts_reconciled = :reconciled,
                    suspensions_applied = :susp_applied,
                    suspensions_expired = :susp_expired,
                    schedules_updated = :sched_updated,
                    audios_rotated = :aud_rotated,
                    error_details = :err
                WHERE id = :id';

        $db->createCommand($sql)
            ->bindValue(':status', $status, PDO::PARAM_STR)
            ->bindValue(':duration', $duration, PDO::PARAM_STR)
            ->bindValue(':reserved', (int) ($metrics['attempts_reserved'] ?? 0), PDO::PARAM_INT)
            ->bindValue(':reconciled', (int) ($metrics['attempts_reconciled'] ?? 0), PDO::PARAM_INT)
            ->bindValue(':susp_applied', (int) ($metrics['suspensions_applied'] ?? 0), PDO::PARAM_INT)
            ->bindValue(':susp_expired', (int) ($metrics['suspensions_expired'] ?? 0), PDO::PARAM_INT)
            ->bindValue(':sched_updated', (int) ($metrics['schedules_updated'] ?? 0), PDO::PARAM_INT)
            ->bindValue(':aud_rotated', (int) ($metrics['audios_rotated'] ?? 0), PDO::PARAM_INT)
            ->bindValue(':err', $metrics['error_details'], PDO::PARAM_STR)
            ->bindValue(':id', $logId, PDO::PARAM_INT)
            ->execute();
    }
}
