<?php
/**
 * =======================================
 * ###################################
 * MagnusBilling
 *
 * @package MagnusBilling
 * @author Adilson Leffa Magnus.
 * @copyright Copyright (C) 2005 - 2016 MagnusBilling. All rights reserved.
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
class Magnus
{
    public $config;
    public $agiconfig;
    public $idconfig = 1;
    public $agentUsername;
    public $CallerID;
    public $channel;
    public $uniqueid;
    public $accountcode;
    public $dnid;
    public $extension;
    public $statchannel;
    public $destination;
    public $credit;
    public $id_plan;
    public $active;
    public $currency = 'usd';
    public $mode     = '';
    public $timeout;
    public $tech;
    public $prefix;
    public $username;
    public $typepaid          = 0;
    public $removeinterprefix = 1;
    public $restriction       = 1;
    public $redial;
    public $enableexpire;
    public $expirationdate;
    public $expiredays;
    public $creationdate;
    public $creditlimit = 0;
    public $id_user;
    public $countryCode;
    public $add_credit;
    public $dialstatus_rev_list;
    public $callshop;
    public $id_plan_agent;
    public $id_offer;
    public $record_call;
    public $mix_monitor_format = 'gsm';
    public $prefix_local;
    public $id_agent;
    public $portabilidade = false;
    public $play_audio    = false;
    public $language;
    public $sip_account;
    public $user_calllimit = 0;
    public $modelUser      = array();
    public $modelSip       = array();
    public $modelUserAgent = array();
    public $demo           = false;
    public $voicemail;
    public $magnusFilesDirectory = '/usr/local/src/magnus/';

    public function __construct()
    {
        $this->dialstatus_rev_list = Magnus::getDialStatus_Revert_List();
    }

    public function init()
    {
        $this->destination = '';
    }

    /*  load_conf */
    public function load_conf(&$agi, $config = null, $webui = 0, $idconfig = 1, $optconfig = array())
    {
        $this->idconfig = 1;
        $modelConfig    = Configuration::model()->findAll();

        foreach ($modelConfig as $conf) {
            $this->config[$conf->config_group_title][$conf->config_key] = $conf->config_value;
        }

        foreach ($modelConfig as $var => $val) {
            $this->config["agi-conf$idconfig"]->$var = $val;
        }

        $this->agiconfig = $this->config["agi-conf$idconfig"];

        return true;
    }

    public function get_agi_request_parameter($agi)
    {
        $this->accountcode = $agi->request['agi_accountcode'];
        $this->dnid        = $agi->request['agi_extension'];

        $this->CallerID = $agi->request['agi_callerid'];
        $this->channel  = $agi->request['agi_channel'];
        $this->uniqueid = $agi->request['agi_uniqueid'];

        $this->lastapp = isset($agi->request['agi_lastapp']) ? $agi->request['agi_lastapp'] : null;

        $stat_channel      = $agi->channel_status($this->channel);
        $this->statchannel = $stat_channel["data"];

        if (preg_match('/Local/', $this->channel) && strlen($this->accountcode) < 4) {
            $modelSip          = Sip::model()->find('name = :dnid', array(':dnid' => $this->dnid));
            $this->accountcode = $modelSip->accountcode;
        }

        $ramal             = explode("-", $this->channel);
        $ramal             = explode("/", $ramal[0]);
        $this->sip_account = $ramal[1];

        $pos_lt = strpos($this->CallerID, '<');
        $pos_gt = strpos($this->CallerID, '>');
        if (($pos_lt !== false) && ($pos_gt !== false)) {
            $len_gt         = $pos_gt - $pos_lt - 1;
            $this->CallerID = substr($this->CallerID, $pos_lt + 1, $len_gt);
        }
        $msg = ' get_agi_request_parameter = ' . $this->statchannel . ' ; ' . $this->CallerID
        . ' ; ' . $this->channel . ' ; ' . $this->uniqueid . ' ; '
        . $this->accountcode . ' ; ' . $this->dnid;
        $agi->verbose($msg, 15);
    }

    public function calculation_price($buyrate, $duration, $initblock, $increment)
    {

        $ratecallduration = $duration;
        $buyratecost      = 0;
        if ($ratecallduration < $initblock) {
            $ratecallduration = $initblock;
        }

        if (($increment > 0) && ($ratecallduration > $initblock)) {
            $mod_sec = $ratecallduration % $increment;
            if ($mod_sec > 0) {
                $ratecallduration += ($increment - $mod_sec);
            }

        }
        $ratecost = ($ratecallduration / 60) * $buyrate;
        $ratecost = $ratecost;
        return $ratecost;

    }
    //hangup($agi);
    public function hangup(&$agi)
    {
        $agi->verbose('Hangup Call ' . $this->destination . ' Username ' . $this->username, 6);
        $agi->hangup();
        exit;
    }

    public static function getDialStatus_Revert_List()
    {
        $dialstatus_rev_list                = array();
        $dialstatus_rev_list["ANSWER"]      = 1;
        $dialstatus_rev_list["BUSY"]        = 2;
        $dialstatus_rev_list["NOANSWER"]    = 3;
        $dialstatus_rev_list["CANCEL"]      = 4;
        $dialstatus_rev_list["CONGESTION"]  = 5;
        $dialstatus_rev_list["CHANUNAVAIL"] = 6;
        $dialstatus_rev_list["DONTCALL"]    = 7;
        $dialstatus_rev_list["TORTURE"]     = 8;
        $dialstatus_rev_list["INVALIDARGS"] = 9;
        return $dialstatus_rev_list;
    }

    public function checkNumber($agi, &$Calc, $try_num, $call2did = false)
    {
        $res               = 0;
        $prompt_enter_dest = 'prepaid-enter-dest';
        $msg               = "use_dnid:" . $this->agiconfig['use_dnid'] . " && len_dnid:(" . strlen($this->
                dnid) . " || len_exten:" . strlen($this->extension) . " ) && (try_num:$try_num)";
        $agi->verbose($msg, 15);

        if (($this->agiconfig['use_dnid'] == 1) && $try_num == 0) {
            if ($this->extension == 's') {
                $this->destination = $this->dnid;
            } else {
                $this->destination = $this->extension;
            }
            $agi->verbose("USE_DNID DESTINATION -> " . $this->destination, 10);
        } else {
            $agi->verbose('Request the destination number' . $prompt_enter_dest, 25);
            $res_dtmf = $agi->get_data($prompt_enter_dest, 6000, 20);
            $agi->verbose("RES DTMF -> " . $res_dtmf["result"], 10);
            $this->destination = $res_dtmf["result"];
            $this->dnid        = $res_dtmf["result"];
        }

        $this->destination = preg_replace('/\#|\*|\-|\.|\(|\)/', '', $this->destination);
        $this->dnid        = preg_replace('/\-|\.|\(|\)/', '', $this->dnid);

        if ($this->destination <= 0) {
            $prompt = "prepaid-invalid-digits";
            $agi->verbose($prompt, 3);
            if (is_numeric($this->destination)) {
                $agi->answer();
            }

            $agi->stream_file($prompt, '#');
            $this->hangup($agi);
        }

        if ($this->dnid == 150) {
            $agi->verbose("SAY BALANCE : $this->credit ", 10);
            $this->sayBalance($agi, $this->credit);

            $prompt = "prepaid-final";
            $agi->verbose($prompt, 10);
            $agi->stream_file($prompt, '#');
            $this->hangup($agi);
        }
        if ($this->dnid == 160) {
            $modelCall = Call::model()->find(array(
                'condition' => 'id_user = :key',
                'params'    => array(':key' => $this->id_user),
                'order'     => 'starttime DESC',
            )
            );
            if (count($modelCall)) {
                $agi->verbose("SAY PRICE LAST CALL : " . $modelCall->sessionbill, 1);
                $this->sayLastCall($agi, $result[0]['sessionbill'], $modelCall->sessiontime);
            }
            $agi->stream_file('prepaid-final', '#');

            $this->hangup($agi);
        }

        if ($this->removeinterprefix) {
            $this->destination = substr($this->destination, 0, 2) == '00' ? substr($this->destination, 2) : $this->destination;
            $agi->verbose("REMOVE INTERNACIONAL PREFIX -> " . $this->destination, 10);
        }

        $this->number_translation($agi, $this->destination);

        $this->checkRestrictPhoneNumber($agi);

        $this->save_redial_number($agi, $this->destination);
        $data = date("d-m-y");
        $agi->verbose("USERNAME=" . $this->username . " DESTINATION=" . $this->destination . " PLAN=" . $this->id_plan . " CREDIT=" . $this->credit, 6);

        if ($this->play_audio == 0) {
            $check_credit = $this->credit + $this->creditlimit;
            if ($check_credit <= 0) {
                $agi->verbose("SEND :: congestion Credit < 0", 3);
                $agi->execute((congestion), Congestion);
                $this->hangup($agi);
            }
        }

        $agi->destination = $this->destination;
        /*call funtion for search rates*/
        $SearchTariff = new SearchTariff();

        $resfindrate = $SearchTariff->find($this->destination, $this->id_plan, $this->id_user, $agi);

        $Calc->tariffObj    = $resfindrate;
        $Calc->number_trunk = count($resfindrate);

        if ($resfindrate == 0) {
            $agi->verbose("The number $this->destination, no exist in the plan $this->id_plan", 3);

            $this->executePlayAudio("prepaid-dest-unreachable", $agi);

            return false;
        } else {
            $agi->verbose("NUMBER TARIFF FOUND -> " . $Calc->number_trunk, 10);
        }

        /* CHECKING THE TIMEOUT*/
        $res_all_calcultimeout = $Calc->calculateAllTimeout($this, $this->credit, $agi);

        if ($this->id_agent > 1) {
            $agi->verbose("Check reseller credit -> " . $this->id_agent . ' credit ' . $this->credit, 20);
            $check_agent_credit = UserCreditManager::checkGlobalCredit($this->id_agent);
        }

        if (!$res_all_calcultimeout || (isset($check_agent_credit) && $check_agent_credit == false)) {
            $this->executePlayAudio("prepaid-no-enough-credit", $agi);
            return false;
        }

        /* calculate timeout*/
        $this->timeout = $Calc->tariffObj[0]['timeout'];
        $timeout       = $this->timeout;
        $agi->verbose("timeout ->> $timeout", 15);
        $minimal_time_charge = $Calc->tariffObj[0]['minimal_time_charge'];
        $this->say_time_call($agi, $timeout, $Calc->tariffObj[0]['rateinitial']);

        return true;
    }

    public function say_time_call($agi, $timeout, $rate = 0)
    {
        $minutes = intval($timeout / 60);
        $seconds = $timeout % 60;

        $agi->verbose("TIMEOUT->" . $timeout . " : minutes=$minutes - seconds=$seconds", 6);

        if ($this->agiconfig['say_rateinitial'] == 1) {
            $this->sayRate($agi, $rate);
        }

        if ($this->agiconfig['say_timetocall'] == 1) {
            $agi->stream_file('prepaid-you-have', '#');
            if ($minutes > 0) {
                if ($minutes == 1) {
                    $agi->say_number($minutes);
                    $agi->stream_file('prepaid-minute', '#');
                } else {
                    $agi->say_number($minutes);
                    $agi->stream_file('prepaid-minutes', '#');
                }
            }
            if ($seconds > 0) {
                if ($minutes > 0) {
                    $agi->stream_file('vm-and', '#');
                }

                if ($seconds == 1) {
                    $agi->say_number($seconds);
                    $agi->stream_file('prepaid-second', '#');
                } else {
                    $agi->stream_file('prepaid-seconds', '#');
                }
            }
        }
    }

    public function sayBalance($agi, $credit, $fromvoucher = 0)
    {

        $mycur = 1;

        $credit_cur = $credit / $mycur;

        list($units, $cents) = pre_split('/\[\.\]/', sprintf('%01.2f', $credit_cur));

        $agi->verbose("[BEFORE: $credit_cur SPRINTF : " . sprintf('%01.2f', $credit_cur) . "]", 10);

        if ($credit > 1) {
            $unit_audio = "credit";
        } else {
            $unit_audio = "credits";
        }

        $cents_audio = "prepaid-cents";

        switch ($cents_audio) {
            case 'prepaid-pence':
                $cent_audio = 'prepaid-penny';
                break;
            default:
                $cent_audio = substr($cents_audio, 0, -1);
        }

        /* say 'you have x dollars and x cents'*/
        if ($fromvoucher != 1) {
            $agi->stream_file('prepaid-you-have', '#');
        } else {
            $agi->stream_file('prepaid-account_refill', '#');
        }

        if ($units == 0 && $cents == 0) {
            $agi->say_number(0);
            $agi->stream_file($unit_audio, '#');
        } else {
            if ($units > 1) {
                $agi->say_number($units);
                $agi->stream_file($unit_audio, '#');
            } else {
                $agi->say_number($units);
                $agi->stream_file($unit_audio, '#');
            }

            if ($units > 0 && $cents > 0) {
                $agi->stream_file('vm-and', '#');
            }
            if ($cents > 0) {
                $agi->say_number($cents);
                if ($cents > 1) {
                    $agi->stream_file($cents_audio, '#');
                } else {
                    $agi->stream_file($cent_audio, '#');
                }

            }
        }
    }

    public function sayLastCall($agi, $rate, $time = 0)
    {
        $rate  = preg_replace("/\./", "z", $rate);
        $array = str_split($rate);
        $agi->stream_file('prepaid-cost-call', '#');
        for ($i = 0; $i < strlen($rate); $i++) {
            if ($array[$i] == 'z') {
                $agi->stream_file('prepaid-point', '#');
                $cents = true;
            } else {
                $agi->say_number($array[$i]);
            }

        }
        if ($cents) {
            $agi->stream_file('prepaid-cents', '#');
        }

        if ($time > 0) {
            $agi->say_number($time);
            $agi->stream_file('prepaid-seconds', '#');
        }
    }

    public function sayRate($agi, $rate)
    {
        $rate = 0.008;

        $mycur      = 1;
        $credit_cur = $rate / $mycur;

        list($units, $cents) = pre_split('/\[\.\]/', sprintf('%01.3f', $credit_cur));

        if (substr($cents, 2) > 0) {
            $point = substr($cents, 2);
        }

        if (strlen($cents) > 2) {
            $cents = substr($cents, 0, 2);
        }

        if ($units == '') {
            $units = 0;
        }

        if ($cents == '') {
            $cents = 0;
        }

        if ($point == '') {
            $point = 0;
        } elseif (strlen($cents) == 1) {
            $cents .= '0';
        }

        if ($rate > 1) {
            $unit_audio = "credit";
        } else {
            $unit_audio = "credits";
        }

        $cent_audio  = 'prepaid-cent';
        $cents_audio = 'prepaid-cents';

        /* say 'the cost of the call is '*/
        $agi->stream_file('prepaid-cost-call', '#');
        $this->agiconfig['play_rate_cents_if_lower_one'] = 1;
        if ($units == 0 && $cents == 0 && $this->agiconfig['play_rate_cents_if_lower_one'] == 0 && !($this->agiconfig['play_rate_cents_if_lower_one'] == 1 && $point == 0)) {
            $agi->say_number(0);
            $agi->stream_file($unit_audio, '#');
        } else {
            if ($units >= 1) {
                $agi->say_number($units);
                $agi->stream_file($unit_audio, '#');
            } elseif ($this->agiconfig['play_rate_cents_if_lower_one'] == 0) {
                $agi->say_number($units);
                $agi->stream_file($unit_audio, '#');
            }

            if ($units > 0 && $cents > 0) {
                $agi->stream_file('vm-and', '#');
            }
            if ($cents > 0 || ($point > 0 && $this->agiconfig['play_rate_cents_if_lower_one'] == 1)) {

                sleep(2);
                $agi->say_number($cents);
                if ($point > 0) {
                    $agi->stream_file('prepaid-point', '#');
                    $agi->say_number($point);
                }
                if ($cents > 1) {
                    $agi->stream_file($cents_audio, '#');
                } else {
                    $agi->stream_file($cent_audio, '#');
                }
            }
        }
    }

    public function checkDaysPackage($agi, $startday, $billingtype)
    {
        if ($billingtype == 0) {
            /* PROCESSING FOR MONTHLY*/
            /* if > last day of the month*/
            if ($startday > date("t")) {
                $startday = date("t");
            }

            if ($startday <= 0) {
                $startday = 1;
            }

            /* Check if the startday is upper that the current day*/
            if ($startday > date("j")) {
                $year_month = date('Y-m', strtotime('-1 month'));
            } else {
                $year_month = date('Y-m');
            }

            $yearmonth   = sprintf("%s-%02d", $year_month, $startday);
            $CLAUSE_DATE = " TIMESTAMP(date_consumption) >= TIMESTAMP('$yearmonth')";
        } else {

            /* PROCESSING FOR WEEKLY*/
            $startday  = $startday % 7;
            $dayofweek = date("w");
            /* Numeric representation of the day of the week 0 (for Sunday) through 6 (for Saturday)*/
            if ($dayofweek == 0) {
                $dayofweek = 7;
            }

            if ($dayofweek < $startday) {
                $dayofweek = $dayofweek + 7;
            }

            $diffday     = $dayofweek - $startday;
            $CLAUSE_DATE = "date_consumption >= DATE_SUB(CURRENT_DATE, INTERVAL $diffday DAY) ";
        }

        return $CLAUSE_DATE;
    }

    public function freeCallUsed($agi, $id_user, $id_offer, $billingtype, $startday)
    {

        $CLAUSE_DATE   = $this->checkDaysPackage($agi, $startday, $billingtype);
        $sql           = "SELECT  COUNT(*) AS status FROM pkg_offer_cdr " . "WHERE $CLAUSE_DATE AND id_user = '$id_user' AND id_offer = '$id_offer' ";
        $modelOfferCdr = OfferCdr::model()->findBySql($sql);

        return count($modelOfferCdr) ? $modelOfferCdr->status : 0;
    }

    public function packageUsedSeconds($agi, $id_user, $id_offer, $billingtype, $startday)
    {
        $CLAUSE_DATE = $this->checkDaysPackage($agi, $startday, $billingtype);
        $sql         = "SELECT sum(used_secondes) AS status FROM pkg_offer_cdr " . "WHERE $CLAUSE_DATE AND id_user = '$this->id_user' AND id_offer = '$id_offer' ";

        $modelOfferCdr = OfferCdr::model()->findBySql($sql);

        return count($modelOfferCdr) ? $modelOfferCdr->status : 0;

    }

    public function check_expirationdate_customer($agi)
    {
        $prompt = '';
        if ($this->modelUser->enableexpire == 1 && $this->expirationdate != '00000000000000' && strlen($this->modelUser->expirationdate) > 5) {

            /* expire date */
            if (intval(strtotime($this->modelUser->expirationdate) - time()) < 0) {
                $agi->verbose('User expired => ' . $this->modelUser->expirationdate);
                $prompt                  = "prepaid-card-expired";
                $this->modelUser->active = 0;
                $this->modelUser->save();
            }

        }
        return $prompt;
    }

    public function save_redial_number($agi, $number)
    {
        if (($this->mode == 'did') || ($this->mode == 'callback')) {
            return;
        }
        $this->modelUser->redial = $number;
        $this->modelUser->save();
    }

    public function run_dial($agi, $dialstr, $dialparams, $trunk_directmedia = 'no', $timeout = 3600, $max_long = 2147483647)
    {
        $dialparams = str_replace("%timeout%", min($timeout * 1000, $max_long), $dialparams);
        $dialparams = str_replace("%timeoutsec%", min($timeout, $max_long), $dialparams);
        if ($this->modelSip->directmedia == 'yes' && $trunk_directmedia == 'yes') {
            $agi->verbose("DIRECT MEDIA ACTIVE", 10);
            $dialparams = preg_replace("/,L/", "", $dialparams);
            $dialparams = preg_replace("/,rRL/", "", $dialparams);
            $dialparams = preg_replace("/,RrL/", "", $dialparams);
        }

        if ($this->modelSip->ringfalse == '1') {
            $dialparams = preg_replace("/(^\,.*\,)/", "$1Rr", $dialparams);
        } elseif ($this->modelSip->ringfalse == '0') {
            $dialparams = preg_replace("/Rr/", "", $dialparams);
            $dialparams = preg_replace("/rR/", "", $dialparams);
        }
        /* Run dial command */
        if (strlen($this->agiconfig['amd']) > 0) {
            $dialparams .= $this->agiconfig['amd'];
        }

        if ($MAGNUS->$demo == true) {
            $agi->answer();
            sleep(20);
        }
        return $agi->execute("DIAL $dialstr" . $dialparams);
    }

    public function number_translation($agi, $destination)
    {
        #match / replace / if match length
        #0/54,4/543424/7,15/549342/9

        //$this->prefix_local = "0/54,*/5511/8,15/549342/9";

        $regexs = preg_split("/,/", $this->prefix_local);

        foreach ($regexs as $key => $regex) {

            $regra   = preg_split('/\//', $regex);
            $grab    = $regra[0];
            $replace = isset($regra[1]) ? $regra[1] : '';
            $digit   = isset($regra[2]) ? $regra[2] : '';

            $agi->verbose("Grab :$grab Replacement: $replace Phone Before: $destination", 25);

            $number_prefix = substr($destination, 0, strlen($grab));

            if (strtoupper($this->config['global']['base_country']) == 'BRL' || strtoupper($this->config['global']['base_country']) == 'ARG') {
                if ($grab == '*' && strlen($destination) == $digit) {
                    $destination = $replace . $destination;
                } else if (strlen($destination) == $digit && $number_prefix == $grab) {
                    $destination = $replace . substr($destination, strlen($grab));
                } elseif ($number_prefix == $grab) {
                    $destination = $replace . substr($destination, strlen($grab));
                }

            } else {

                if (strlen($destination) == $digit) {
                    if ($grab == '*' && strlen($destination) == $digit) {
                        $destination = $replace . $destination;
                    } else if ($number_prefix == $grab) {
                        $destination = $replace . substr($destination, strlen($grab));
                    }
                }
            }
        }

        $agi->verbose("Phone After translation: $destination", 10);
        $this->destination = Portabilidade::getDestination($destination, $this->id_plan);
    }

    public function round_precision($number)
    {
        $PRECISION = 6;
        return round($number, $PRECISION);
    }

    public function executePlayAudio($prompt, $agi)
    {
        if (strlen($prompt) > 0) {
            if ($this->play_audio == 0) {
                $agi->verbose("Send Congestion $prompt", 3);
                $agi->execute((congestion), Congestion);
            } else {
                $agi->verbose($prompt, 3);
                $agi->answer();
                $agi->stream_file($prompt, '#');

            }
        }
    }

    public function checkRestrictPhoneNumber($agi)
    {
        if ($this->restriction == 1 || $this->restriction == 2) {
            /*Check if Account have restriction*/
            $modelRestrictedPhonenumber = RestrictedPhonenumber::model()->findAll(array(
                'condition' => 'id_user = :key AND number = SUBSTRING(:key1,1,length(number))',
                'params'    => array(
                    ':key'  => $this->id_user,
                    ':key1' => $this->destination,
                ),
                'order'     => 'LENGTH(number) DESC',

            ));

            $agi->verbose("RESTRICTED NUMBERS ", 15);

            if ($this->restriction == 1) {
                /* NOT ALLOW TO CALL RESTRICTED NUMBERS*/
                if (count($modelRestrictedPhonenumber) > 0) {
                    /* NUMBER NOT AUHTORIZED*/
                    $agi->verbose("NUMBER NOT AUHTORIZED - NOT ALLOW TO CALL RESTRICTED NUMBERS", 1);
                    $agi->answer();
                    $agi->stream_file('prepaid-dest-unreachable', '#');
                    $this->hangup($agi);
                }
            } else if ($this->restriction == 2) {
                /* ALLOW TO CALL ONLY RESTRICTED NUMBERS */
                if (count($modelRestrictedPhonenumber) == 0) {
                    /*NUMBER NOT AUHTORIZED*/
                    $agi->verbose("NUMBER NOT AUHTORIZED - ALLOW TO CALL ONLY RESTRICTED NUMBERS", 1);
                    $agi->answer();
                    $agi->stream_file('prepaid-dest-unreachable', '#');
                    $this->hangup($agi);
                }
            }
        }
    }

    public function startRecordCall(&$agi, $addicional = '')
    {
        if ($this->record_call == 1) {
            $command_mixmonitor = "MixMonitor /var/spool/asterisk/monitor/$this->accountcode/{$this->destination}{$addicional}.{$this->uniqueid}." . $this->mix_monitor_format . ",b";
            $agi->execute($command_mixmonitor);
            $agi->verbose($command_mixmonitor, 1);
        }
    }

    public function stopRecordCall(&$agi)
    {
        if ($this->record_call == 1) {
            $agi->verbose("EXEC StopMixMonitor (" . $this->uniqueid . ")", 6);
            $agi->execute("StopMixMonitor");
        }
    }

    public function executeVoiceMail($agi, $dialstatus, $answeredtime)
    {
        if ($this->voicemail == 1) {
            if ($dialstatus == "BUSY") {
                $answeredtime = 0;
                $agi->answer();
                $agi->execute(VoiceMail, $this->destination . "@billing,b");
            } elseif ($dialstatus == "NOANSWER") {
                $answeredtime = 0;
                $agi->answer();
                $agi->execute(VoiceMail, $this->destination . "@billing");
            } elseif ($dialstatus == "CANCEL") {
                $answeredtime = 0;
            }
            if (($dialstatus == "CHANUNAVAIL") || ($dialstatus == "CONGESTION")) {
                $agi->verbose("CHANNEL UNAVAILABLE - GOTO VOICEMAIL ($dest_username)", 6);
                $agi->answer();
                $agi->stream_file("vm-intro", '#');
                $agi->execute(VoiceMail, $this->destination . '@billing,u');
            }
        }
        return $answeredtime;
    }

    public function roudRatePrice($sessiontime, $sell, $initblock, $billingblock)
    {
        if ($sessiontime < $initblock) {
            $sessiontime = $initblock;
        }

        if ($sessiontime > $initblock) {
            $mod_sec = $sessiontime % $billingblock;
            if ($mod_sec > 0) {
                $sessiontime += ($billingblock - $mod_sec);
            }

        }
        return ($sessiontime / 60) * $sell;
    }
    public function checkIVRSchedule($model)
    {
        $weekDay = date('D');

        switch ($weekDay) {
            case 'Sun':
                $weekDay = $model->{'TimeOfDay_sun'};
                break;
            case 'Sat':
                $weekDay = $model->{'TimeOfDay_sat'};
                break;
            default:
                $weekDay = $model->{'TimeOfDay_monFri'};
                break;
        }

        $hours   = date('H');
        $minutes = date('i');
        $now     = ($hours * 60) + $minutes;

        $intervals = preg_split("/\|/", $weekDay);

        foreach ($intervals as $key => $interval) {
            $hours = explode('-', $interval);

            $start = $hours[0];
            $end   = $hours[1];

            #convert start hour to minutes
            $hourInterval = explode(':', $start);
            $starthour    = $hourInterval[0] * 60;
            $start        = $starthour + $hourInterval[1];

            #convert end hour to minutes
            $hourInterval = explode(':', $end);
            $starthour    = $hourInterval[0] * 60;
            $end          = $starthour + $hourInterval[1];

            if ($now >= $start && $now <= $end) {
                return "open";
            }
        }
        return "closed";
    }
};
