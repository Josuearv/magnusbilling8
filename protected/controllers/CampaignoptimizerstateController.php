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

    public function beforeRead($values)
    {
        if (class_exists('CampaignAnalyticsService')) {
            CampaignAnalyticsService::syncAllAnalyticsAndFailedCdr(3);
        }

        if (isset($_GET[$this->nameParamSort]) && is_string($_GET[$this->nameParamSort])) {
            $sortMap = [
                'idCampaignname' => 't.id_campaign',
                'canonical_number' => 't.id_phonenumber',
                'state' => 't.policy_status',
                'reason' => 't.suspension_reason',
                'suspended_until' => 't.suspension_expires_at',
                'is_permanent' => 't.policy_status',
                'applied_by' => 't.updated_by',
                'applied_at' => 't.created_at',
            ];
            $rawSort = trim($_GET[$this->nameParamSort]);
            if (isset($sortMap[$rawSort])) {
                $_GET[$this->nameParamSort] = $sortMap[$rawSort];
            }
        }

        return parent::beforeRead($values);
    }

    public function setAttributesModels($attributes, $models)
    {
        if (!is_array($attributes) || empty($attributes)) {
            return $attributes;
        }

        foreach ($attributes as $i => $row) {
            $policy = $row['policy_status'] ?? 'ELIGIBLE';
            $attributes[$i]['state'] = $policy === 'TEMP_SUSPENDED'
                ? 'SUSPENDED_TEMP'
                : ($policy === 'ADMIN_RESTRICTED' ? 'RESTRICTED_PERM' : 'ACTIVE');
            $attributes[$i]['reason'] = $row['suspension_reason'] ?? '';
            $attributes[$i]['suspended_until'] = $row['suspension_expires_at'] ?? '';
            $attributes[$i]['is_permanent'] = ($policy === 'ADMIN_RESTRICTED') ? 1 : 0;
            $attributes[$i]['applied_by'] = $row['updated_by'] ?? 'SYSTEM';
            $attributes[$i]['applied_at'] = $row['created_at'] ?? '';
            if (empty($attributes[$i]['canonical_number']) && !empty($row['idPhonenumbernumber'])) {
                $attributes[$i]['canonical_number'] = (string) $row['idPhonenumbernumber'];
            }
        }

        return $attributes;
    }
}
