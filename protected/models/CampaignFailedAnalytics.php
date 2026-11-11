<?php
/**
 * Modelo para a tabela "pkg_campaign_failed_analytics".
 *
 * MagnusBilling 8
 *
 * @property int $id
 * @property int $id_campaign
 * @property string $canonical_number
 * @property string $local_date
 * @property int $total_fails
 * @property int $fails_noanswer
 * @property int $fails_busy
 * @property int $fails_failed
 * @property int $fails_congestion
 * @property int $last_hangupcause
 * @property string $last_sip_code
 * @property string $last_attempt_at
 * @property string $status_cache
 *
 * @property Campaign $idCampaign
 */
class CampaignFailedAnalytics extends Model
{
    protected $_module = 'campaignoptimizerconfig';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_campaign_failed_analytics';
    }

    public function primaryKey()
    {
        return 'id';
    }

    public function rules()
    {
        $rules = [
            ['id_campaign, canonical_number, local_date, last_attempt_at', 'required'],
            ['id_campaign, total_fails, fails_noanswer, fails_busy, fails_failed, fails_congestion, last_hangupcause', 'numerical', 'integerOnly' => true],
            ['canonical_number', 'length', 'max' => 30],
            ['local_date', 'length', 'max' => 10],
            ['last_sip_code', 'length', 'max' => 20],
            ['status_cache', 'in', 'range' => ['TEMP_SUSPENDED', 'ACTIVE', 'ADMIN_RESTRICTED']],
            ['last_attempt_at', 'safe'],
        ];
        return $this->getExtraField($rules);
    }

    public function relations()
    {
        return [
            'idCampaign' => [self::BELONGS_TO, 'Campaign', 'id_campaign'],
        ];
    }

    public function getAttributes($names = true)
    {
        $attributes = parent::getAttributes($names);
        $attributes['date_day'] = $this->local_date ?? '';
        $attributes['failure_count'] = (int) ($this->total_fails ?? 0);
        $attributes['count_no_answer'] = (int) ($this->fails_noanswer ?? 0);
        $attributes['count_busy'] = (int) ($this->fails_busy ?? 0);
        $attributes['count_failed'] = (int) ($this->fails_failed ?? 0);
        $attributes['count_congestion'] = (int) ($this->fails_congestion ?? 0);
        $attributes['count_chanunavail'] = 0;
        $attributes['last_hangup_cause'] = (int) ($this->last_hangupcause ?? 0);
        $statusCache = $this->status_cache ?? 'ACTIVE';
        $attributes['current_state'] = $statusCache === 'TEMP_SUSPENDED'
            ? 'SUSPENDED_TEMP'
            : ($statusCache === 'ADMIN_RESTRICTED' ? 'RESTRICTED_PERM' : 'ACTIVE');
        $attributes['suspended_until'] = '';
        return $attributes;
    }
}
