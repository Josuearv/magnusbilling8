<?php
/**
 * Controlador para la configuración del Optimizador de Campañas.
 *
 * MagnusBilling 8 - Campaign Optimizer
 */
class CampaignoptimizerconfigController extends Controller
{
    public $attributeOrder = 't.id DESC';
    public $extraValues = ['idCampaign' => 'name'];

    public function init()
    {
        $this->instanceModel = new CampaignOptimizerConfig;
        $this->abstractModel = CampaignOptimizerConfig::model();
        $this->titleReport = Yii::t('zii', 'Campaign Optimizer');
        parent::init();
    }

    public function beforeSave($values)
    {
        // 1. Normalizar modo ENFORCING -> ACTIVE
        if (isset($values['mode']) && $values['mode'] === 'ENFORCING') {
            $values['mode'] = 'ACTIVE';
        }

        // 2. Mapear alias de formulario hacia atributos del modelo
        if (isset($values['max_calls_daily_per_recipient'])) {
            $values['max_daily_attempts'] = (int) $values['max_calls_daily_per_recipient'];
        }
        if (isset($values['min_failures_temp_block'])) {
            $values['max_failed_attempts'] = (int) $values['min_failures_temp_block'];
        }
        if (isset($values['schedule_weekday_start'])) {
            $values['weekday_start_time'] = $values['schedule_weekday_start'];
        }
        if (isset($values['schedule_weekday_end'])) {
            $values['weekday_stop_time'] = $values['schedule_weekday_end'];
        }
        if (isset($values['schedule_weekend_start'])) {
            $values['saturday_start_time'] = $values['schedule_weekend_start'];
            $values['sunday_start_time'] = $values['schedule_weekend_start'];
        }
        if (isset($values['schedule_weekend_end'])) {
            $values['saturday_stop_time'] = $values['schedule_weekend_end'];
            $values['sunday_stop_time'] = $values['schedule_weekend_end'];
        }
        if (isset($values['jitter_range_minutes'])) {
            $values['jitter_max_minutes'] = (int) $values['jitter_range_minutes'];
            $values['enable_schedule_jitter'] = ((int) $values['jitter_range_minutes'] > 0) ? 1 : 0;
        }
        if (isset($values['audio_pool_directory'])) {
            $values['audio_source_path'] = $values['audio_pool_directory'];
            if (!empty($values['audio_pool_directory'])) {
                $values['enable_audio_rotation'] = 1;
            }
        }
        if (isset($values['audio_rotation_interval_minutes'])) {
            $values['audio_rotation_interval_min'] = (int) $values['audio_rotation_interval_minutes'];
            if ((int) $values['audio_rotation_interval_minutes'] > 0) {
                $values['enable_audio_rotation'] = 1;
            }
        }

        return parent::beforeSave($values);
    }

    public function afterSave($model, $values)
    {
        if ($this->success && isset($model->id_campaign) && (int) $model->id_campaign > 0) {
            try {
                $idCampaign = (int) $model->id_campaign;
                $sourcePath = !empty($model->audio_source_path) ? $model->audio_source_path : '/opt/';
                CampaignScheduleService::calculateScheduleForDay($idCampaign);
                CampaignAudioService::discoverAndCatalogAudios($idCampaign, $sourcePath);
                CampaignAudioService::rotateCampaignAudio($idCampaign, null, true);
            } catch (Exception $e) {
                Yii::log('Error in CampaignoptimizerconfigController::afterSave: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            }
        }
        return parent::afterSave($model, $values);
    }

    /**
     * Ejecuta inmediatamente la tarea de optimización en segundo plano.
     */
    public function actionRunNow()
    {
        if (!Yii::app()->session['isAdmin']) {
            echo json_encode(['success' => false, 'message' => Yii::t('zii', 'Unauthorized')]);
            exit;
        }

        try {
            $username = Yii::app()->session['username'] ?: 'ADMIN';
            $startTime = microtime(true);

            // 1. Aplicar horarios y variación de inicio
            $scheduleUpdated = CampaignScheduleService::applyDailySchedulesForAllActiveCampaigns();

            // 2. Rotar audios elegibles
            $audiosRotated = CampaignAudioService::rotateAllEligibleCampaigns();

            // 3. Reconciliar llamadas fallidas pre/post AGI
            $reconcileStats = CampaignResultAdapter::reconcileDispatchedAttempts(500);

            $duration = round(microtime(true) - $startTime, 3);

            // Registrar en la bitácora
            $log = new CampaignOptimizerLog();
            $log->execution_type = 'MANUAL_REQUEST';
            $log->requested_by = $username;
            $log->status = 'COMPLETED';
            $log->start_time = date('Y-m-d H:i:s');
            $log->duration_seconds = $duration;
            $log->attempts_reconciled = (int) ($reconcileStats['processed'] ?? 0);
            $log->schedules_updated = count($scheduleUpdated);
            $log->audios_rotated = count($audiosRotated);
            $log->save();

            echo json_encode([
                'success' => true,
                'msg' => Yii::t('zii', 'Optimization executed successfully in {sec}s.', ['{sec}' => $duration]),
                'message' => Yii::t('zii', 'Optimization executed successfully in {sec}s.', ['{sec}' => $duration]),
                'attempts_reconciled' => (int) ($reconcileStats['processed'] ?? 0),
                'suspensions_applied' => 0,
                'schedules_updated' => count($scheduleUpdated),
                'audios_rotated' => count($audiosRotated),
                'stats' => [
                    'duration' => $duration,
                    'schedules_updated' => count($scheduleUpdated),
                    'audios_rotated' => count($audiosRotated),
                    'reconciled' => $reconcileStats,
                ],
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'msg' => Yii::t('zii', 'Error: {err}', ['{err}' => $e->getMessage()]),
                'message' => Yii::t('zii', 'Error: {err}', ['{err}' => $e->getMessage()]),
            ]);
        }
        exit;
    }

    /**
     * Fuerza la rotación inmediata del audio para una campaña seleccionada.
     */
    public function actionRotateAudioNow()
    {
        if (!Yii::app()->session['isAdmin']) {
            echo json_encode(['success' => false, 'msg' => Yii::t('zii', 'Unauthorized'), 'message' => Yii::t('zii', 'Unauthorized')]);
            exit;
        }

        $idCampaign = (int) Yii::app()->request->getParam('id_campaign');
        if ($idCampaign <= 0) {
            $firstConfig = CampaignOptimizerConfig::model()->find('activated = 1 ORDER BY id DESC');
            if ($firstConfig) {
                $idCampaign = (int) $firstConfig->id_campaign;
            }
        }
        if ($idCampaign <= 0) {
            echo json_encode(['success' => false, 'msg' => Yii::t('zii', 'Invalid campaign ID'), 'message' => Yii::t('zii', 'Invalid campaign ID')]);
            exit;
        }

        $result = CampaignAudioService::rotateCampaignAudio($idCampaign, null, true);
        $msg = !empty($result['rotated'])
            ? Yii::t('zii', 'Audio rotated to version {tag}.', ['{tag}' => $result['version_tag'] ?? ''])
            : Yii::t('zii', 'Could not rotate audio: {reason}', ['{reason}' => $result['reason'] ?? 'unknown']);
        echo json_encode([
            'success' => !empty($result['rotated']),
            'version_tag' => $result['version_tag'] ?? '',
            'file_path' => $result['file_name'] ?? '',
            'data' => $result,
            'msg' => $msg,
            'message' => $msg,
        ]);
        exit;
    }
}
