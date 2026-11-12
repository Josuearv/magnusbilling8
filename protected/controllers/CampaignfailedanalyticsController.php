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

    public function beforeRead($values)
    {
        // Mapear columnas virtuales de ordenamiento a columnas físicas de pkg_campaign_failed_analytics
        if (isset($_GET[$this->nameParamSort]) && is_string($_GET[$this->nameParamSort])) {
            $sortMap = [
                'date_day' => 't.local_date',
                'failure_count' => 't.total_fails',
                'count_no_answer' => 't.fails_noanswer',
                'count_busy' => 't.fails_busy',
                'count_failed' => 't.fails_failed',
                'count_congestion' => 't.fails_congestion',
                'count_chanunavail' => 't.fails_failed',
                'last_hangup_cause' => 't.last_hangupcause',
                'current_state' => 't.status_cache',
                'suspended_until' => 't.last_attempt_at',
                'idCampaignname' => 't.id_campaign',
            ];
            $rawSort = trim($_GET[$this->nameParamSort]);
            if (isset($sortMap[$rawSort])) {
                $_GET[$this->nameParamSort] = $sortMap[$rawSort];
            }
        }

        // Normalizar filtro de búsqueda enviado por ExtJS store.filter({property, value})
        // hacia el formato esperado por BaseController::createCondition ({type, field, comparison, value})
        if (!empty($_GET['filter']) && is_string($_GET['filter'])) {
            $decoded = json_decode($_GET['filter'], true);
            if (is_array($decoded)) {
                $fieldMap = [
                    'date_day' => 'local_date',
                    'failure_count' => 'total_fails',
                    'count_no_answer' => 'fails_noanswer',
                    'count_busy' => 'fails_busy',
                    'count_failed' => 'fails_failed',
                    'count_congestion' => 'fails_congestion',
                    'last_hangup_cause' => 'last_hangupcause',
                    'current_state' => 'status_cache',
                ];
                $normalized = [];
                foreach ($decoded as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $field = $item['field'] ?? $item['property'] ?? '';
                    if (isset($fieldMap[$field])) {
                        $field = $fieldMap[$field];
                    }
                    if ($field === '') {
                        continue;
                    }
                    $normalized[] = [
                        'type' => $item['type'] ?? 'string',
                        'field' => $field,
                        'comparison' => $item['comparison'] ?? 'ct',
                        'value' => $item['value'] ?? '',
                    ];
                }
                $_GET['filter'] = json_encode($normalized);
            }
        }

        return parent::beforeRead($values);
    }

    public function setAttributesModels($attributes, $models)
    {
        if (!is_array($attributes) || empty($attributes)) {
            return $attributes;
        }

        // Pre-cargar fechas de expiración de suspensión únicamente para los contactos de la página actual
        $suspendedMap = [];
        $targetNumbers = [];
        $targetCampaigns = [];

        foreach ($attributes as $row) {
            if (!empty($row['canonical_number'])) {
                $targetNumbers[] = trim((string) $row['canonical_number']);
            }
            if (!empty($row['id_campaign'])) {
                $targetCampaigns[] = (int) $row['id_campaign'];
            }
        }
        $targetNumbers = array_values(array_unique($targetNumbers));
        $targetCampaigns = array_values(array_unique($targetCampaigns));

        if (!empty($targetNumbers) && !empty($targetCampaigns)) {
            try {
                $numPlaceholders = [];
                $campPlaceholders = [];
                $bindings = [];

                foreach ($targetNumbers as $idx => $num) {
                    $key = ':num' . $idx;
                    $numPlaceholders[] = $key;
                    $bindings[$key] = $num;
                }
                foreach ($targetCampaigns as $idx => $camp) {
                    $key = ':camp' . $idx;
                    $campPlaceholders[] = $key;
                    $bindings[$key] = $camp;
                }

                $sql = "SELECT st.id_campaign, p.number AS canonical_number, st.policy_status, st.suspension_expires_at
                        FROM pkg_campaign_optimizer_state st
                        INNER JOIN pkg_phonenumber p ON p.id = st.id_phonenumber
                        WHERE st.id_campaign IN (" . implode(',', $campPlaceholders) . ")
                          AND p.number IN (" . implode(',', $numPlaceholders) . ")";

                $rows = Yii::app()->db->createCommand($sql)->queryAll(true, $bindings);
                foreach ($rows as $r) {
                    $key = (int) $r['id_campaign'] . ':' . trim((string) $r['canonical_number']);
                    $suspendedMap[$key] = $r;
                }
            } catch (Exception $e) {
            }
        }

        foreach ($attributes as $i => $row) {
            $idCampaign = (int) ($row['id_campaign'] ?? 0);
            $canonical = trim((string) ($row['canonical_number'] ?? ''));
            $stateInfo = $suspendedMap[$idCampaign . ':' . $canonical] ?? null;

            $statusCache = $row['status_cache'] ?? 'ACTIVE';
            if ($stateInfo) {
                if ($stateInfo['policy_status'] === 'ADMIN_RESTRICTED') {
                    $statusCache = 'ADMIN_RESTRICTED';
                } elseif ($stateInfo['policy_status'] === 'TEMP_SUSPENDED') {
                    $statusCache = 'TEMP_SUSPENDED';
                }
            }

            $attributes[$i]['date_day'] = $row['local_date'] ?? '';
            $attributes[$i]['failure_count'] = (int) ($row['total_fails'] ?? 0);
            $attributes[$i]['count_no_answer'] = (int) ($row['fails_noanswer'] ?? 0);
            $attributes[$i]['count_busy'] = (int) ($row['fails_busy'] ?? 0);
            $attributes[$i]['count_failed'] = (int) ($row['fails_failed'] ?? 0);
            $attributes[$i]['count_congestion'] = (int) ($row['fails_congestion'] ?? 0);
            $attributes[$i]['count_chanunavail'] = (int) ($row['fails_failed'] ?? 0);
            $attributes[$i]['last_hangup_cause'] = (int) ($row['last_hangupcause'] ?? 19);
            $attributes[$i]['last_sip_code'] = (string) (!empty($row['last_sip_code']) ? $row['last_sip_code'] : '480');
            $attributes[$i]['current_state'] = $statusCache === 'TEMP_SUSPENDED'
                ? 'SUSPENDED_TEMP'
                : ($statusCache === 'ADMIN_RESTRICTED' ? 'RESTRICTED_PERM' : 'ACTIVE');
            $attributes[$i]['suspended_until'] = $stateInfo && !empty($stateInfo['suspension_expires_at'])
                ? (string) $stateInfo['suspension_expires_at']
                : '';
        }

        return $attributes;
    }

    /**
     * Retorna las métricas consolidadas (KPIs) para las tarjetas de la interfaz.
     */
    public function actionGetKpis()
    {
        $this->checkActionAccess([], $this->instanceModel->getModule(), 'canRead');
        if (class_exists('CampaignAnalyticsService')) {
            CampaignAnalyticsService::syncAllAnalyticsAndFailedCdr(60);
        }
        $idCampaign = (int) Yii::app()->request->getParam('id_campaign');
        $date = Yii::app()->request->getParam('date') ?: date('Y-m-d');

        $kpis = CampaignAnalyticsService::getSummaryKpis($idCampaign > 0 ? $idCampaign : null, $date);
        $mappedKpis = [
            'total_failures_today' => (int) ($kpis['total_fails'] ?? $kpis['total_failures_today'] ?? 0),
            'temporary_suspensions' => (int) ($kpis['suspended_today'] ?? $kpis['temp_suspended'] ?? $kpis['temporary_suspensions'] ?? 0),
            'permanent_restrictions' => (int) ($kpis['admin_restricted'] ?? $kpis['permanent_restrictions'] ?? 0),
            'distinct_contacts_affected' => (int) ($kpis['unique_failed_numbers'] ?? $kpis['unique_numbers_failed'] ?? $kpis['distinct_contacts_affected'] ?? 0),
        ];
        echo json_encode(['success' => true, 'data' => $kpis, 'kpis' => $mappedKpis]);
        exit;
    }

    /**
     * Reactiva un número suspendido (restaura elegibilidad en la campaña).
     */
    public function actionReactivate()
    {
        if (!Yii::app()->session['isAdmin']) {
            echo json_encode(['success' => false, 'msg' => Yii::t('zii', 'Unauthorized'), 'message' => Yii::t('zii', 'Unauthorized')]);
            exit;
        }

        $id = (int) Yii::app()->request->getParam('id');
        $number = Yii::app()->request->getParam('number');
        $idCampaign = (int) Yii::app()->request->getParam('id_campaign');

        if ($id > 0 && empty($number)) {
            $analyticsRow = CampaignFailedAnalytics::model()->findByPk($id);
            if ($analyticsRow) {
                $number = $analyticsRow->canonical_number;
                $idCampaign = (int) $analyticsRow->id_campaign;
            }
        }

        if (empty($number)) {
            echo json_encode(['success' => false, 'msg' => Yii::t('zii', 'Phone number is required'), 'message' => Yii::t('zii', 'Phone number is required')]);
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

            $msg = Yii::t('zii', 'Number {num} reactivated successfully.', ['{num}' => $number]);
            echo json_encode([
                'success' => true,
                'msg' => $msg,
                'message' => $msg,
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'msg' => $e->getMessage(), 'message' => $e->getMessage()]);
        }
        exit;
    }

    /**
     * Envía un número de forma permanente a la tabla de restricción (pkg_restrict_phone).
     */
    public function actionRestrict()
    {
        if (!Yii::app()->session['isAdmin']) {
            echo json_encode(['success' => false, 'msg' => Yii::t('zii', 'Unauthorized'), 'message' => Yii::t('zii', 'Unauthorized')]);
            exit;
        }

        $id = (int) Yii::app()->request->getParam('id');
        $number = Yii::app()->request->getParam('number');
        $idCampaign = (int) Yii::app()->request->getParam('id_campaign');

        if ($id > 0 && empty($number)) {
            $analyticsRow = CampaignFailedAnalytics::model()->findByPk($id);
            if ($analyticsRow) {
                $number = $analyticsRow->canonical_number;
                $idCampaign = (int) $analyticsRow->id_campaign;
            }
        }

        if (empty($number)) {
            echo json_encode(['success' => false, 'msg' => Yii::t('zii', 'Phone number is required'), 'message' => Yii::t('zii', 'Phone number is required')]);
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

            $msg = Yii::t('zii', 'Number {num} added to permanent restriction list.', ['{num}' => $number]);
            echo json_encode([
                'success' => true,
                'msg' => $msg,
                'message' => $msg,
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'msg' => $e->getMessage(), 'message' => $e->getMessage()]);
        }
        exit;
    }
}
