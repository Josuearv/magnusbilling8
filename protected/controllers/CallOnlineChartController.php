<?php
/**
 * Acoes do modulo "Call".
 *
 * =======================================
 * ###################################
 * MagnusBilling
 *
 * @package MagnusBilling
 * @author Adilson Leffa Magnus.
 * @copyright Copyright (C) 2005 - 2023 MagnusSolution. All rights reserved.
 * ###################################
 *
 * This software is released under the terms of the GNU Lesser General Public License v2.1
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 *
 * Please submit bug reports, patches, etc to https://github.com/magnusbilling/mbilling/issues
 * =======================================
 * Magnusbilling.com <info@magnusbilling.com>
 * 19/09/2012
 */

class CallOnlineChartController extends Controller
{

    public function init()
    {
        if ( ! Yii::app()->session['id_user']) {
            $this->sendError('Access denied for module "{module}".', ['{module}' => 'CallOnlineChart']);
            exit;
        }

        $this->instanceModel = new CallOnlineChart;
        $this->abstractModel = CallOnlineChart::model();
        $this->titleReport   = Yii::t('zii', 'CallOnlineChart');
        parent::init();
    }

    public function actionRead($asJson = true, $condition = null)
    {
        LoadConfig::syncSystemTimezone();
        require_once Yii::app()->basePath . '/commands/CallChartCommand.php';
        if (class_exists('CallChartCommand')) {
            CallChartCommand::syncOnlineCallsNow(2);
        }

        $filter = isset($_GET['filter']) ? json_decode($_GET['filter']) : null;

        $hoursValue = isset($filter[0]) && isset($filter[0]->value) ? $filter[0]->value : 1;
        $isPositiveInteger = is_int($hoursValue)
            || (is_string($hoursValue) && preg_match('/^[1-9][0-9]*$/D', $hoursValue));
        $hours = $isPositiveInteger
            ? filter_var($hoursValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        if ($hours === false) {
            header('HTTP/1.0 400 Bad Request');
            echo json_encode([
                $this->nameSuccess => false,
                $this->nameMsg     => 'Invalid hours filter',
            ]);
            return;
        }

        $sinceDate = date('Y-m-d H:i:s', time() - ($hours * 3600));

        // Backfill minute buckets in pkg_call_online_chart from real CDR / CDR Failed activity
        // if callchart was not running when calls were placed in the requested window.
        try {
            $hasNonZeroChart = (int) Yii::app()->db->createCommand("
                SELECT COUNT(*) FROM pkg_call_online_chart
                WHERE date >= :since AND total > 0
            ")->bindValue(':since', $sinceDate, PDO::PARAM_STR)->queryScalar();

            if ($hasNonZeroChart === 0) {
                $cdrMinutes = Yii::app()->db->createCommand("
                    SELECT
                        minute_bucket,
                        SUM(ans_cnt) AS answer_calls,
                        SUM(tot_cnt) AS total_calls
                    FROM (
                        SELECT DATE_FORMAT(starttime, '%Y-%m-%d %H:%i:00') AS minute_bucket,
                               COUNT(*) AS ans_cnt,
                               COUNT(*) AS tot_cnt
                        FROM pkg_cdr
                        WHERE starttime >= :since
                        GROUP BY DATE_FORMAT(starttime, '%Y-%m-%d %H:%i:00')
                        UNION ALL
                        SELECT DATE_FORMAT(starttime, '%Y-%m-%d %H:%i:00') AS minute_bucket,
                               0 AS ans_cnt,
                               COUNT(*) AS tot_cnt
                        FROM pkg_cdr_failed
                        WHERE starttime >= :since
                        GROUP BY DATE_FORMAT(starttime, '%Y-%m-%d %H:%i:00')
                    ) u
                    GROUP BY minute_bucket
                    ORDER BY minute_bucket ASC
                ")->bindValue(':since', $sinceDate, PDO::PARAM_STR)->queryAll();

                foreach ($cdrMinutes as $bucket) {
                    $mDate = $bucket['minute_bucket'] ?? '';
                    if ($mDate === '') {
                        continue;
                    }
                    $tot = (int) ($bucket['total_calls'] ?? 0);
                    $ans = (int) ($bucket['answer_calls'] ?? 0);
                    $existingBucket = CallOnlineChart::model()->find('date = :d', [':d' => $mDate]);
                    if ($existingBucket) {
                        if ((int) $existingBucket->total < $tot || (int) $existingBucket->answer < $ans) {
                            $existingBucket->total  = max((int) $existingBucket->total, $tot);
                            $existingBucket->answer = max((int) $existingBucket->answer, $ans);
                            $existingBucket->save();
                        }
                    } else {
                        $newBucket         = new CallOnlineChart();
                        $newBucket->date   = $mDate;
                        $newBucket->total  = $tot;
                        $newBucket->answer = $ans;
                        $newBucket->save();
                    }
                }
            }
        } catch (Exception $e) {
        }

        $filter = 'date >= :sinceDate';

        $dateFormat = 'MIN(DATE_FORMAT(date, \'%D %H:%i\')) AS date';
        $select     = 'MAX(id) AS id, ' . $dateFormat . ', MAX(total) AS total, MAX(answer) AS answer';

        if ($hours == 6) {
            $group = "UNIX_TIMESTAMP(date) DIV 120";
        } elseif ($hours == 12) {
            $group = "UNIX_TIMESTAMP(date) DIV 180";
        } elseif ($hours == 24) {
            $group = "UNIX_TIMESTAMP(date) DIV 300";
        } elseif ($hours == 48) {
            $group = "UNIX_TIMESTAMP(date) DIV 600";
        } elseif ($hours == 72) {
            $group = "UNIX_TIMESTAMP(date) DIV 900";
        } elseif ($hours > 12) {
            $group = "UNIX_TIMESTAMP(date) DIV 720";
        } else {
            $dateFormat = 'DATE_FORMAT(date, \'%H:%i\') AS date';
            $select     = 'id, ' . $dateFormat . ', total, answer';
            $group      = '';
        }

        $criteria = [
            'select'    => $select,
            'order'     => 'id ASC',
            'condition' => $filter,
            'params'    => [':sinceDate' => $sinceDate],
        ];
        if ($group !== '') {
            $criteria['group'] = $group;
        }

        $modelCallOnlineChart = CallOnlineChart::model()->findAll($criteria);

        $rows = $this->getAttributesModels($modelCallOnlineChart, $this->extraValues);
        if (!is_array($rows)) {
            $rows = [];
        }

        echo json_encode([
            $this->nameRoot  => $rows,
            $this->nameCount => count($rows),
        ]);

    }

}
