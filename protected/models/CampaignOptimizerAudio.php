<?php
/**
 * Modelo para a tabela "pkg_campaign_optimizer_audio".
 *
 * MagnusBilling 8
 *
 * @property int $id
 * @property int $id_campaign
 * @property string $version_tag
 * @property string $file_path
 * @property string $sha256_checksum
 * @property int $file_size_bytes
 * @property string $format
 * @property int $is_active
 * @property string $validated_at
 *
 * @property Campaign $idCampaign
 */
class CampaignOptimizerAudio extends Model
{
    protected $_module = 'campaignoptimizeraudio';

    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    public function tableName()
    {
        return 'pkg_campaign_optimizer_audio';
    }

    public function primaryKey()
    {
        return 'id';
    }

    public function rules()
    {
        $rules = [
            ['id_campaign, version_tag, file_path, sha256_checksum, file_size_bytes, format', 'required'],
            ['id_campaign, file_size_bytes, is_active', 'numerical', 'integerOnly' => true],
            ['version_tag', 'length', 'max' => 50],
            ['file_path', 'length', 'max' => 255],
            ['sha256_checksum', 'length', 'max' => 64],
            ['format', 'length', 'max' => 10],
            ['validated_at', 'safe'],
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
