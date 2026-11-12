<?php
/**
 * Model for table "pkg_cdr_number_quality_score".
 *
 * @property int $id
 * @property string $canonical_number
 * @property int $id_user
 * @property int $id_campaign
 * @property int $id_trunk
 * @property int $window_days
 * @property int $attempts
 * @property int $answered
 * @property int $failed
 * @property int $cnt_short_5s
 * @property int $cnt_6_30s
 * @property int $cnt_31_179s
 * @property int $cnt_mid_short
 * @property int $cnt_long_180s
 * @property float $asr_pct
 * @property float $sdr_5s_pct
 * @property float $mid_short_pct
 * @property float $acd_sec
 * @property float $duration_stddev
 * @property float $duration_cv
 * @property string $dominant_fail_cause
 * @property float $dominant_fail_pct
 * @property int $last_hangupcause
 * @property float $buycost_total
 * @property float $quality_score
 * @property float $confidence
 * @property string $quality_label
 * @property string $reason_summary
 * @property int $is_trunk_suspect
 * @property string|null $last_call_at
 * @property string $updated_at
 *
 * @property User $idUser
 * @property Campaign $idCampaign
 * @property Trunk $idTrunk
 */
class NumberQualityScore extends Model
{
    protected $_module = 'numberquality';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_cdr_number_quality_score';
    }

    public function primaryKey()
    {
        return 'id';
    }

    public function rules()
    {
        $rules = array(
            array('canonical_number, updated_at', 'required'),
            array('id_user, id_campaign, id_trunk, window_days, attempts, answered, failed, cnt_short_5s, cnt_6_30s, cnt_31_179s, cnt_mid_short, cnt_long_180s, last_hangupcause, is_trunk_suspect', 'numerical', 'integerOnly' => true),
            array('asr_pct, sdr_5s_pct, mid_short_pct, acd_sec, duration_stddev, duration_cv, dominant_fail_pct, buycost_total, quality_score, confidence', 'numerical'),
            array('canonical_number, dominant_fail_cause, quality_label', 'length', 'max' => 32),
            array('reason_summary', 'length', 'max' => 255),
            array('last_call_at, updated_at', 'safe'),
        );
        return $this->getExtraField($rules);
    }

    public function relations()
    {
        return array(
            'idUser'     => array(self::BELONGS_TO, 'User', 'id_user'),
            'idCampaign' => array(self::BELONGS_TO, 'Campaign', 'id_campaign'),
            'idTrunk'    => array(self::BELONGS_TO, 'Trunk', 'id_trunk'),
        );
    }
}
