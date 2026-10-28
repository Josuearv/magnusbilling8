<?php
/**
 * Modelo para a tabela "pkg_campaign_optimizer_log".
 *
 * MagnusBilling 8
 *
 * @property int $id
 * @property string $execution_type
 * @property string $requested_by
 * @property string $status
 * @property string $start_time
 * @property float $duration_seconds
 * @property int $attempts_reserved
 * @property int $attempts_reconciled
 * @property int $suspensions_applied
 * @property int $suspensions_expired
 * @property int $schedules_updated
 * @property int $audios_rotated
 * @property string $error_details
 */
class CampaignOptimizerLog extends Model
{
    protected $_module = 'campaignoptimizerlog';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_campaign_optimizer_log';
    }

    public function primaryKey()
    {
        return 'id';
    }

    public function rules()
    {
        $rules = [
            ['attempts_reserved, attempts_reconciled, suspensions_applied, suspensions_expired, schedules_updated, audios_rotated', 'numerical', 'integerOnly' => true],
            ['duration_seconds', 'numerical'],
            ['execution_type', 'in', 'range' => ['CRON', 'MANUAL_REQUEST']],
            ['status', 'in', 'range' => ['PENDING', 'RUNNING', 'COMPLETED', 'FAILED']],
            ['requested_by', 'length', 'max' => 50],
            ['error_details, start_time', 'safe'],
        ];
        return $this->getExtraField($rules);
    }

    public function relations()
    {
        return [];
    }
}
