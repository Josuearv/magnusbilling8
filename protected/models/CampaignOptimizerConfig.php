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

    // Alias properties for backward compatibility with ExtJS views and forms
    public $max_calls_daily_per_recipient;
    public $min_failures_temp_block;
    public $schedule_weekday_start;
    public $schedule_weekday_end;
    public $schedule_weekend_start;
    public $schedule_weekend_end;
    public $jitter_range_minutes;
    public $audio_pool_directory;
    public $audio_rotation_interval_minutes;

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
            ['max_calls_daily_per_recipient, min_failures_temp_block, jitter_range_minutes, audio_rotation_interval_minutes', 'numerical', 'integerOnly' => true],
            ['mode', 'in', 'range' => ['OBSERVATION', 'ACTIVE', 'ENFORCING']],
            ['timezone', 'length', 'max' => 50],
            ['weekday_start_time, weekday_stop_time, saturday_start_time, saturday_stop_time, sunday_start_time, sunday_stop_time', 'length', 'max' => 8],
            ['schedule_weekday_start, schedule_weekday_end, schedule_weekend_start, schedule_weekend_end', 'length', 'max' => 8],
            ['audio_source_path, audio_pool_directory', 'length', 'max' => 255],
        ];
        return $this->getExtraField($rules);
    }

    public function relations()
    {
        return [
            'idCampaign' => [self::BELONGS_TO, 'Campaign', 'id_campaign'],
        ];
    }

    protected function beforeValidate()
    {
        // 1. Normalizar modo ENFORCING -> ACTIVE
        if ($this->mode === 'ENFORCING') {
            $this->mode = 'ACTIVE';
        }

        // 2. Mapear alias recibidos del formulario hacia las columnas reales de BD
        if ($this->max_calls_daily_per_recipient !== null && empty($this->max_daily_attempts)) {
            $this->max_daily_attempts = (int) $this->max_calls_daily_per_recipient;
        }
        if ($this->min_failures_temp_block !== null && empty($this->max_failed_attempts)) {
            $this->max_failed_attempts = (int) $this->min_failures_temp_block;
        }
        if (!empty($this->schedule_weekday_start) && empty($this->weekday_start_time)) {
            $this->weekday_start_time = $this->schedule_weekday_start;
        }
        if (!empty($this->schedule_weekday_end) && empty($this->weekday_stop_time)) {
            $this->weekday_stop_time = $this->schedule_weekday_end;
        }
        if (!empty($this->schedule_weekend_start)) {
            if (empty($this->saturday_start_time)) $this->saturday_start_time = $this->schedule_weekend_start;
            if (empty($this->sunday_start_time)) $this->sunday_start_time = $this->schedule_weekend_start;
        }
        if (!empty($this->schedule_weekend_end)) {
            if (empty($this->saturday_stop_time)) $this->saturday_stop_time = $this->schedule_weekend_end;
            if (empty($this->sunday_stop_time)) $this->sunday_stop_time = $this->schedule_weekend_end;
        }
        if ($this->jitter_range_minutes !== null && empty($this->jitter_max_minutes)) {
            $this->jitter_max_minutes = (int) $this->jitter_range_minutes;
        }
        if (!empty($this->audio_pool_directory) && empty($this->audio_source_path)) {
            $this->audio_source_path = $this->audio_pool_directory;
        }
        if ($this->audio_rotation_interval_minutes !== null && empty($this->audio_rotation_interval_min)) {
            $this->audio_rotation_interval_min = (int) $this->audio_rotation_interval_minutes;
        }

        // 3. Garantizar valores predeterminados válidos
        if (empty($this->max_daily_attempts)) $this->max_daily_attempts = 6;
        if (empty($this->max_failed_attempts)) $this->max_failed_attempts = 5;
        if (empty($this->timezone)) $this->timezone = 'America/Bogota';
        if (empty($this->weekday_start_time)) $this->weekday_start_time = '08:30:00';
        if (empty($this->weekday_stop_time)) $this->weekday_stop_time = '20:30:00';
        if (empty($this->saturday_start_time)) $this->saturday_start_time = '09:00:00';
        if (empty($this->saturday_stop_time)) $this->saturday_stop_time = '18:00:00';
        if (empty($this->sunday_start_time)) $this->sunday_start_time = $this->saturday_start_time;
        if (empty($this->sunday_stop_time)) $this->sunday_stop_time = $this->saturday_stop_time;
        if (empty($this->audio_source_path)) $this->audio_source_path = '/opt/';
        if (empty($this->audio_rotation_interval_min)) $this->audio_rotation_interval_min = 15;

        return parent::beforeValidate();
    }

    protected function afterFind()
    {
        parent::afterFind();
        $this->max_calls_daily_per_recipient = (int) ($this->max_daily_attempts ?? 6);
        $this->min_failures_temp_block = (int) ($this->max_failed_attempts ?? 5);
        $this->schedule_weekday_start = $this->weekday_start_time ?? '08:30:00';
        $this->schedule_weekday_end = $this->weekday_stop_time ?? '20:30:00';
        $this->schedule_weekend_start = $this->saturday_start_time ?? '09:00:00';
        $this->schedule_weekend_end = $this->saturday_stop_time ?? '18:00:00';
        $this->jitter_range_minutes = (int) ($this->jitter_max_minutes ?? 30);
        $this->audio_pool_directory = $this->audio_source_path ?? '/opt/';
        $this->audio_rotation_interval_minutes = (int) ($this->audio_rotation_interval_min ?? 15);
    }

    public function getAttributes($names = true)
    {
        $attributes = parent::getAttributes($names);
        $attributes['max_calls_daily_per_recipient'] = (int) ($this->max_daily_attempts ?? 6);
        $attributes['min_failures_temp_block'] = (int) ($this->max_failed_attempts ?? 5);
        $attributes['schedule_weekday_start'] = $this->weekday_start_time ?? '08:30:00';
        $attributes['schedule_weekday_end'] = $this->weekday_stop_time ?? '20:30:00';
        $attributes['schedule_weekend_start'] = $this->saturday_start_time ?? '09:00:00';
        $attributes['schedule_weekend_end'] = $this->saturday_stop_time ?? '18:00:00';
        $attributes['jitter_range_minutes'] = (int) ($this->jitter_max_minutes ?? 30);
        $attributes['audio_pool_directory'] = $this->audio_source_path ?? '/opt/';
        $attributes['audio_rotation_interval_minutes'] = (int) ($this->audio_rotation_interval_min ?? 15);
        return $attributes;
    }
}
