<?php
/**
 * Modelo para a tabela "pkg_campaign_optimizer_schedule".
 *
 * MagnusBilling 8
 *
 * @property int $id
 * @property int $id_campaign
 * @property string $schedule_date
 * @property string $start_time_base
 * @property string $stop_time_base
 * @property int $jitter_minutes_applied
 * @property string $effective_start_time
 * @property string $effective_stop_time
 * @property int $target_frequency
 * @property int $max_frequency
 * @property string $created_at
 *
 * @property Campaign $idCampaign
 */
class CampaignOptimizerSchedule extends Model
{
    protected $_module = 'campaignoptimizerschedule';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_campaign_optimizer_schedule';
    }

    public function primaryKey()
    {
        return 'id';
    }

    public function rules()
    {
        $rules = [
            ['id_campaign, schedule_date, start_time_base, stop_time_base, effective_start_time, effective_stop_time, target_frequency, max_frequency', 'required'],
            ['id_campaign, jitter_minutes_applied, target_frequency, max_frequency', 'numerical', 'integerOnly' => true],
            ['schedule_date', 'length', 'max' => 10],
            ['start_time_base, stop_time_base, effective_start_time, effective_stop_time', 'length', 'max' => 8],
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
