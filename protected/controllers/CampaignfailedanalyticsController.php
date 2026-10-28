<?php
/**
 * Controlador para la analítica forense de llamadas fallidas.
 *
 * MagnusBilling 8 - Campaign Optimizer
 */
class CampaignfailedanalyticsController extends Controller
{
    public $attributeOrder = 't.last_attempt_at DESC';
    public $extraValues = ['idCampaign' => 'name'];

    public function init()
    {
        $this->instanceModel = new CampaignFailedAnalytics;
        $this->abstractModel = CampaignFailedAnalytics::model();
        $this->titleReport = Yii::t('zii', 'Failed Numbers Analytics');
        parent::init();
    }

    /**
     * Retorna las métricas consolidadas (KPIs) para las tarjetas de la interfaz.
     */
    public function actionGetKpis()
    {
        $idCampaign = (int) Yii::app()->request->getParam('id_campaign');
        $date = Yii::app()->request->getParam('date') ?: date('Y-m-d');

        $kpis = CampaignAnalyticsService::getSummaryKpis($idCampaign > 0 ? $idCampaign : null, $date);
        echo json_encode(['success' => true, 'data' => $kpis]);
        exit;
    }

    /**
     * Reactiva un número suspendido (restaura elegibilidad en la campaña).
     */
    public function actionReactivate()
    {
        if (!Yii::app()->session['isAdmin']) {
            echo json_encode(['success' => false, 'message' => Yii::t('zii', 'Unauthorized')]);
            exit;
        }

        $number = Yii::app()->request->getParam('number');
        $idCampaign = (int) Yii::app()->request->getParam('id_campaign');

        if (empty($number)) {
            echo json_encode(['success' => false, 'message' => Yii::t('zii', 'Phone number is required')]);
            exit;
        }

        $canonical = CampaignAttemptService::normalizeCanonicalNumber($number);

        try {
            // 1. Restaurar en pkg_campaign_optimizer_state
            if ($idCampaign > 0) {
                Yii::app()->db->createCommand(
                    "UPDATE pkg_campaign_optimizer_state
                     SET policy_status = 'ELIGIBLE', suspension_reason = 'MANUAL_REACTIVATION', updated_by = :u
                     WHERE id_campaign = :c AND id_phonenumber IN (
                         SELECT id FROM pkg_phonenumber WHERE number = :num OR number = :canon
                     )"
                )->bindValues([
                    ':u' => Yii::app()->session['username'] ?: 'ADMIN',
                    ':c' => $idCampaign,
                    ':num' => $number,
                    ':canon' => $canonical,
                ])->execute();
            }

            // 2. Reactivar en pkg_phonenumber
            Yii::app()->db->createCommand(
                "UPDATE pkg_phonenumber SET status = 1, try = 0
                 WHERE number = :num OR number = :canon"
            )->bindValues([':num' => $number, ':canon' => $canonical])->execute();

            // 3. Actualizar caché en pkg_campaign_failed_analytics
            Yii::app()->db->createCommand(
                "UPDATE pkg_campaign_failed_analytics SET status_cache = 'ACTIVE'
                 WHERE canonical_number = :canon"
            )->bindValue(':canon', $canonical, PDO::PARAM_STR)->execute();

            echo json_encode([
                'success' => true,
                'message' => Yii::t('zii', 'Number {num} reactivated successfully.', ['{num}' => $number]),
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * Envía un número de forma permanente a la tabla de restricción (pkg_restrict_phone).
     */
    public function actionRestrict()
    {
        if (!Yii::app()->session['isAdmin']) {
            echo json_encode(['success' => false, 'message' => Yii::t('zii', 'Unauthorized')]);
            exit;
        }

        $number = Yii::app()->request->getParam('number');
        $idCampaign = (int) Yii::app()->request->getParam('id_campaign');

        if (empty($number)) {
            echo json_encode(['success' => false, 'message' => Yii::t('zii', 'Phone number is required')]);
            exit;
        }

        $canonical = CampaignAttemptService::normalizeCanonicalNumber($number);

        try {
            // 1. Insertar en pkg_restrict_phone si no existe
            $exists = (int) Yii::app()->db->createCommand(
                "SELECT COUNT(*) FROM pkg_restrict_phone WHERE number = :canon AND id_user = 1"
            )->bindValue(':canon', $canonical, PDO::PARAM_STR)->queryScalar();

            if (!$exists) {
                Yii::app()->db->createCommand(
                    "INSERT INTO pkg_restrict_phone (number, id_user, direction) VALUES (:canon, 1, 3)"
                )->bindValue(':canon', $canonical, PDO::PARAM_STR)->execute();
            }

            // 2. Desactivar en pkg_phonenumber (status = 4: restringido)
            Yii::app()->db->createCommand(
                "UPDATE pkg_phonenumber SET status = 4 WHERE number = :num OR number = :canon"
            )->bindValues([':num' => $number, ':canon' => $canonical])->execute();

            // 3. Marcar en pkg_campaign_optimizer_state
            if ($idCampaign > 0) {
                Yii::app()->db->createCommand(
                    "UPDATE pkg_campaign_optimizer_state
                     SET policy_status = 'ADMIN_RESTRICTED', suspension_reason = 'MANUAL_RESTRICTION', updated_by = :u
                     WHERE id_campaign = :c AND id_phonenumber IN (
                         SELECT id FROM pkg_phonenumber WHERE number = :num OR number = :canon
                     )"
                )->bindValues([
                    ':u' => Yii::app()->session['username'] ?: 'ADMIN',
                    ':c' => $idCampaign,
                    ':num' => $number,
                    ':canon' => $canonical,
                ])->execute();
            }

            // 4. Actualizar caché en analítica
            Yii::app()->db->createCommand(
                "UPDATE pkg_campaign_failed_analytics SET status_cache = 'ADMIN_RESTRICTED'
                 WHERE canonical_number = :canon"
            )->bindValue(':canon', $canonical, PDO::PARAM_STR)->execute();

            echo json_encode([
                'success' => true,
                'message' => Yii::t('zii', 'Number {num} added to permanent restriction list.', ['{num}' => $number]),
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}
