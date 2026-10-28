<?php
/**
 * Controlador para el catálogo y versionado de audios del Optimizador de Campañas.
 *
 * MagnusBilling 8 - Campaign Optimizer
 */
class CampaignoptimizeraudioController extends Controller
{
    public $attributeOrder = 't.id DESC';
    public $extraValues = ['idCampaign' => 'name'];

    public function init()
    {
        $this->instanceModel = new CampaignOptimizerAudio;
        $this->abstractModel = CampaignOptimizerAudio::model();
        $this->titleReport = Yii::t('zii', 'Optimizer Audios');
        parent::init();
    }
}
