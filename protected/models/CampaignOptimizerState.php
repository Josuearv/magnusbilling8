<?php
/**
 * Modelo para a tabela "pkg_campaign_optimizer_state".
 *
 * MagnusBilling 8
 *
 * @property int $id
 * @property int $id_campaign
 * @property int $id_phonenumber
 * @property string $policy_status
 * @property string $suspension_reason
 * @property string $suspension_expires_at
 * @property int $last_attempt_id
 * @property string $updated_by
 * @property string $created_at
 * @property string $updated_at
 *
 * @property Campaign $idCampaign
 * @property PhoneNumber $idPhonenumber
 */
class CampaignOptimizerState extends Model
{
    protected $_module = 'campaignoptimizerconfig';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_campaign_optimizer_state';
    }

    public function primaryKey()
    {
        return 'id';
    }

    public function rules()
    {
        $rules = [
            ['id_campaign, id_phonenumber', 'required'],
            ['id_campaign, id_phonenumber, last_attempt_id', 'numerical', 'integerOnly' => true],
            ['policy_status', 'in', 'range' => ['ELIGIBLE', 'TEMP_SUSPENDED', 'ADMIN_RESTRICTED']],
            ['suspension_reason', 'length', 'max' => 100],
            ['updated_by', 'length', 'max' => 50],
            ['suspension_expires_at', 'safe'],
        ];
        return $this->getExtraField($rules);
    }

    public function relations()
    {
        return [
            'idCampaign' => [self::BELONGS_TO, 'Campaign', 'id_campaign'],
            'idPhonenumber' => [self::BELONGS_TO, 'PhoneNumber', 'id_phonenumber'],
        ];
    }

    public function getAttributes($names = true)
    {
        $attributes = parent::getAttributes($names);
        $policy = $this->policy_status ?? 'ELIGIBLE';
        $attributes['state'] = $policy === 'TEMP_SUSPENDED'
            ? 'SUSPENDED_TEMP'
            : ($policy === 'ADMIN_RESTRICTED' ? 'RESTRICTED_PERM' : 'ACTIVE');
        $attributes['reason'] = $this->suspension_reason ?? '';
        $attributes['suspended_until'] = $this->suspension_expires_at ?? '';
        $attributes['is_permanent'] = ($policy === 'ADMIN_RESTRICTED') ? 1 : 0;
        $attributes['applied_by'] = $this->updated_by ?? 'SYSTEM';
        $attributes['applied_at'] = $this->created_at ?? '';
        $attributes['canonical_number'] = isset($this->idPhonenumber) && $this->idPhonenumber
            ? (string) $this->idPhonenumber->number
            : '';
        return $attributes;
    }
}
