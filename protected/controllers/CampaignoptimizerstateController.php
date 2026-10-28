<?php
/**
 * Controlador para los estados de suspensión y políticas del Optimizador de Campañas.
 *
 * MagnusBilling 8 - Campaign Optimizer
 */
class CampaignoptimizerstateController extends Controller
{
    public $attributeOrder = 't.id DESC';
    public $extraValues = ['idCampaign' => 'name', 'idPhonenumber' => 'number'];

    public function init()
    {
        $this->instanceModel = new CampaignOptimizerState;
        $this->abstractModel = CampaignOptimizerState::model();
        $this->titleReport = Yii::t('zii', 'Optimizer Suspensions');
        parent::init();
    }
}
