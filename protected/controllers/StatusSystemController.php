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
        if (empty($attributes)) {
            $attributes = [[]];
        }

        // Format dates and ensure float metrics for each point
        for ($i = 0; $i < count($attributes); $i++) {
            if (!empty($attributes[$i]['date'])) {
                $attributes[$i]['date'] = date('H:i', strtotime($attributes[$i]['date']));
            }
            $attributes[$i]['networkin']  = isset($attributes[$i]['networkin']) ? (float)$attributes[$i]['networkin'] : 0.0;
            $attributes[$i]['networkout'] = isset($attributes[$i]['networkout']) ? (float)$attributes[$i]['networkout'] : 0.0;
        }

        if (count($attributes) === 1 && !empty($attributes[0]['date'])) {
            $prev = $attributes[0];
            $prev['date'] = date('H:i', strtotime('-1 minute'));
            $prev['networkin'] = 0.0;
            $prev['networkout'] = 0.0;
            $attributes = [$prev, $attributes[0]];
        } elseif (count($attributes) > 1) {
            $attributes = array_reverse($attributes);
        }

        $totalActiveUsers = User::model()->count('active = 1');
        $modelCallSummaryPerMonth = CallSummaryPerMonth::model()->find('month = :key', [':key' => date('Ym')]);
        $monthprofit = isset($modelCallSummaryPerMonth->lucro) ? number_format($modelCallSummaryPerMonth->lucro, 2) : '0.00';

        $modelCallOnlineChart = CallOnlineChart::model()->find([
            'condition' => 'date > :key',
            'params'    => [':key' => date('Y-m-d')],
            'order'     => 'total DESC',
        ]);

        $totalCPS = StatusSystem::model()->find([
            'condition' => 'date > :key',
            'params'    => [':key' => date('Y-m-d 00:00:00')],
            'order'     => 'cps DESC',
        ]);
        $cpsValue = (isset($totalCPS->cps)) ? $totalCPS->cps : 0;
        $maximumcc = isset($modelCallOnlineChart->total) ? 'CC ' . $modelCallOnlineChart->total . ' | CPS ' . $cpsValue : 'CC 0 | CPS ' . $cpsValue;

        $modelRefill = Refill::model()->find([
            'select'    => 'sum(credit) as credit',
            'condition' => 'date > :key',
            'params'    => [':key' => date('Y-m') . '-01'],
        ]);
        $monthRefill = isset($modelRefill->credit) ? number_format($modelRefill->credit, 2) : '0.00';

        $lastIdx = count($attributes) - 1;
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
