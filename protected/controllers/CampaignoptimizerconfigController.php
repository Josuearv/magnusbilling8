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
                'message' => Yii::t('zii', 'Optimization executed successfully in {sec}s.', ['{sec}' => $duration]),
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
            echo json_encode(['success' => false, 'message' => Yii::t('zii', 'Unauthorized')]);
            exit;
        }

        $idCampaign = (int) Yii::app()->request->getParam('id_campaign');
        if ($idCampaign <= 0) {
            echo json_encode(['success' => false, 'message' => Yii::t('zii', 'Invalid campaign ID')]);
            exit;
        }

        $result = CampaignAudioService::rotateCampaignAudio($idCampaign, null, true);
        echo json_encode([
            'success' => !empty($result['rotated']),
            'data' => $result,
            'message' => !empty($result['rotated'])
                ? Yii::t('zii', 'Audio rotated to version {tag}.', ['{tag}' => $result['version_tag'] ?? ''])
                : Yii::t('zii', 'Could not rotate audio: {reason}', ['{reason}' => $result['reason'] ?? 'unknown']),
        ]);
        exit;
    }
}
