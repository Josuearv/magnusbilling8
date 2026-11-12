<?php

/**
 * Acoes do modulo "CallOnLine".
 *
 * =======================================
 * ###################################
 * MagnusBilling
 *
 * @package MagnusBilling
 * @author  Adilson Leffa Magnus.
 * @copyright   Todos os direitos reservados.
 * ###################################
 * =======================================
 * Magnusbilling.com <info@magnusbilling.com>
 * 19/09/2012
 */

class StatusSystemController extends Controller
{
    public $attributeOrder = 't.id DESC';

    public function init()
    {
        LoadConfig::syncSystemTimezone();
        $this->instanceModel = new StatusSystem;
        $this->abstractModel = StatusSystem::model();
        $this->titleReport   = Yii::t('zii', 'Status system');
        parent::init();

        if (!Yii::app()->session['isAdmin']) {
            $this->extraValues = array(
                'idUser'   => 'username',
                'idPlan'   => 'name',
                'idPrefix' => 'destination',
            );
        }
    }

    public function setAttributesModels($attributes, $models)
    {
        LoadConfig::syncSystemTimezone();
        $callChartCmdPath = Yii::app()->basePath . '/commands/CallChartCommand.php';
        if (is_file($callChartCmdPath)) {
            require_once $callChartCmdPath;
            if (class_exists('CallChartCommand')) {
                CallChartCommand::syncOnlineCallsNow(2);
            }
        }

        if (empty($attributes)) {
            $attributes = [[]];
        }

        // Format dates and ensure float metrics for each point
        for ($i = 0; $i < count($attributes); $i++) {
            if (!empty($attributes[$i]['date'])) {
                $attributes[$i]['date'] = date('H:i', strtotime($attributes[$i]['date']));
            }
            $attributes[$i]['networkin']  = isset($attributes[$i]['networkin']) ? (float) $attributes[$i]['networkin'] : 0.0;
            $attributes[$i]['networkout'] = isset($attributes[$i]['networkout']) ? (float) $attributes[$i]['networkout'] : 0.0;
        }

        if (count($attributes) === 1 && !empty($attributes[0]['date'])) {
            $prev               = $attributes[0];
            $prev['date']       = date('H:i', strtotime('-1 minute'));
            $prev['networkin']  = 0.0;
            $prev['networkout'] = 0.0;
            $attributes         = [$prev, $attributes[0]];
        } elseif (count($attributes) > 1) {
            $attributes = array_reverse($attributes);
        }

        $totalActiveUsers         = User::model()->count('active = 1');
        $modelCallSummaryPerMonth = CallSummaryPerMonth::model()->find('month = :key', [':key' => date('Ym')]);
        $profitVal                = isset($modelCallSummaryPerMonth->lucro) ? (float) $modelCallSummaryPerMonth->lucro : null;
        if ($profitVal === null) {
            $monthStart = date('Y-m-01 00:00:00');
            $profitVal  = (float) Yii::app()->db->createCommand("
                SELECT COALESCE(SUM(sessionbill - buycost), 0)
                FROM pkg_cdr
                WHERE starttime >= :monthStart
            ")->bindValue(':monthStart', $monthStart, PDO::PARAM_STR)->queryScalar();
        }
        $monthprofit = number_format((float) $profitVal, 2);

        $todayStart = date('Y-m-d 00:00:00');

        $ccFromChart = (int) Yii::app()->db->createCommand("
            SELECT COALESCE(MAX(total), 0)
            FROM pkg_call_online_chart
            WHERE date >= :today
        ")->bindValue(':today', $todayStart, PDO::PARAM_STR)->queryScalar();

        $ccLive = (int) CallOnLine::model()->count();

        $cpsFromStatus = (int) Yii::app()->db->createCommand("
            SELECT COALESCE(MAX(cps), 0)
            FROM pkg_status_system
            WHERE date >= :today
        ")->bindValue(':today', $todayStart, PDO::PARAM_STR)->queryScalar();

        $cpsFromCdr = 0;
        try {
            $cpsFromCdr = (int) Yii::app()->db->createCommand("
                SELECT COALESCE(MAX(cnt), 0) FROM (
                    SELECT starttime, COUNT(*) AS cnt FROM (
                        SELECT starttime FROM pkg_cdr WHERE starttime >= :today
                        UNION ALL
                        SELECT starttime FROM pkg_cdr_failed WHERE starttime >= :today
                    ) u GROUP BY starttime
                ) s
            ")->bindValue(':today', $todayStart, PDO::PARAM_STR)->queryScalar();
        } catch (Exception $e) {
            $cpsFromCdr = 0;
        }

        $cpsValue  = max($cpsFromStatus, $cpsFromCdr);
        $ccPeakVal = max($ccFromChart, $ccLive, $cpsValue);
        $maximumcc = 'CC ' . $ccPeakVal . ' | CPS ' . $cpsValue;

        $modelRefill = Refill::model()->find([
            'select'    => 'sum(credit) as credit',
            'condition' => 'date >= :key',
            'params'    => [':key' => date('Y-m-01 00:00:00')],
        ]);
        $monthRefill = isset($modelRefill->credit) ? number_format($modelRefill->credit, 2) : '0.00';

        $lastIdx       = count($attributes) - 1;
        $targetIndices = array_unique([0, $lastIdx]);

        foreach ($targetIndices as $idx) {
            $attributes[$idx]['totalActiveUsers'] = $totalActiveUsers;
            $attributes[$idx]['monthprofit']      = $monthprofit;
            $attributes[$idx]['maximumcc']        = $maximumcc;
            $attributes[$idx]['monthRefill']      = $monthRefill;

            // Safe fallbacks for hardware metrics
            $attributes[$idx]['cpuPercent']  = $attributes[$idx]['cpuPercent'] ?? 0;
            $attributes[$idx]['cpuMediaUso'] = $attributes[$idx]['cpuMediaUso'] ?? 0;
            $attributes[$idx]['disk_perc']   = $attributes[$idx]['disk_perc'] ?? 0;
            $attributes[$idx]['disk_free']   = $attributes[$idx]['disk_free'] ?? 0;
            $attributes[$idx]['memTotal']    = $attributes[$idx]['memTotal'] ?? 0;
            $attributes[$idx]['memUsed']     = $attributes[$idx]['memUsed'] ?? 0;
            $attributes[$idx]['uptime']      = $attributes[$idx]['uptime'] ?? '';
        }

        return $attributes;
    }
}
