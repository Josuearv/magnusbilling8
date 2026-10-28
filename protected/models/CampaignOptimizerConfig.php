<?php
/**
 * Modelo para a tabela "pkg_campaign_optimizer_config".
 *
 * MagnusBilling 8
 *
 * @property int $id
 * @property int $id_campaign
 * @property int $activated
 * @property string $mode
 * @property string $timezone
 * @property int $max_daily_attempts
 * @property int $max_failed_attempts
 * @property int $enable_schedule_jitter
 * @property int $jitter_max_minutes
 * @property string $weekday_start_time
 * @property string $weekday_stop_time
 * @property string $saturday_start_time
 * @property string $saturday_stop_time
 * @property string $sunday_start_time
 * @property string $sunday_stop_time
 * @property int $weekday_frequency
 * @property int $weekday_max_frequency
 * @property int $weekend_frequency
 * @property int $weekend_max_frequency
 * @property int $enable_audio_rotation
 * @property int $audio_rotation_interval_min
 * @property string $audio_source_path
 * @property string $created_at
 * @property string $updated_at
 *
 * @property Campaign $idCampaign
 */
class CampaignOptimizerConfig extends Model
{
    protected $_module = 'campaignoptimizerconfig';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_campaign_optimizer_config';
    }

    public function primaryKey()
    {
        return 'id';
    }

    public function rules()
    {
        $rules = [
            ['id_campaign', 'required'],
            ['id_campaign, activated, max_daily_attempts, max_failed_attempts, enable_schedule_jitter, jitter_max_minutes, weekday_frequency, weekday_max_frequency, weekend_frequency, weekend_max_frequency, enable_audio_rotation, audio_rotation_interval_min', 'numerical', 'integerOnly' => true],
            ['mode', 'in', 'range' => ['OBSERVATION', 'ACTIVE']],
            ['timezone', 'length', 'max' => 50],
            ['weekday_start_time, weekday_stop_time, saturday_start_time, saturday_stop_time, sunday_start_time, sunday_stop_time', 'length', 'max' => 8],
            ['audio_source_path', 'length', 'max' => 255],
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
