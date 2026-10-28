<?php
/**
 * Modelo para a tabela "pkg_campaign_optimizer_attempt".
 *
 * MagnusBilling 8
 *
 * @property int $id
 * @property string $attempt_uuid
 * @property int $id_campaign
 * @property int $id_phonenumber
 * @property int $id_user_owner
 * @property string $original_number
 * @property string $canonical_number
 * @property int $id_server
 * @property string $uniqueid
 * @property int $id_trunk
 * @property string $status
 * @property int $hangupcause
 * @property string $sip_code
 * @property string $evidence_source
 * @property int $audio_version_id
 * @property string $reserved_at
 * @property string $dispatched_at
 * @property string $finalized_at
 *
 * @property Campaign $idCampaign
 * @property PhoneNumber $idPhonenumber
 * @property User $idUserOwner
 */
class CampaignOptimizerAttempt extends Model
{
    protected $_module = 'campaignoptimizerattempt';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_campaign_optimizer_attempt';
    }

    public function primaryKey()
    {
        return 'id';
    }

    public function rules()
    {
        $rules = [
            ['attempt_uuid, id_campaign, id_phonenumber, id_user_owner, original_number, canonical_number', 'required'],
            ['id_campaign, id_phonenumber, id_user_owner, id_server, id_trunk, hangupcause, audio_version_id', 'numerical', 'integerOnly' => true],
            ['attempt_uuid', 'length', 'max' => 36],
            ['original_number, canonical_number', 'length', 'max' => 30],
            ['uniqueid', 'length', 'max' => 40],
            ['sip_code', 'length', 'max' => 20],
            ['evidence_source', 'length', 'max' => 50],
            ['status', 'in', 'range' => ['RESERVED', 'DISPATCHED', 'ANSWERED', 'NOANSWER', 'BUSY', 'FAILED', 'CANCEL', 'CONGESTION', 'UNKNOWN', 'TIMED_OUT']],
            ['reserved_at, dispatched_at, finalized_at', 'safe'],
        ];
        return $this->getExtraField($rules);
    }

    public function relations()
    {
        return [
            'idCampaign' => [self::BELONGS_TO, 'Campaign', 'id_campaign'],
            'idPhonenumber' => [self::BELONGS_TO, 'PhoneNumber', 'id_phonenumber'],
            'idUserOwner' => [self::BELONGS_TO, 'User', 'id_user_owner'],
        ];
    }
}
