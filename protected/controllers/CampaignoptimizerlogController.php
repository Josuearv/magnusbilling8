<?php
/**
 * Controlador para la bitácora y auditoría del Optimizador de Campañas.
 *
 * MagnusBilling 8 - Campaign Optimizer
 */
class CampaignoptimizerlogController extends Controller
{
    public $attributeOrder = 't.id DESC';

    public function init()
    {
        $this->instanceModel = new CampaignOptimizerLog;
        $this->abstractModel = CampaignOptimizerLog::model();
        $this->titleReport = Yii::t('zii', 'Optimizer Execution Log');
        parent::init();
    }
}
