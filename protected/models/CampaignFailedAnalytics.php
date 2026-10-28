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
    protected $_module = 'campaignfailedanalytics';

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
}
