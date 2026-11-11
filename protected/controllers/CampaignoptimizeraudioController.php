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

    public function beforeRead($values)
    {
        try {
            $configs = CampaignOptimizerConfig::model()->findAll('activated = 1');
            foreach ($configs as $config) {
                $sourcePath = !empty($config->audio_source_path) ? $config->audio_source_path : '/opt/';
                CampaignAudioService::discoverAndCatalogAudios((int) $config->id_campaign, $sourcePath);
                if (CampaignAudioService::getActiveAudioVersionId((int) $config->id_campaign) === null) {
                    CampaignAudioService::rotateCampaignAudio((int) $config->id_campaign, null, true);
                }
            }
        } catch (Exception $e) {
            Yii::log('Error in CampaignoptimizeraudioController::beforeRead: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
        }
        return parent::beforeRead($values);
    }
}
