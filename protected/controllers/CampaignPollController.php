<?php

/**
 * Acoes do modulo "CampaignPoll".
 *
 * =======================================
 * ###################################
 * MagnusBilling
 *
 * @package MagnusBilling
 * @author Adilson Leffa Magnus.
 * @copyright Copyright (C) 2005 - 2023 MagnusSolution. All rights reserved.
 * ###################################
 *
 * This software is released under the terms of the GNU Lesser General Public License v2.1
 * A copy of which is available from http://www.gnu.org/copyleft/lesser.html
 *
 * Please submit bug reports, patches, etc to https://github.com/magnusbilling/mbilling/issues
 * =======================================
 * Magnusbilling.com <info@magnusbilling.com>
 * 28/10/2012
 */

class CampaignPollController extends Controller
{
    public $attributeOrder = 't.id';
    public $extraValues    = ['idCampaign' => 'name', 'idUser' => 'username'];

    private $uploaddir;
    public $fieldsFkReport = [
        'id_campaign' => [
            'table'       => 'pkg_campaign',
            'pk'          => 'id',
            'fieldReport' => 'name',
        ],
    ];

    public function init()
    {
        $this->uploaddir     = $this->magnusFilesDirectory . 'sounds/';
        $this->instanceModel = new CampaignPoll;
        $this->abstractModel = CampaignPoll::model();
        $this->titleReport   = Yii::t('zii', 'Poll');
        parent::init();
    }

    public function beforeSave($values)
    {
        if ($this->isNewRecord || isset($values['id_campaign'])) {
            $modelCampaign     = Campaign::model()->findByPk((int) $values['id_campaign']);
            $values['id_user'] = $modelCampaign ? $modelCampaign->id_user : null;
        }

        if (isset($_FILES["arq_audio"]) && strlen($_FILES["arq_audio"]["name"]) > 1) {
            $data                = explode('.', $_FILES["arq_audio"]["name"]);
            $typefile            = array_pop($data);
            $values['arq_audio'] = "idPoll_" . (isset($values['id']) && $values['id'] > 0 ? $values['id'] : '') . '.' . $typefile;
        } elseif (! $this->isNewRecord) {
            unset($values['arq_audio']);
        }
        return $values;
    }

    public function afterSave($model, $values)
    {
        if (isset($_FILES["arq_audio"]) && strlen($_FILES["arq_audio"]["name"]) > 1) {
            if (file_exists($this->uploaddir . 'idPoll_' . $model->id . '.wav')) {
                @unlink($this->uploaddir . 'idPoll_' . $model->id . '.wav');
            }
            if (file_exists($this->uploaddir . 'idPoll_' . $model->id . '.gsm')) {
                @unlink($this->uploaddir . 'idPoll_' . $model->id . '.gsm');
            }
            $typefile   = Util::validExtension($_FILES['arq_audio']['tmp_name'], $_FILES["arq_audio"]["name"], ['gsm', 'wav']);
            $audioName  = 'idPoll_' . $model->id . '.' . $typefile;
            $uploadfile = $this->uploaddir . $audioName;
            if (move_uploaded_file($_FILES["arq_audio"]["tmp_name"], $uploadfile)) {
                @chmod($uploadfile, 0666);
            }
            if ($model->arq_audio !== $audioName) {
                $model->arq_audio = $audioName;
                Yii::app()->db->createCommand("UPDATE pkg_campaign_poll SET arq_audio = :audio WHERE id = :id")
                    ->bindValue(':audio', $audioName, PDO::PARAM_STR)
                    ->bindValue(':id', $model->id, PDO::PARAM_INT)
                    ->execute();
            }
        }

        return;
    }

    public function afterDestroy($values)
    {
        $namePk = $this->abstractModel->primaryKey();
        if (array_key_exists(0, $values)) {
            foreach ($values as $value) {
                $id = $value[$namePk];

                //deleta os audios da enquete

                $uploadfile = $this->uploaddir . 'idPoll_' . $id . '.gsm';
                if (file_exists($uploadfile)) {
                    unlink($uploadfile);
                }
            }
        } else {
            $id = $values[$namePk];
            //deleta os audios da enquete

            $uploadfile = $this->uploaddir . 'idPoll_' . $id . '.gsm';
            if (file_exists($uploadfile)) {
                unlink($uploadfile);
            }
        }
    }
}
