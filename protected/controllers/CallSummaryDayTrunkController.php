<?php

/**
 * Acoes do modulo "Call".
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
 * 17/08/2012
 */

class CallSummaryDayTrunkController extends Controller
{
    public $attributeOrder = 'day DESC';
    public $join           = 'JOIN pkg_trunk c ON t.id_trunk = c.id';
    public $extraValues    = array('idTrunk' => 'trunkcode');

    public $fieldsFkReport = array(
        'id_trunk' => array(
            'table'       => 'pkg_trunk',
            'pk'          => 'id',
            'fieldReport' => 'trunkcode',
        ),
    );

    public function init()
    {
        LoadConfig::syncSystemTimezone();
        $this->instanceModel = new CallSummaryDayTrunk;
        $this->abstractModel = CallSummaryDayTrunk::model();
        $this->titleReport   = Yii::t('zii', 'Summary Day Trunk');
        parent::init();
    }

    public function actionRead($asJson = true, $condition = null)
    {
        if (!Yii::app()->session['isAdmin']) {
            echo json_encode(array(
                $this->nameRoot  => [],
                $this->nameCount => 0,
                $this->nameSum   => [],
            ));
            exit;
        }
        parent::actionRead();
    }

    public function recordsExtraSum($records = array())
    {
        foreach ($records as $key => $value) {
            $records[0]->sumsessiontime += $value['sessiontime'] / 60;
            $records[0]->sumsessionbill += $value['sessionbill'];
            $records[0]->sumbuycost += $value['buycost'];
            $records[0]->sumaloc_all_calls += $value['sessiontime'] / ($value['nbcall'] == 0 ? 1 :  $value['nbcall']);
            $records[0]->sumnbcall += $value['nbcall'];
        }

        $this->nameSum = 'sum';

        return $records;
    }

    public function getAttributesModels($models, $itemsExtras = array())
    {
        $attributes = false;
        foreach ($models as $key => $item) {
            $attributes[$key]                      = $item->attributes;
            $attributes[$key]['nbcall']            = $item->nbcall;
            $attributes[$key]['day']               = $item->day;
            $attributes[$key]['aloc_all_calls']    = $item->aloc_all_calls;
            $attributes[$key]['sessiontime']       = $item->sessiontime / 60;
            $attributes[$key]['sumsessiontime']    = $item->sumsessiontime;
            $attributes[$key]['sumsessionbill']    = $item->sumsessionbill;
            $attributes[$key]['sumbuycost']        = $item->sumbuycost;
            $attributes[$key]['sumlucro']          = $item->sumsessionbill - $item->sumbuycost;
            $attributes[$key]['sumaloc_all_calls'] = $item->sumaloc_all_calls;
            $attributes[$key]['sumnbcall']         = $item->sumnbcall;

            if (isset(Yii::app()->session['isClient']) && Yii::app()->session['isClient']) {
                foreach ($this->fieldsInvisibleClient as $field) {
                    unset($attributes[$key][$field]);
                }
            }

            if (isset(Yii::app()->session['isAgent']) && Yii::app()->session['isAgent']) {
                foreach ($this->fieldsInvisibleAgent as $field) {
                    unset($attributes[$key][$field]);
                }
            }

            foreach ($itemsExtras as $relation => $fields) {
                $arrFields = explode(',', $fields);
                foreach ($arrFields as $field) {
                    if (isset($item->$relation->$field)) {

                        $attributes[$key][$relation . $field] = $item->$relation->$field;
                        if (Yii::app()->session['idClient']) {
                            foreach ($this->fieldsInvisibleClient as $field) {
                                unset($attributes[$key][$field]);
                            }
                        }

                        if (Yii::app()->session['idAgent']) {
                            foreach ($this->fieldsInvisibleAgent as $field) {
                                unset($attributes[$key][$field]);
                            }
                        }
                    }
                }
            }
        }

        return $attributes;
    }

    public function actionChart()
    {
        LoadConfig::syncSystemTimezone();
        $today     = date('Y-m-d');
        $todayFrom = $today . ' 00:00:00';
        $todayTo   = date('Y-m-d 00:00:00', strtotime($today . ' +1 day'));

        $liveTrunks = [];
        try {
            $liveTrunks = Yii::app()->db->createCommand("
                SELECT
                    u.id_trunk,
                    t.trunkcode AS idTrunktrunkcode,
                    SUM(u.sessiontime) AS sessiontime,
                    SUM(u.nbcall) AS nbcall,
                    SUM(u.nbcall_fail) AS nbcall_fail,
                    SUM(u.buycost) AS buycost,
                    SUM(u.sessionbill) AS sessionbill
                FROM (
                    SELECT
                        id_trunk,
                        COALESCE(SUM(real_sessiontime), 0) AS sessiontime,
                        COUNT(*) AS nbcall,
                        0 AS nbcall_fail,
                        COALESCE(SUM(buycost), 0) AS buycost,
                        COALESCE(SUM(sessionbill), 0) AS sessionbill
                    FROM pkg_cdr
                    WHERE starttime >= :from AND starttime < :to AND id_trunk > 0
                    GROUP BY id_trunk
                    UNION ALL
                    SELECT
                        id_trunk,
                        0 AS sessiontime,
                        0 AS nbcall,
                        COUNT(*) AS nbcall_fail,
                        0 AS buycost,
                        0 AS sessionbill
                    FROM pkg_cdr_failed
                    WHERE starttime >= :from AND starttime < :to AND id_trunk > 0
                    GROUP BY id_trunk
                ) u
                INNER JOIN pkg_trunk t ON t.id = u.id_trunk
                GROUP BY u.id_trunk, t.trunkcode
                ORDER BY SUM(u.sessionbill) DESC, SUM(u.nbcall) DESC, SUM(u.nbcall_fail) DESC
                LIMIT 3
            ")->queryAll(true, [
                ':from' => $todayFrom,
                ':to'   => $todayTo,
            ]);
        } catch (Exception $e) {
            $liveTrunks = [];
        }

        if (!empty($liveTrunks)) {
            $sumSessionTime = 0.0;
            $sumSessionBill = 0.0;
            $sumBuyCost     = 0.0;
            $sumNbCall      = 0;

            foreach ($liveTrunks as &$row) {
                $ansCalls   = (int) ($row['nbcall'] ?? 0);
                $failCalls  = (int) ($row['nbcall_fail'] ?? 0);
                $effCalls   = $ansCalls > 0 ? $ansCalls : $failCalls;
                $row['eff_nbcall'] = $effCalls;
                $sumSessionTime += ((float) ($row['sessiontime'] ?? 0)) / 60.0;
                $sumSessionBill += (float) ($row['sessionbill'] ?? 0);
                $sumBuyCost     += (float) ($row['buycost'] ?? 0);
                $sumNbCall      += $effCalls;
            }
            unset($row);

            $rows = [];
            foreach ($liveTrunks as $idx => $row) {
                $secTime     = (float) ($row['sessiontime'] ?? 0);
                $effCalls    = (int) $row['eff_nbcall'];
                $sessionBill = (float) ($row['sessionbill'] ?? 0);
                $buyCost     = (float) ($row['buycost'] ?? 0);
                $lucro       = $sessionBill - $buyCost;

                $rows[] = [
                    'id'               => (int) ($idx + 1),
                    'day'              => $today,
                    'id_trunk'         => (int) $row['id_trunk'],
                    'idTrunktrunkcode' => (string) $row['idTrunktrunkcode'],
                    'sessiontime'      => $secTime / 60.0,
                    'aloc_all_calls'   => $effCalls > 0 ? ($secTime / $effCalls) : 0,
                    'nbcall'           => $effCalls,
                    'nbcall_fail'      => (int) ($row['nbcall_fail'] ?? 0),
                    'buycost'          => $buyCost,
                    'sessionbill'      => $sessionBill,
                    'lucro'            => $lucro != 0.0 ? $lucro : ($sumSessionBill == 0.0 ? (float) $effCalls : 0.0),
                    'sumsessiontime'   => $sumSessionTime,
                    'sumsessionbill'   => $sumSessionBill,
                    'sumbuycost'       => $sumBuyCost,
                    'sumlucro'         => $sumSessionBill - $sumBuyCost,
                    'sumnbcall'        => $sumNbCall,
                ];
            }

            echo json_encode([
                $this->nameRoot  => $rows,
                $this->nameCount => count($rows),
                $this->nameSum   => $rows,
            ]);
            return;
        }

        $records = CallSummaryDayTrunk::model()->findAll([
            'condition' => 'day = :key',
            'params'    => array(':key' => $today),
            'limit'     => 3,
        ]);

        $recordsSum = $this->recordsExtraSum($records);
        $attrRows   = $this->getAttributesModels($records, $this->extraValues);
        if (!is_array($attrRows)) {
            $attrRows = [];
        }

        echo json_encode(array(
            $this->nameRoot  => $attrRows,
            $this->nameCount => count($attrRows),
            $this->nameSum   => $this->getAttributesModels($recordsSum),
        ));
    }
}
