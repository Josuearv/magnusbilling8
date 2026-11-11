<?php

/**
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
 *
 */

class CallChartCommand extends ConsoleCommand
{
    private $totalCalls;
    private $totalUpCalls;
    private $dids;
    private $sips;
    private $sipNames;
    private $didsNumbers;
    private $campaignsCache = [];

    public static function syncOnlineCallsNow($minIntervalSeconds = 2)
    {
        $stampFile = '/tmp/mbilling_callonline_sync.stamp';
        if ($minIntervalSeconds > 0 && is_file($stampFile)) {
            clearstatcache(true, $stampFile);
            $mtime = @filemtime($stampFile);
            if ($mtime !== false && (time() - $mtime) < $minIntervalSeconds) {
                return;
            }
        }
        @touch($stampFile);
        try {
            LoadConfig::syncSystemTimezone();
            $instance        = new self('callchart', null);
            $instance->debug = 0;
            $instance->isDid();
            $instance->isSIPCall();
            $calls = AsteriskAccess::getCoreShowCdrChannels();
            ob_start();
            $instance->user_cdr_show($calls, false);
            ob_end_clean();
        } catch (Exception $e) {
            if (ob_get_level() > 0) {
                @ob_end_clean();
            }
            Yii::log('CallChartCommand::syncOnlineCallsNow error: ' . $e->getMessage(), CLogger::LEVEL_WARNING, 'application');
        }
    }

    public function run($args)
    {
        LoadConfig::syncSystemTimezone();
        $startedMtime = @filemtime(__FILE__);
        $this->debug  = 0;
        $this->isDid();
        $this->isSIPCall();
        for (;;) {
            if (date('i') == 59) {
                break;
            }
            clearstatcache(true, __FILE__);
            if ($startedMtime && @filemtime(__FILE__) !== $startedMtime) {
                break;
            }
            try {
                LoadConfig::syncSystemTimezone();
                Servers::model()->updateAll(['status' => 1], 'status = 2');
                $calls = AsteriskAccess::getCoreShowCdrChannels();
            } catch (Exception $e) {
                sleep(4);
                continue;
            }

            echo "\n\n\n\nSTART ------\n\n";

            echo "use cdr show \n\n";
            $this->user_cdr_show($calls, true);
        }
    }

    private function findCampaignByTokenPart($campaignKey)
    {
        $campaignKey = trim((string) $campaignKey);
        if ($campaignKey === '') {
            return null;
        }
        if (array_key_exists($campaignKey, $this->campaignsCache)) {
            return $this->campaignsCache[$campaignKey];
        }
        $modelCampaign = null;
        if (ctype_digit($campaignKey)) {
            $modelCampaign = Campaign::model()->findByPk((int) $campaignKey);
        }
        if ($modelCampaign === null) {
            $modelCampaign = Campaign::model()->find('name = :key', [':key' => $campaignKey]);
        }
        $this->campaignsCache[$campaignKey] = $modelCampaign;
        return $modelCampaign;
    }

    public function user_cdr_show($calls, $sleepAfter = true)
    {
        @touch('/tmp/mbilling_callonline_sync.stamp');
        $uniqueid  = null;
        $totalUp    = 0;
        $totalCalls = 0;

        $modelUserCallShop = User::model()->count('callshop = 1');
        if ($modelUserCallShop > 0) {
            $callShopIds       = [];
            $modelUserCallShop = User::model()->findAll('callshop = 1');
            foreach ($modelUserCallShop as $key => $value) {
                $callShopIds[] = $value->id;
            }
        }

        $currentMinuteDate            = date('Y-m-d H:i:') . '00';
        $modelCallOnlineChart         = CallOnlineChart::model()->find('date = :key', [':key' => $currentMinuteDate]);
        if ($modelCallOnlineChart === null) {
            $modelCallOnlineChart         = new CallOnlineChart();
            $modelCallOnlineChart->date   = $currentMinuteDate;
            $modelCallOnlineChart->answer = 0;
            $modelCallOnlineChart->total  = 0;
            try {
                $modelCallOnlineChart->save();
            } catch (Exception $e) {
                $modelCallOnlineChart = CallOnlineChart::model()->find('date = :key', [':key' => $currentMinuteDate]);
            }
            $this->totalUpCalls = 0;
            $this->totalCalls   = 0;
        } else {
            $this->totalUpCalls = max((int) $this->totalUpCalls, (int) $modelCallOnlineChart->answer);
            $this->totalCalls   = max((int) $this->totalCalls, (int) $modelCallOnlineChart->total);
        }

        if (is_array($calls) && count($calls) > 0) {

            $sql = [];

            if ($this->debug > 1) {
                print_r($calls);
            }
            $config         = LoadConfig::getConfig();
            $ip_tech_length = $config['global']['ip_tech_length'];
            $sql            = [];
            foreach ($calls as $key => $call) {
                $modelDid = $modelSip = [];
                $type     = '';
                $channel  = trim((string) ($call[0] ?? ''));

                $status       = trim((string) ($call[4] ?? ''));
                $ringDuration = isset($call[11]) && is_numeric(trim((string) $call[11])) ? (int) trim((string) $call[11]) : 0;
                if ((preg_match("/Congestion/", $status) || preg_match("/Busy/", $status)) ||
                    (preg_match('/Ring/', $status) && $ringDuration > 60)
                ) {
                    AsteriskAccess::instance()->hangupRequest($channel);
                    echo "return after hangup channel\n";
                    continue;
                }

                $uniqueid = !empty($call['uniqueid']) ? trim((string) $call['uniqueid']) : null;
                $trunk    = null;
                if (preg_match('#^(?:PJSIP|SIP|IAX2?)/(.+)-[0-9a-f]+(?:;[12])?$#i', $channel, $m)) {
                    $sip_account = $m[1];
                } elseif (preg_match('/^PJSIP\/([^-\s]+)/i', $channel, $m)) {
                    $sip_account = $m[1];
                } else {
                    $sip_account = '';
                }
                $ndiscado = trim((string) ($call[2] ?? ''));

                $modelSip1   = Sip::model()->find('name = :key', [':key' => $sip_account]);
                $accountcode = isset($modelSip1->idUser->username) ? $modelSip1->idUser->username : '';
                $rawCodec    = trim((string) ($call[5] ?? ''));
                $codec       = substr(preg_replace('/[^a-zA-Z0-9]/', '', $rawCodec), 0, 5);
                if ($codec === '') {
                    $codec = 'ulaw';
                }

                $chan = trim((string) ($call[9] ?? ''));
                // The final token is Asterisk's hexadecimal channel suffix.
                // Keep dashes that are part of the configured PJSIP endpoint.
                if (preg_match('#^[^/]+/(.+)-[0-9a-f]+(?:;[12])?$#i', $chan, $m)) {
                    $des_chan = $trunk = $m[1];
                } else {
                    $des_chan = $trunk = '';
                }

                $last_app  = trim((string) ($call[3] ?? ''));
                $cdr       = isset($call[10]) && is_numeric(trim((string) $call[10])) ? (int) trim((string) $call[10]) : 0;
                $originate = $sip_account;

                // Detect MassiveCall (MC!) token across any column or enriched metadata
                $mcToken = isset($call['mc_token']) && is_string($call['mc_token']) ? trim($call['mc_token']) : null;
                if ($mcToken === null) {
                    foreach ($call as $fieldKey => $fieldVal) {
                        if (is_int($fieldKey) && is_string($fieldVal) && preg_match('/^(MC\![^\s|]+)/i', trim($fieldVal), $mcMatch)) {
                            $mcToken = $mcMatch[1];
                            break;
                        }
                    }
                }

                if ($mcToken !== null) {
                    $campaingParts = explode('!', $mcToken);
                    $campaignKey   = isset($campaingParts[1]) ? trim($campaingParts[1]) : '';
                    $phoneIdPart   = isset($campaingParts[2]) ? trim($campaingParts[2]) : '';
                    $modelCampaing = $this->findCampaignByTokenPart($campaignKey);
                    $id_user       = isset($modelCampaing->id_user) ? (int) $modelCampaing->id_user : 'NULL';
                    $campaignLabel = (isset($modelCampaing->name) && strlen($modelCampaing->name) > 0)
                        ? $modelCampaing->name
                        : $campaignKey;
                    $trunk         = 'Campaign ' . $campaignLabel;
                    if (($ndiscado === '' || $ndiscado === 's') && ctype_digit($phoneIdPart)) {
                        $phoneModel = PhoneNumber::model()->findByPk((int) $phoneIdPart);
                        if ($phoneModel && !empty($phoneModel->number)) {
                            $ndiscado = (string) $phoneModel->number;
                        }
                    }
                    if (empty($uniqueid)) {
                        $uniqueid = $phoneIdPart !== '' ? $phoneIdPart : substr(md5($channel), 0, 16);
                    }
                } elseif ($last_app == 'Dial' || $last_app == 'Mbilling') {

                    if ($status == 'Ringing') {
                        echo "return because status is Ringing";
                        continue;
                    }

                    if (preg_match('/^MC\!/', $sip_account)) {
                        echo "torpedo\n";
                        $campaingName  = preg_split('/\!/', $call[1]);
                        $modelCampaing = $this->findCampaignByTokenPart($campaingName[1] ?? '');
                        $id_user       = isset($modelCampaing->id_user) ? $modelCampaing->id_user : 'NULL';
                        $trunk         = "Campaign " . ($modelCampaing ? $modelCampaing->name : ($campaingName[1] ?? ''));
                    } else {

                        //check if is a DID call
                        if (false !== $key = array_search($ndiscado, $this->didsNumbers)) {
                            $modelDid = $this->dids[$key];
                        }
                        //check if is a call to sip account
                        else if (false !== $key = array_search($ndiscado, $this->sipNames)) {
                            $modelSip = $this->sips[$key];
                        }

                        if (isset($modelDid['id'])) {
                            $type    = 'DID';
                            $trunk   = 'DID Call ' . $modelDid['did'];
                            $id_user = isset($modelDid['id_user']) ? $modelDid['id_user'] : null;

                            $didChannel = AsteriskAccess::getCoreShowChannel($channel);
                            if (isset($didChannel['UniqueID'])) {
                                $cdr = time() - intval($didChannel['UniqueID']);
                            }

                            if ($des_chan != '<none>') {
                                if (isset($didChannel['SIPADDHEADER01=P-SipAccount'])) {
                                    $sip_account = $trunk = $didChannel['SIPADDHEADER01=P-SipAccount'];
                                } else if (isset($didChannel['DIALEDPEERNUMBER'])) {
                                    $sip_account = $didChannel['DIALEDPEERNUMBER'];
                                }
                            }
                        } else if (isset($modelSip['id'])) {
                            echo "is a SIP call $ndiscado\n";
                            $type        = 'SIP';
                            $sip_account = $originate;
                            $trunk       = Yii::t('zii', 'SIP Call');
                            $id_user     = $modelSip['id_user'];
                            $is_sip_call = true;
                        } else {

                            //se é autenticado por techprefix

                            //try get user
                            if (preg_match('/^PJSIP\/sipproxy\-/', $channel)) {

                                $sip_account = trim($call[1]);

                                if (false !== $key = array_search($sip_account, $this->sipNames)) {
                                    $modelSip1 = Sip::model()->find('name = :key', [':key' => $this->sips[$key]['name']]);
                                } else {
                                    continue;
                                }
                            } else if (strlen($ndiscado) > 15) {
                                $tech     = substr($ndiscado, 0, $ip_tech_length);
                                $modelSip1 = Sip::model()->find('techprefix = :key AND host != "dynamic" ', [':key' => $tech]);
                            }

                            if (isset($modelSip1->id)) {
                                $modelSip = $modelSip1;
                                $id_user = $modelSip->id_user;
                            }

                            if (! isset($modelSip->name)) {
                                echo " $sip_account $status aqui\n";
                                if ($status == 'Ring') {
                                    $sip_account = $originate;
                                }
                                if (strlen($sip_account) > 3) {
                                    //echo "check per sip_account $originate\n";
                                    if (false !== $key = array_search($originate, $this->sipNames)) {
                                        $modelSip = Sip::model()->find('name = :key', [':key' => $this->sips[$key]['name']]);
                                    }
                                } else if (strlen($accountcode)) {
                                    //echo "check per accountcode $accountcode\n";
                                    $modelSip = Sip::model()->find(
                                        'accountcode = :key',
                                        [
                                            ':key' => $accountcode,
                                        ]
                                    );
                                }

                                if (! isset($modelSip->name)) {

                                    if (preg_match('/^IAX/', strtoupper($channel))) {
                                        $modelSip = Iax::model()->find('name = :key', [':key' => $originate]);
                                    } else {
                                        //check if is via IP from proxy
                                        $callProxy = AsteriskAccess::getCoreShowChannel($channel, null, $call['server']);
                                        $modelSip  = Sip::model()->find('host = :key', [':key' => $callProxy['X-AUTH-IP']]);
                                    }
                                }
                            }



                            $type        = 'pstn';
                            $id_user     = $modelSip['id_user'];
                            $sip_account = $modelSip['name'];
                        }

                        if ($type == '') {
                            echo "not fount the type call \n";
                            continue;
                        }
                    }
                } elseif ($last_app == 'AGI' || $last_app == 'AppDial2') {
                    if (preg_match('/^MC\!/', trim((string) ($call[1] ?? '')))) {

                        //torpedo
                        $campaingName = preg_split('/\!/', trim((string) $call[1]));

                        $modelCampaing = $this->findCampaignByTokenPart($campaingName[1] ?? '');

                        $id_user = isset($modelCampaing->id_user) ? $modelCampaing->id_user : 'NULL';
                        $trunk   = "Campaign " . ($modelCampaing ? $modelCampaing->name : ($campaingName[1] ?? ''));
                    } else {
                        //check if is a DID number
                        //DID call ivr   -> SIP/addphone-000000|           |9999999999    |           |Up|(g729)|<none>             |AGI  |4|5
                        if (false !== $key = array_search($call[2], $this->didsNumbers)) {
                            $modelDid = $this->dids[$key];
                        }
                        if (isset($modelDid['id'])) {

                            $id_user = $modelDid['id_user'];

                            switch ($modelDid['voip_call']) {
                                case 2:
                                    $trunk = $originate . ' IVR';
                                    break;
                                case 3:
                                    $trunk = $originate . ' CallingCard';
                                    break;
                                case 4:
                                    $trunk = $originate . ' portalDeVoz';
                                    break;
                                case 4:
                                    $trunk = $originate . ' CID Callback';
                                    break;
                                case 5:
                                    $trunk = $originate . ' CID Callback';
                                    break;
                                case 6:
                                    $trunk = $originate . ' 0800 Callback';
                                    break;
                                default:
                                    $trunk = $originate . ' DID Call';
                                    break;
                            }
                        } else {
                            $id_user = 'NULL';
                        }
                    }
                } elseif ($last_app == 'Queue') {

                    if (preg_match('/^MC\!/', trim((string) ($call[1] ?? '')))) {
                        //torpedo
                        $campaingName = preg_split('/\!/', trim((string) $call[1]));

                        $modelCampaing = $this->findCampaignByTokenPart($campaingName[1] ?? '');

                        $id_user  = isset($modelCampaing->id_user) ? $modelCampaing->id_user : 'NULL';
                        $trunk    = "Campaign " . ($modelCampaing ? $modelCampaing->name : ($campaingName[1] ?? ''));
                        $uniqueid = $campaingName[2] ?? '';
                    } else {

                        //check if is a DID number
                        if (false !== $key = array_search($call[2], $this->didsNumbers)) {
                            $modelDid = $this->dids[$key];
                        }

                        if (isset($modelDid['id'])) {
                            $id_user = $modelDid['id_user'];
                            $trunk   = $ndiscado . ' Queue ';
                            if ($status == 'Up') {
                                $callQueue = AsteriskAccess::getCoreShowChannel($channel, null, $call['server']);
                                if (isset($callQueue['UniqueID'])) {
                                    $cdr = time() - intval($callQueue['UniqueID']);
                                }
                                if (isset($callQueue['MEMBERNAME'])) {
                                    $sip_account = substr($callQueue['MEMBERNAME'], 4);
                                }
                            }
                        } else {
                            $id_user = 'NULL';
                        }
                    }
                } else {
                    continue;
                }

                if (! is_numeric($id_user)) {

                    echo "continue because not found id_user\n";
                    continue;
                }

                if (! is_numeric($id_user) || ! is_numeric($cdr)) {
                    echo "continue because not foun id_user = $id_user or cdr = $cdr\n";
                    continue;
                }

                if (preg_match("/Up/", $status)) {
                    $totalUp++;
                }
                $totalCalls++;

                if (empty($uniqueid)) {
                    $uniqueid = substr(md5($channel), 0, 16);
                }

                $safeUniqueid   = addslashes(substr((string) $uniqueid, 0, 25));
                $safeSipAccount = addslashes(substr((string) $sip_account, 0, 50));
                $safeIdUser     = (int) $id_user;
                $safeChannel    = addslashes(substr((string) $channel, 0, 50));
                $safeTrunk      = addslashes(substr((string) $trunk, 0, 50));
                $safeNdiscado   = addslashes(substr((string) $ndiscado, 0, 25));
                $safeCodec      = addslashes(substr(preg_replace('/[^a-zA-Z0-9]/', '', (string) $codec), 0, 5));
                if ($safeCodec === '') {
                    $safeCodec = 'ulaw';
                }
                $safeStatus     = addslashes(substr((string) $status, 0, 16));
                $safeCdr        = max(0, (int) $cdr);
                $safeServer     = addslashes(substr((string) ($call['server'] ?? 'localhost'), 0, 50));

                $sql[] = "(NULL,'$safeUniqueid', '$safeSipAccount', $safeIdUser, '$safeChannel', '$safeTrunk', '$safeNdiscado', '$safeCodec', '$safeStatus', '$safeCdr', 'no','no', '$safeServer')";

                if (isset($callShopIds) && is_array($callShopIds)) {
                    if (in_array($id_user, $callShopIds)) {

                        if (! isset($modelSip->id)) {
                            $modelSip = Sip::model()->find('name =:key', [':key' => $sip_account]);

                            if (isset($modelSip->id)) {
                                $modelSip->status         = 3;
                                $modelSip->callshopnumber = $ndiscado;
                                $modelSip->callshoptime   = $cdr;
                                $modelSip->save();
                                $modelSip = null;
                            }
                        }
                    }
                }
            }

            if ($totalUp > $this->totalUpCalls) {
                $this->totalUpCalls = $totalUp;
                echo "totalUp é > total1\n";
            }

            if ($totalCalls > $this->totalCalls) {
                $this->totalCalls = $totalCalls;
            }

            if ($modelCallOnlineChart !== null) {
                $modelCallOnlineChart->answer = $this->totalUpCalls;
                $modelCallOnlineChart->total  = $this->totalCalls;
                $modelCallOnlineChart->save();
            }

            echo 'totalUp = ' . $totalUp . ' -> totalCalls = ' . $totalCalls . "\n";
            echo 'this->totalUpCalls = ' . $this->totalUpCalls . ' -> this->totalCalls = ' . $this->totalCalls . "\n";
            CallOnLine::model()->deleteAll();

            if (count($sql) > 0) {

                $result = CallOnLine::model()->insertCalls($sql);
                if ($this->debug > 1) {
                    print_r($result);
                }
            }
        } else {
            CallOnLine::model()->deleteAll();
        }
        if ($sleepAfter) {
            sleep(4);
        }
    }

    private function isDid()
    {
        $sql               = "SELECT did, id, id_user FROM pkg_did WHERE reserved = 1";
        $this->dids        = Yii::app()->db->createCommand($sql)->queryAll();
        $this->didsNumbers = isset($this->dids[0]) ? array_column($this->dids, 'did') : [];
    }
    private function isSIPCall()
    {
        $sql            = "SELECT name, id_user FROM pkg_sip";
        $this->sips     = Yii::app()->db->createCommand($sql)->queryAll();
        $this->sipNames = array_column($this->sips, 'name');
    }
}
