<?php
/**
 * Modelo para a tabela "pkg_campaign_optimizer_daily".
 *
 * MagnusBilling 8
 *
 * @property int $id
 * @property int $id_user_owner
 * @property string $canonical_number
 * @property string $local_date
 * @property int $reserved_count
 * @property int $initiated_count
 * @property int $failed_count
 * @property int $answered_count
 * @property string $created_at
 * @property string $updated_at
 *
 * @property User $idUserOwner
 */
class CampaignOptimizerDaily extends Model
{
    protected $_module = 'campaignoptimizerdaily';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_campaign_optimizer_daily';
    }

    public function primaryKey()
    {
        return 'id';
    }

    public function rules()
    {
        $rules = [
            ['id_user_owner, canonical_number, local_date', 'required'],
            ['id_user_owner, reserved_count, initiated_count, failed_count, answered_count', 'numerical', 'integerOnly' => true],
            ['canonical_number', 'length', 'max' => 30],
            ['local_date', 'length', 'max' => 10],
        ];
        return $this->getExtraField($rules);
    }

    public function relations()
    {
        return [
            'idUserOwner' => [self::BELONGS_TO, 'User', 'id_user_owner'],
        ];
    }
}
