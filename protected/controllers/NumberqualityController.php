<?php
/**
 * Controller for CDR & ASR Number Quality Analytics ("Analitica de Numeros").
 *
 * Admin-only module enforcing ACL + CSRF + strict PDO parameterization.
 * All heavy aggregation happens asynchronously via NumberQualityCommand; web actions
 * only read pre-aggregated summary tables and bounded per-number CDR samples.
 */
class NumberqualityController extends Controller
{
    public $attributeOrder = 't.quality_score ASC, t.attempts DESC';
    public $extraValues    = array(
        'idUser'     => 'username',
        'idCampaign' => 'name',
        'idTrunk'    => 'trunkcode',
    );
    public $fieldsInvisibleOperator = array('id_trunk', 'idTrunktrunkcode', 'buycost_total');

    public function init()
    {
        $this->instanceModel = new NumberQualityScore;
        $this->abstractModel = NumberQualityScore::model();
        $this->titleReport   = Yii::t('zii', 'Number Quality Analytics');
        parent::init();
    }

    protected function beforeAction($action)
    {
        if (! parent::beforeAction($action)) {
            return false;
        }

        $actionId = strtolower($action->getId());
        if (in_array($actionId, array('create', 'update', 'destroy', 'save', 'importfromcsv'), true)) {
            $this->denyJson(Yii::t('zii', 'Method not allowed.'), 405);
            return false;
        }

        if (empty(Yii::app()->session['isAdmin']) || ! AccessManager::getInstance('numberquality')->canRead()) {
            $this->denyJson(Yii::t('zii', 'Access denied.'), 403);
            return false;
        }

        return true;
    }

    /**
     * Extends standard Controller filter parsing with explicit quick filter params
     * (quality_label, id_campaign, id_trunk, query).
     *
     * @param array $filter
     * @return array
     */
    public function applyFilterToLimitedAdmin()
    {
        // Admin-only controller; preserve parent signature cleanly.
    }

    public function actionRead($asJson = true, $condition = null)
    {
        $parts = array();

        $label = trim((string) Yii::app()->request->getParam('quality_label', ''));
        if ($label !== '' && preg_match('/^[A-Z0-9_]{2,32}$/', $label)) {
            if ($label === 'BAD_ALL') {
                $parts[] = "t.quality_label IN ('BAD_SHORT_5S','BAD_UNDER_3MIN','RIGID_CUT_PATTERN','UNREACHABLE_FAIL_PATTERN')";
            } else {
                $parts[] = 't.quality_label = :nq_label';
                $this->paramsFilter[':nq_label'] = $label;
            }
        }

        $idCampaign = (int) Yii::app()->request->getParam('id_campaign', 0);
        if ($idCampaign > 0) {
            $parts[] = 't.id_campaign = :nq_campaign';
            $this->paramsFilter[':nq_campaign'] = $idCampaign;
        }

        $idTrunk = (int) Yii::app()->request->getParam('id_trunk', 0);
        if ($idTrunk > 0) {
            $parts[] = 't.id_trunk = :nq_trunk';
            $this->paramsFilter[':nq_trunk'] = $idTrunk;
        }

        $minAttempts = (int) Yii::app()->request->getParam('min_attempts', 0);
        if ($minAttempts > 0) {
            $parts[] = 't.attempts >= :nq_min_att';
            $this->paramsFilter[':nq_min_att'] = $minAttempts;
        }

        $query = trim((string) Yii::app()->request->getParam('query', ''));
        if ($query !== '') {
            $cleanDigits = preg_replace('/[^0-9]/', '', $query);
            $parts[] = '(t.canonical_number LIKE :nq_q1
                         OR t.reason_summary LIKE :nq_q2
                         OR t.dominant_fail_cause LIKE :nq_q2
                         OR EXISTS (SELECT 1 FROM pkg_campaign nc WHERE nc.id = t.id_campaign AND nc.name LIKE :nq_q2)
                         OR EXISTS (SELECT 1 FROM pkg_trunk nt WHERE nt.id = t.id_trunk AND nt.trunkcode LIKE :nq_q2))';
            $this->paramsFilter[':nq_q1'] = '%' . ($cleanDigits !== '' ? $cleanDigits : $query) . '%';
            $this->paramsFilter[':nq_q2'] = '%' . $query . '%';
        }

        if (! empty($parts)) {
            $extraCond = implode(' AND ', $parts);
            $condition = $condition ? "({$condition}) AND ({$extraCond})" : $extraCond;
        }

        return parent::actionRead($asJson, $condition);
    }

    /**
     * Returns KPI totals, label breakdown, and worst trunk health strip.
     */
    public function actionSummary()
    {
        header('Content-Type: application/json; charset=utf-8');
        $idCampaign = (int) Yii::app()->request->getParam('id_campaign', 0);
        $idTrunk    = (int) Yii::app()->request->getParam('id_trunk', 0);

        $data = NumberQualityService::getSummary(
            $idCampaign > 0 ? $idCampaign : null,
            $idTrunk > 0 ? $idTrunk : null
        );

        echo json_encode(array(
            'success' => true,
            'kpis'    => $data['kpis'],
            'trunks'  => $data['trunks'],
        ));
        Yii::app()->end();
    }

    /**
     * Returns duration histogram and recent answered/failed calls for a selected destination.
     */
    public function actionDetail()
    {
        header('Content-Type: application/json; charset=utf-8');
        $number     = trim((string) Yii::app()->request->getParam('canonical_number', ''));
        $idCampaign = (int) Yii::app()->request->getParam('id_campaign', 0);
        $idTrunk    = (int) Yii::app()->request->getParam('id_trunk', 0);

        if ($number === '') {
            $this->denyJson(Yii::t('zii', 'Phone number is required.'), 422);
            return;
        }

        $detail = NumberQualityService::getNumberDetail($number, $idCampaign, $idTrunk);
        echo json_encode(array(
            'success'   => true,
            'score'     => $detail['score'],
            'histogram' => $detail['histogram'],
            'calls'     => $detail['calls'],
        ));
        Yii::app()->end();
    }

    /**
     * Triggers an incremental sync + score recompute on explicit admin request (POST + canUpdate).
     */
    public function actionRecalculate()
    {
        header('Content-Type: application/json; charset=utf-8');
        if (! Yii::app()->request->isPostRequest || ! AccessManager::getInstance('numberquality')->canUpdate()) {
            $this->denyJson(Yii::t('zii', 'Permission denied.'), 403);
            return;
        }

        $windowDays = (int) Yii::app()->request->getPost('window_days', NumberQualityService::DEFAULT_WINDOW_DAYS);
        $backfill   = (int) Yii::app()->request->getPost('backfill', 0) === 1;

        $res = NumberQualityService::syncAndScore($windowDays, NumberQualityService::DEFAULT_MIN_ATTEMPTS, $backfill);
        echo json_encode($res);
        Yii::app()->end();
    }

    /**
     * Applies an operator-approved action on a number (no automatic blocking):
     *   - TEMP_SUSPEND: pause in campaign optimizer / phonenumber for N hours (default 24h)
     *   - RESTRICT_PERM: add to pkg_restrict_phone + mark phonenumber restricted (status=4)
     *   - REACTIVATE: remove from pkg_restrict_phone + reactivate phonenumber (status=1) & optimizer state
     */
    public function actionApplySuggestion()
    {
        header('Content-Type: application/json; charset=utf-8');
        if (! Yii::app()->request->isPostRequest || ! AccessManager::getInstance('numberquality')->canUpdate()) {
            $this->denyJson(Yii::t('zii', 'Permission denied.'), 403);
            return;
        }

        $scoreId     = (int) Yii::app()->request->getPost('id', 0);
        $actionType  = strtoupper(trim((string) Yii::app()->request->getPost('action_type', '')));
        $hours       = max(1, min(720, (int) Yii::app()->request->getPost('hours', 24)));
        $number      = trim((string) Yii::app()->request->getPost('canonical_number', ''));
        $idCampaign  = (int) Yii::app()->request->getPost('id_campaign', 0);
        $idUser      = (int) Yii::app()->request->getPost('id_user', 0);

        if ($scoreId > 0) {
            $row = NumberQualityScore::model()->findByPk($scoreId);
            if ($row) {
                $number     = $row->canonical_number;
                $idCampaign = (int) $row->id_campaign;
                $idUser     = (int) $row->id_user;
            }
        }

        $canonical = CampaignAttemptService::normalizeCanonicalNumber($number);
        $digits    = preg_replace('/[^0-9]/', '', (string) $canonical);
        if ($digits === '' || ! in_array($actionType, array('TEMP_SUSPEND', 'RESTRICT_PERM', 'REACTIVATE'), true)) {
            $this->denyJson(Yii::t('zii', 'Invalid parameters for action.'), 422);
            return;
        }

        $actor = ! empty(Yii::app()->session['username']) ? (string) Yii::app()->session['username'] : 'ADMIN';
        $db    = Yii::app()->db;
        $tx    = $db->beginTransaction();

        try {
            $msg = '';
            $plusDigits = '+' . $digits;

            if ($actionType === 'TEMP_SUSPEND') {
                if ($idCampaign > 0) {
                    $db->createCommand(
                        "UPDATE pkg_campaign_optimizer_state
                         SET policy_status = 'TEMP_SUSPENDED',
                             suspension_reason = :reason,
                             suspension_expires_at = DATE_ADD(NOW(), INTERVAL :hours HOUR),
                             updated_by = :actor
                         WHERE id_campaign = :camp
                           AND id_phonenumber IN (
                               SELECT id FROM pkg_phonenumber WHERE number IN (:n1, :n2)
                           )"
                    )
                        ->bindValue(':reason', 'NQ_MANUAL_SUSPEND_' . $hours . 'H', PDO::PARAM_STR)
                        ->bindValue(':hours', $hours, PDO::PARAM_INT)
                        ->bindValue(':actor', $actor, PDO::PARAM_STR)
                        ->bindValue(':camp', $idCampaign, PDO::PARAM_INT)
                        ->bindValue(':n1', $digits, PDO::PARAM_STR)
                        ->bindValue(':n2', $plusDigits, PDO::PARAM_STR)
                        ->execute();
                }

                $db->createCommand(
                    "UPDATE pkg_phonenumber SET status = 0 WHERE number IN (:n1, :n2)"
                )
                    ->bindValue(':n1', $digits, PDO::PARAM_STR)
                    ->bindValue(':n2', $plusDigits, PDO::PARAM_STR)
                    ->execute();

                $msg = Yii::t('zii', 'Number {num} paused/suspended for {h} hours.', array('{num}' => $digits, '{h}' => $hours));
            } elseif ($actionType === 'RESTRICT_PERM') {
                $restrictUserId = $idUser > 0 ? $idUser : 1;
                $exists = (int) $db->createCommand(
                    "SELECT COUNT(*) FROM pkg_restrict_phone WHERE number = :num AND id_user = :uid"
                )
                    ->bindValue(':num', $digits, PDO::PARAM_STR)
                    ->bindValue(':uid', $restrictUserId, PDO::PARAM_INT)
                    ->queryScalar();

                if (! $exists) {
                    $db->createCommand(
                        "INSERT INTO pkg_restrict_phone (number, id_user, direction) VALUES (:num, :uid, 3)"
                    )
                        ->bindValue(':num', $digits, PDO::PARAM_STR)
                        ->bindValue(':uid', $restrictUserId, PDO::PARAM_INT)
                        ->execute();
                }

                $db->createCommand(
                    "UPDATE pkg_phonenumber SET status = 4 WHERE number IN (:n1, :n2)"
                )
                    ->bindValue(':n1', $digits, PDO::PARAM_STR)
                    ->bindValue(':n2', $plusDigits, PDO::PARAM_STR)
                    ->execute();

                if ($idCampaign > 0) {
                    $db->createCommand(
                        "UPDATE pkg_campaign_optimizer_state
                         SET policy_status = 'ADMIN_RESTRICTED',
                             suspension_reason = 'NQ_MANUAL_RESTRICT',
                             updated_by = :actor
                         WHERE id_campaign = :camp
                           AND id_phonenumber IN (
                               SELECT id FROM pkg_phonenumber WHERE number IN (:n1, :n2)
                           )"
                    )
                        ->bindValue(':actor', $actor, PDO::PARAM_STR)
                        ->bindValue(':camp', $idCampaign, PDO::PARAM_INT)
                        ->bindValue(':n1', $digits, PDO::PARAM_STR)
                        ->bindValue(':n2', $plusDigits, PDO::PARAM_STR)
                        ->execute();
                }

                $msg = Yii::t('zii', 'Number {num} restricted permanently.', array('{num}' => $digits));
            } else {
                // REACTIVATE
                $db->createCommand(
                    "DELETE FROM pkg_restrict_phone WHERE number IN (:n1, :n2)"
                )
                    ->bindValue(':n1', $digits, PDO::PARAM_STR)
                    ->bindValue(':n2', $plusDigits, PDO::PARAM_STR)
                    ->execute();

                $db->createCommand(
                    "UPDATE pkg_phonenumber SET status = 1, try = 0 WHERE number IN (:n1, :n2)"
                )
                    ->bindValue(':n1', $digits, PDO::PARAM_STR)
                    ->bindValue(':n2', $plusDigits, PDO::PARAM_STR)
                    ->execute();

                if ($idCampaign > 0) {
                    $db->createCommand(
                        "UPDATE pkg_campaign_optimizer_state
                         SET policy_status = 'ELIGIBLE',
                             suspension_reason = 'NQ_MANUAL_REACTIVATE',
                             suspension_expires_at = NULL,
                             updated_by = :actor
                         WHERE id_campaign = :camp
                           AND id_phonenumber IN (
                               SELECT id FROM pkg_phonenumber WHERE number IN (:n1, :n2)
                           )"
                    )
                        ->bindValue(':actor', $actor, PDO::PARAM_STR)
                        ->bindValue(':camp', $idCampaign, PDO::PARAM_INT)
                        ->bindValue(':n1', $digits, PDO::PARAM_STR)
                        ->bindValue(':n2', $plusDigits, PDO::PARAM_STR)
                        ->execute();
                }

                $msg = Yii::t('zii', 'Number {num} reactivated.', array('{num}' => $digits));
            }

            $tx->commit();
            echo json_encode(array('success' => true, 'msg' => $msg));
        } catch (Exception $e) {
            $tx->rollback();
            Yii::log('NumberqualityController applySuggestion error: ' . $e->getMessage(), CLogger::LEVEL_ERROR);
            $this->denyJson($e->getMessage(), 500);
        }

        Yii::app()->end();
    }

    private function denyJson($message, $statusCode = 403)
    {
        if (! headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(array('success' => false, 'msg' => $message));
    }
}
