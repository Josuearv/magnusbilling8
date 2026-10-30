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

        $attributes[0]['totalActiveUsers'] = User::model()->count('active = 1');
        $modelCallSummaryPerMonth          = CallSummaryPerMonth::model()->find('month = :key', [':key' => date('Ym')]);

        $attributes[0]['monthprofit'] = isset($modelCallSummaryPerMonth->lucro) ? number_format($modelCallSummaryPerMonth->lucro, 2) : '0.00';

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
        $attributes[0]['maximumcc'] = isset($modelCallOnlineChart->total) ? 'CC ' . $modelCallOnlineChart->total . ' | CPS ' . $cpsValue : 'CC 0 | CPS ' . $cpsValue;

        $modelRefill = Refill::model()->find([
            'select'    => 'sum(credit) as credit',
            'condition' => 'date > :key',
            'params'    => [':key' => date('Y-m') . '-01'],
        ]);
        $attributes[0]['monthRefill'] = isset($modelRefill->credit) ? number_format($modelRefill->credit, 2) : '0.00';

        // Safe fallbacks for hardware metrics
        $attributes[0]['cpuPercent']  = $attributes[0]['cpuPercent'] ?? 0;
        $attributes[0]['cpuMediaUso'] = $attributes[0]['cpuMediaUso'] ?? 0;
        $attributes[0]['disk_perc']   = $attributes[0]['disk_perc'] ?? 0;
        $attributes[0]['disk_free']   = $attributes[0]['disk_free'] ?? 0;
        $attributes[0]['memTotal']    = $attributes[0]['memTotal'] ?? 0;
        $attributes[0]['memUsed']     = $attributes[0]['memUsed'] ?? 0;
        $attributes[0]['uptime']      = $attributes[0]['uptime'] ?? '';

        return $attributes;
    }
}
