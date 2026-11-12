<?php
/**
 * CDR & ASR Number Quality Analytics Console Command.
 *
 * Usage:
 *   php cron.php NumberQuality                   # Incremental sync + rolling 30d score recompute (auto-backfill on first run)
 *   php cron.php NumberQuality backfill [days]   # Rebuild rolling daily aggregates for the last N days (default 30)
 *   php cron.php NumberQuality score [days] [min]# Recompute scores only from daily aggregates
 */
class NumberQualityCommand extends CConsoleCommand
{
    private $lockHandle = null;

    /**
     * Console entry point.
     *
     * @param array $args
     * @return int Exit code
     */
    public function run($args)
    {
        if (! $this->acquireLock()) {
            echo '[' . date('Y-m-d H:i:s') . "] NumberQuality already running. Skipping execution.\n";
            return 0;
        }

        $action = $args[0] ?? 'sync';

        try {
            if ($action === 'backfill') {
                $days = isset($args[1]) ? max(1, min(90, (int) $args[1])) : NumberQualityService::DEFAULT_WINDOW_DAYS;
                echo '[' . date('Y-m-d H:i:s') . "] Running NumberQuality backfill ({$days}d)...\n";
                $res = NumberQualityService::backfillAndScore($days);
            } elseif ($action === 'score') {
                $days   = isset($args[1]) ? max(1, min(90, (int) $args[1])) : NumberQualityService::DEFAULT_WINDOW_DAYS;
                $minAtt = isset($args[2]) ? max(1, (int) $args[2]) : NumberQualityService::DEFAULT_MIN_ATTEMPTS;
                echo '[' . date('Y-m-d H:i:s') . "] Running NumberQuality score recompute ({$days}d, min={$minAtt})...\n";
                $res = NumberQualityService::recomputeScoresOnly($days, $minAtt);
            } else {
                echo '[' . date('Y-m-d H:i:s') . "] Running NumberQuality incremental sync & score...\n";
                $res = NumberQualityService::syncAndScore();
            }

            echo '[' . date('Y-m-d H:i:s') . '] Result: ' . json_encode($res) . "\n";
            return !empty($res['success']) || !empty($res['skipped']) ? 0 : 1;
        } catch (Exception $e) {
            Yii::log('NumberQualityCommand error: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            echo '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . "\n";
            return 1;
        } finally {
            $this->releaseLock();
        }
    }

    private function acquireLock()
    {
        if (!function_exists('flock')) {
            return true;
        }
        $lockFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mbilling_number_quality.lock';
        $this->lockHandle = @fopen($lockFile, 'c+');
        if ($this->lockHandle === false) {
            return false;
        }
        if (!@flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
            @fclose($this->lockHandle);
            $this->lockHandle = null;
            return false;
        }
        return true;
    }

    private function releaseLock()
    {
        if ($this->lockHandle !== null) {
            if (function_exists('flock')) {
                @flock($this->lockHandle, LOCK_UN);
            }
            @fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }
}
