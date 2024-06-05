<?php

namespace App\controllers;

use App\core\Core;
use DateTime;
use DOMNode;
use PDO;

class AlertsController extends Core
{
    const DEBUG = false;
    const CRON_CYCLES = 5; // todo switch back to 6
    const BULK_MAIL_ACCOUNT = "otraapplikacio@gmail.com";
    const BULK_MAIL_ACCOUNT_PW = "daeihgtccsdkymkx";
    private $PAJZS_addresses = [
        "BM OKF PAJZS <pajzs@katved.gov.hu>",
        "emailgw@katved.gov.hu",
        "Pajzs <Pajzs.OKF@katved.gov.hu>",
    ];

    // ZWuJV!r9esNi@mL1ac80
    // daeihgtccsdkymkx

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * getEmails() function
     * gets called at regular intervals and sends the alert upon finding an incident report email.
     * @return false|string
     * @throws \Exception
     */
    public function getEmails()
    {
        if (isset($_GET['status_check'])) {
            return "OK";
        }
        $this->log(date("Y-m-d H:i:s") . " ---- cron init ----");
        $cronUuid = $this->uuid(true);
        $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " started ----");
        $time_limit = 60; // s
//        set_time_limit($time_limit); // kill after 1 min


        // error_reporting(0);
        $db = $this->DB;

        $get_cron_status = $db->prepare("SELECT * FROM cron");
        $get_cron_status->execute(array());
        $cron_status = $get_cron_status->fetch(PDO::FETCH_ASSOC);

        $last_run = strtotime($cron_status["lastrun"]); // last run ended at
        $time_diff = intval(strtotime("now") - $last_run);
        $upd_cron = $db->prepare("UPDATE cron SET last_run_seconds_ago = ?");
        $upd_cron->execute(array($time_diff));

        if ($cron_status["running"] == 1 && !isset($_GET["nocron"])) {
            $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " CRON running is TRUE ----");
            if ($time_diff < $time_limit) {
                // csak valamiert elhuzodott az elozo, ilyenkor varunk egy kicsit, majd a kovetkezo cron cycle-ben probaljuk ujra
                mail("mihaly.szabo@appsolution.hu", "CRON ERR", "CRON OVERLAPPING");
                $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " aborted, cron calls overlapping. ----");
            } else {
                // ha 120+ mp telt el akkor
                $upd_cron = $db->prepare("UPDATE cron SET running = 0");
                $upd_cron->execute(array());
                mail("mihaly.szabo@appsolution.hu", "CRON ERR", "CRON TIME DIFF > 120 S, RE-INITED");
                $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " aborted, cron calls overlapping. Running set to FALSE ----");
                $this->sendProblemNotification();
            }
            exit(json_encode(
                array(
                    "status" => $cron_status,
                    "error" => "already running!"
                )
            ));
        } else {
            $set_running = $db->prepare("UPDATE cron SET running = 1");
            $set_running->execute(array());
            $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " set running to TRUE ----");
        }

        $res = null;

        try {
            $res = $this->doFetchEmails($cronUuid);
        } catch (\Exception $e) {
            $this->log("Exception: " . $e->getMessage());
        }

        return $this->_response(
            array(
                "status" => 200,
                "data" => $res
            ),
            200
        );
    }


    private function doFetchEmails($cronUuid)
    {
        $cron_start = microtime(true);
        $db = $this->DB;
        $alerts = [];
        $emailsProcessed = [];

        $connection = imap_open('{imap.gmail.com:993/imap/ssl}INBOX', self::BULK_MAIL_ACCOUNT, self::BULK_MAIL_ACCOUNT_PW, OP_READONLY);
        $imapErrors = imap_errors();

        if (empty($imapErrors)) {
            for ($i = 0; $i < (isset($_GET["nocron"]) ? 1 : self::CRON_CYCLES); ++$i) {
                $time_start = microtime(true);
                $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " cycle #" . ($i + 1) . " started... ----");

                if ($connection) {
                    $db->beginTransaction();

                    $get_cron_status = $db->prepare("SELECT * FROM cron");
                    $get_cron_status->execute(array());
                    $cron_status = $get_cron_status->fetch(PDO::FETCH_ASSOC);
                    $from_uid = $cron_status["last_uid"] + 1;

                    $emails = imap_fetch_overview($connection, ($from_uid) . ":*", FT_UID);
                    $emailsProcessed[$i] = $emails;

                    if (!empty($emails)) {
                        $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . ": found " . count($emails) . "email(s)");
                        foreach ($emails as $email) {
                            $uid = $email->uid;

                            if ($uid > $cron_status["last_uid"]) {
                                if (in_array($email->from, $this->PAJZS_addresses)) {
                                    $recv_address = $email->to;
                                    $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . ": received PAJZS email uid: " . $uid . ", " . $email->from . "->" . $email->to . "");

                                    $get_org = $db->prepare("SELECT * FROM organizations WHERE alert_email = ?");
                                    $get_org->execute(array($recv_address));
                                    if ($get_org->rowCount()) {
                                        $organization = $get_org->fetch(PDO::FETCH_ASSOC);  // ehhez a szervezethez tartozik az email

                                        if ($organization["email_alerts_enabled"] == 1) {
                                            $body = base64_decode(quoted_printable_decode(imap_fetchbody($connection, $uid, '1', FT_UID)));

                                            // assembling alert data
                                            $alert_data = $this->parseEmail($body);
                                            $alert_data['org_id'] = $organization["id"];

                                            if (isset($_GET['nocron'])) {
                                                continue 2;
                                            }

                                            $email_date = new DateTime();
                                            $email_date->setTimestamp($email->udate);

                                            $alert_data["email_arrived_datetime"] = $email_date->format("Y-m-d H:i:s");
                                            $alert_data["email_uid"] = $uid;
                                            $alert_data["org_email"] = $organization["alert_email"];

                                            // email példány mentése
                                            $this->storeAlert($alert_data);

                                            $check_exists = $db->prepare("SELECT * FROM incidents WHERE incident_id = ?");
                                            $check_exists->execute(array($alert_data["incident_id"]));
                                            if (!$check_exists->rowCount()) {
                                                // nincs még létrehozva az esemény DB-ben, létrehozzuk
                                                $incident_id = $this->createIncident($alert_data);
                                                $alert_data['created_incident_id'] = $incident_id;

                                                // hozzárendeljük azt a szervezetet akinek az emailjére kiment
                                                $this->assignIncident($organization["id"], $incident_id);

//                                                $now = new DateTime();
//                                                if ($email_date->diff($now)->i < 10) {
                                                // kiriasztjuk ezt a szervezetet
                                                $this->pushAlertToOrganization($organization["id"], $incident_id, $alert_data);
//                                                }
                                                $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . ": processed alert email uid " . $uid . " successfully");
                                            } else {
                                                // már szerepel a DB-ben az esemény, lekérjük
                                                $get_incident = $db->prepare("SELECT * FROM incidents WHERE incident_id = ?");
                                                $get_incident->execute(array($alert_data["incident_id"]));

                                                $incident = $get_incident->fetch(PDO::FETCH_ASSOC);

                                                // lehet hogy most új e-mailre ment ki a riasztás, ebben az esetben hozzárendeljük az új szervezethez is az incidenst
                                                $check_new_org = $db->prepare("SELECT * FROM org_incidents WHERE org_id = ? AND incident_id = ?");
                                                $check_new_org->execute(array($organization["id"], $incident["id"]));
                                                if (!$check_new_org->rowCount()) {
                                                    // igen, új mailre ment ki, hozzárendeljük ehhez az org-hoz is
                                                    $this->assignIncident($organization["id"], $incident["id"]);


//                                                    $now = new DateTime();
//                                                    if ($email_date->diff($now)->i < 10) {
                                                    // ha 10 percen belüli az e-mail, kiértesítjük a szervezetet, ha nem, akkor valószínűleg egy leállás után most futunk le először, úgyhogy hagyjuk
                                                    // !!upd: nem biztos h ha 10p+ akkor leállás után futtatjuk, mostanában egyre többször üzemszerű ez a késés, kiszedem az ellenorzest
                                                    $this->pushAlertToOrganization($organization["id"], $incident["id"], $alert_data);
//                                                    }
                                                    $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . ": added new organization (" . $organization['id'] . ") to incident");
                                                } else {
                                                    // ha nem új org-nak megy ki, akkor ez csak update lehet

                                                    // itt is megnézzük, hogy nem egy régebbi levél-e, amit csak most sikerült beolvasni, hanem tényleg aktuális-e !! upd: ezt kivettem
//                                                    $now = new DateTime();
//                                                    if ($email_date->diff($now)->i < 10) {
                                                    $this->pushUpdateToOrganization($incident, $alert_data, $organization["id"]);
//                                                    }
                                                    $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . ": processed update email successfully");
                                                }
                                            }
                                            $alerts[] = $alert_data;
                                        } else { // end if(email_alerts_enabled)
                                            $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . ": email alerts not enabled, skipping.");
                                        }
                                    } else { // end if(org_exists) nincs benne a db-ben ez az org, wtf?
                                        $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . ": no organization found with this email.");
                                    }
                                } else {
                                    // ha nem az okf-tol jott akkor valoszinuleg megerosito mail
                                    if ($email->from == "A Gmail csapata <forwarding-noreply@google.com>") {
                                        $subject = $email->subject;
                                        $this->log($subject);
//                                        $tmp = explode("_", $subject);
//                                        $tmp = $tmp[count($tmp) - 1];
//                                        $email = substr($tmp, 0, strpos($tmp, '?'));
                                        $tmp = explode(' ', $subject);
                                        $email = end($tmp);
                                        $this->log($email);
                                        $set_org = $db->prepare("UPDATE organizations SET `status` = 'fwd_pending' WHERE alert_email = ?");
                                        $set_org->execute(array($email));
                                        $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . ": processed confirm email");
                                    } else {
                                        $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . ": email sender not recognized: " . $email->from . "");
                                    }
                                }

                                $set_last_uid = $db->prepare("UPDATE cron SET last_uid = ?");
                                $set_last_uid->execute(array($uid));
                            } else { // end if(uid>last_uid)
                                $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . ": uid < last uid, skipping");
                            }
                        } // end foreach
                    } else { // end if(empty(emails))
                        $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . ": no new emails");
                    }

                    $commit_result = $db->commit();
                    $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . " commit result: " . $commit_result);
                } else {
                    // valamiert megszakadt a connection, kilőjük ezt a ciklust
                    $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . " connection lost to imap server");
                    break;
                }

                $exec_time = microtime(true) - $time_start;

                $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . " cycle exec " . $exec_time . "s");
                $cycle_time_max = 60 / self::CRON_CYCLES;

                if (!isset($_GET["nocron"]) && ($exec_time < $cycle_time_max) && $i < self::CRON_CYCLES - 1) {
                    $sleep_t = intval(floor($cycle_time_max - $exec_time));
                    $this->log(date("Y-m-d H:i:s") . " cron #" . $cronUuid . " sleep " . $sleep_t . "s");
                    sleep($sleep_t);
                }
            } // end for (CRON CYCLES)

        } else {
            $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " errors: " . implode(", ", $imapErrors) . " ----");
        }

        imap_close($connection);

        $cron_exec_time = microtime(true) - $cron_start;

        $set_running = $db->prepare("UPDATE cron SET running = 0, lastrun = NOW(), last_exec_time_s = ?");
        $set_running->execute(array($cron_exec_time));
        $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " set running to FALSE ----");
        $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " finished ----");

        $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " checking stage start ----");
        $this->checkCreatedIncidents($cronUuid, $alerts);
        $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " checking stage ended ----");


        return array(
            'emails' => $emailsProcessed,
            'new_alerts_count' => count($alerts),
            'new_alerts' => $alerts,
            'imap_errors' => $imapErrors
        );
    }

    private function checkCreatedIncidents($cronUuid, $alerts)
    {
        $db = $this->DB;
        $failedAlerts = [];
        foreach ($alerts as $alert) {
            $failed = false;
            if (!empty($alert["incident_id"])) {
                $check_inc_exists = $db->prepare("SELECT * FROM incidents WHERE incident_id = ?");
                $check_inc_exists->execute(array(
                    $alert["incident_id"]
                ));
                if ($check_inc_exists->rowCount()) {
                    $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " [OK] found in incidents table: " . $alert["incident_id"] . " ----");
                } else {
                    $failed = true;
                    $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " [ERR] not found in incidents table: " . $alert["incident_id"] . ", data: " . json_encode($alert) . " ----");
                }


                $check_alert_exists = $db->prepare("SELECT * FROM alerts WHERE incident_id = ?");
                $check_alert_exists->execute(array(
                    $alert["incident_id"]
                ));
                if ($check_alert_exists->rowCount()) {
                    $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " [OK] found in alerts table: " . $alert["incident_id"] . " ----");
                } else {
                    $failed = true;
                    $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " [ERR] not found in alerts table: " . $alert["incident_id"] . ", data: " . json_encode($alert) . " ----");
                }
            } else {
                $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " empty incident id...? " . json_encode($alert) . " ----");
            }
            if ($failed) {
                $failedAlerts[] = $alert;
            }
        }
        if (!empty($failedAlerts)) {
            foreach ($failedAlerts as $failedAlert) {
                $this->storeAlert($failedAlert);
                $incident_id = $this->createIncident($failedAlert);
                $this->assignIncident($failedAlert["org_id"], $incident_id);
                // update failed user-incidents joins
                if (!empty($failedAlert['created_incident_id'])) {
                    $updateUserIncidents = $db->prepare('UPDATE user_incidents SET incident_id = ? WHERE incident_id = ?');
                    $updateUserIncidents->execute(array(
                        $incident_id,
                        $failedAlert['created_incident_id']
                    ));
                }
                $this->log(date("Y-m-d H:i:s") . " ---- cron #" . $cronUuid . " re-inserted incident " . $failedAlert['incident_id'] . " as " . $incident_id . " ----");
            }
            mail("mihaly.szabo@appsolution.hu", "[OTRA] missing incidents", "1 or more incidents were missing from database, re-inserted, check log for details.<br><pre>" . json_encode($failedAlerts)) ."</pre>";
        }
    }


    /**
     * Component functions
     */

    /**
     * @param $body
     * @return array
     */
    private function parseEmail($body)
    {
        $alert_data = array();

        $dom = new \DOMDocument();
        $dom->loadHTML(mb_convert_encoding($body, 'HTML-ENTITIES', 'UTF-8'));
        $raw_body = explode('<br', $body);

        $var = null;

        foreach ($raw_body as $item) {
            // kitakaritjuk az explode során megmaradt tag maradványokat
            $clean_item = str_replace("/>", "", strip_tags($item));
            if (substr($clean_item, 0, 1) == '>') {
                $clean_item = substr($clean_item, 1);
            }


            if (!empty($clean_item) && $clean_item !== 'generated_by_email_gateway') {
                // nem üres a sor, elkezdjük parse-olni

                // kiszedjük a riasztás azonosítót a riasztási lap szöveg alapján
                // pl. RIASZTÁSI LAP (2020SZABOLCS02525)
                $query = "RIASZTÁSI LAP";
                if (substr($clean_item, 0, strlen($query)) === $query) {
                    $incident_id_arr = explode(" ", $clean_item);
                    $incident_id = str_replace(array("(", ")"), "", $incident_id_arr[2]);
                    $alert_data["incident_id"] = $incident_id;
                }


                // az item data az egy tömb amiben a 0 a key és az 1 a value tehát pl
                // Káreset fajtája: Műszaki mentés => $item_data[0] = "Káreset fajtája", $item_data[1] = Műszaki mentés

                $item_data = explode(':', $clean_item);
                if (count($item_data) > 2) { // arra az esetre ha lett volna még kettospont a sorban
                    $first = array_shift($item_data);
                    $rest = implode(":", $item_data);
                    $item_data = [$first, $rest];
                }
                // legyen szep
                if (array_key_exists(1, $item_data)) {
                    $item_data[1] = trim($item_data[1]);
                }


                switch ($item_data[0]) {
                    case "Település/Szektor":
                        $var = 'city';
                        break;
                    case "Cím":
                        $var = 'address';
                        break;
                    case "Jelzés dátuma":
                        $var = 'alert_datetime';
                        break;
                    case "Káreset fajtája":
                        $var = 'incident_type';
                        break;
                    case "Kategória":
                        $var = 'category';
                        break;
                    case "Esemény rövid leírása":
                        $var = 'description';
                        break;
                    case "Riasztási fokozat":
                        $var = 'alarm_level';
                        break;
                    case "Módosított riasztási fokozat":
                        $var = 'modified_alarm_level';
                        break;
                    case "Mit veszélyeztet":
                        $var = 'risk';
                        break;
                    case "Életveszély":
                        $var = 'life_danger';
                        break;
                    case "Megkülönböztető jelzés használata":
                        $var = 'sirens';
                        break;
                    case "Eseményre riasztott szerek":
                        $var = 'alerted_units';
                        break;
                    default:
                        if ($var !== 'alerted_units' && $var !== 'description') {
                            $var = null;
                        }
                        break;
                }
                if ($var) {
                    if ($var == 'alerted_units' || $var == 'description') {
                        if (array_key_exists(1, $item_data) && ($item_data[0] == "Eseményre riasztott szerek" || $item_data[0] == "Esemény rövid leírása")) {
                            // ez az az eset amikor az első szert szedjük ki mert akkor még a kettőspont után van
                            // (pl.: Eseményre riasztott szerek: Nyírség/2 - 2020-08-03 11:21:10)
                            $alert_data[$var][] = $item_data[1];
                        } else {
                            // ez már az amikor új sor
                            $alert_data[$var][] = implode(':', $item_data);
                        }
                    } else {
                        // egyéb esetekben mindig az 1-es indexet vesszuk ki tehát ami a kettőspont után van
                        $alert_data[$var] = $item_data[1];
                    }
                }
            }
        } // end foreach

        // összeragasztjuk amik új sorokba voltak
        $alert_data["alerted_units"] = implode(', ', $alert_data["alerted_units"]);
        $alert_data["description"] = implode(', ', $alert_data["description"]);

        $links = $dom->getElementsByTagName('a');
        /** @var DOMNode $link */
        foreach ($links as $link) {
            foreach ($link->attributes as $attr) {
                if ($attr->name === 'href' && strpos($attr->value, "holvan.hu") > 0) {
                    $link = $attr->value;
                    $eov = [];
                    $spl = explode("=", $link);
                    $eov[] = substr($spl[1], 0, strpos($spl[1], "&"));
                    $eov[] = substr($spl[2], 0, strpos($spl[2], "&"));
                    $latlng = $this->EOVWGS_convert($eov);
                    $alert_data["lat"] = $latlng[0];
                    $alert_data["lng"] = $latlng[1];
                }
            }
        }
        return $alert_data;
    }

    private function storeAlert($alert_data)
    {
        $db = $this->DB;
        $this->log("**debug** Trying to insert alert data: " . json_encode($alert_data));
        $insert_alert = $db->prepare("INSERT INTO alerts(email_uid, email, incident_id, city, address, lat, lng, alert_datetime, email_arrived_datetime, incident_type, category, description, alarm_level, modified_alarm_level, risk, life_danger, sirens, alerted_units) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $insert_alert->execute(array(
            $alert_data["email_uid"],
            $alert_data["org_email"],
            $alert_data["incident_id"],
            $alert_data["city"],
            $alert_data["address"],
            $alert_data["lat"],
            $alert_data["lng"],
            $alert_data["alert_datetime"],
            $alert_data["email_arrived_datetime"],
            $alert_data["incident_type"],
            $alert_data["category"],
            $alert_data["description"],
            $alert_data["alarm_level"],
            $alert_data["modified_alarm_level"],
            $alert_data["risk"],
            $alert_data["life_danger"],
            $alert_data["sirens"],
            $alert_data["alerted_units"],
        ));
        $this->log("**debug** Inserted alert: " . $db->lastInsertId());
    }

    private function createIncident($alert_data)
    {
        $db = $this->DB;
        $insert_incident = $db->prepare("INSERT INTO incidents(incident_id, city, address, lat, lng, alert_datetime, email_arrived_datetime, incident_type, category, description, alarm_level, modified_alarm_level, risk, life_danger, sirens, alerted_units) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $insert_incident->execute(array(
            $alert_data["incident_id"],
            $alert_data["city"],
            $alert_data["address"],
            $alert_data["lat"],
            $alert_data["lng"],
            $alert_data["alert_datetime"],
            $alert_data["email_arrived_datetime"],
            $alert_data["incident_type"],
            $alert_data["category"],
            $alert_data["description"],
            $alert_data["alarm_level"],
            $alert_data["modified_alarm_level"],
            $alert_data["risk"],
            $alert_data["life_danger"],
            $alert_data["sirens"],
            $alert_data["alerted_units"],
        ));
        return $db->lastInsertId();
    }

    private function assignIncident($org_id, $incident_id)
    {
        $db = $this->DB;
        $store_org_incident = $db->prepare("INSERT INTO org_incidents (org_id, incident_id) VALUES (?,?)");
        $store_org_incident->execute(array(
            $org_id,
            $incident_id
        ));
    }

    private function pushAlertToOrganization($org_id, $incident_id, $alert_data)
    {
        $db = $this->DB;
        $get_crew = $db->prepare("SELECT user_id FROM user_organizations WHERE organization_id = ?");
        $get_crew->execute(array($org_id));

        while ($member_id = $get_crew->fetch(PDO::FETCH_ASSOC)["user_id"]) {
            $get_member = $db->prepare("SELECT * FROM users WHERE id = ?");
            $get_member->execute(array($member_id));
            $member = $get_member->fetch(PDO::FETCH_ASSOC);
            if ($member["push_notification"] == 1) {
                $res = self::sendAlertNotification($member["fcm_token"], $member["curr_platform"], $alert_data);
                if ($res["success"] == 1) {
                    $insert = $db->prepare("INSERT INTO user_incidents(user_id, incident_id, status) VALUES (?,?,'notified')");
                    $insert->execute(array($member["id"], $incident_id));
                } else {
                    file_put_contents(BASE_DIR . "/log/failed_notification_" . date("Ymd") . ".json", date("Ymd-his") . "_" . $member["email"] . "_" . json_encode($res), FILE_APPEND);
                    $insert = $db->prepare("INSERT INTO user_incidents(user_id, incident_id, status) VALUES (?,?,'unreachable')");
                    $insert->execute(array($member["id"], $incident_id));
                }
            } else {
                $insert = $db->prepare("INSERT INTO user_incidents(user_id, incident_id, status) VALUES (?,?,'offline')");
                $insert->execute(array($member["id"], $incident_id));
            }
        }
    }

    private function pushUpdateToOrganization($incident, $alert_data, $org_id)
    {
        $db = $this->DB;

        // megnézzük hogy a mostani data eltér-e és ha igen, frissítjük.
        // Ha (!(nem változott semmi)), tehát valami változott, akkor frissítjük az eseményt.
        if (!($alert_data["city"] == $incident["city"] &&
            $alert_data["address"] == $incident["address"] &&
            $alert_data["lat"] == $incident["lat"] &&
            $alert_data["lng"] == $incident["lng"] &&
            $alert_data["alert_datetime"] == $incident["alert_datetime"] &&
            $alert_data["incident_type"] == $incident["incident_type"] &&
            $alert_data["category"] == $incident["category"] &&
            $alert_data["description"] == $incident["description"] &&
            $alert_data["alarm_level"] == $incident["alarm_level"] &&
            $alert_data["modified_alarm_level"] == $incident["modified_alarm_level"] &&
            $alert_data["risk"] == $incident["risk"] &&
            $alert_data["life_danger"] == $incident["life_danger"] &&
            $alert_data["sirens"] == $incident["sirens"] &&
            $alert_data["alerted_units"] == $incident["alerted_units"])) {
            $update_incident = $db->prepare("UPDATE incidents SET city = ?, address = ?, lat = ?, lng = ?, alert_datetime = ?, incident_type = ?, category = ?, description = ?, alarm_level = ?, modified_alarm_level = ?, risk = ?, life_danger = ?, sirens = ?, alerted_units = ? WHERE id = ?");
            $update_incident->execute(array(
                $alert_data["city"],
                $alert_data["address"],
                $alert_data["lat"],
                $alert_data["lng"],
                $alert_data["alert_datetime"],
                $alert_data["incident_type"],
                $alert_data["category"],
                $alert_data["description"],
                $alert_data["alarm_level"],
                $alert_data["modified_alarm_level"],
                $alert_data["risk"],
                $alert_data["life_danger"],
                $alert_data["sirens"],
                $alert_data["alerted_units"],
                $incident["id"]
            ));
            $get_crew = $db->prepare("SELECT user_id FROM user_organizations WHERE organization_id = ?");
            $get_crew->execute(array($org_id));
            while ($member_id = $get_crew->fetch(PDO::FETCH_ASSOC)["user_id"]) {
                $get_member = $db->prepare("SELECT * FROM users WHERE id = ?");
                $get_member->execute(array($member_id));
                $member = $get_member->fetch(PDO::FETCH_ASSOC);
                if ($member["push_notification"] == 1) {
                    $this->sendAlertUpdateNotification($member["fcm_token"], $member["curr_platform"], $alert_data["incident_id"]);
                }
            }
        }
    }

    /**
     * -------------------- NOTIFICATION FUNCTIONS --------------------
     */

    /**
     * @param $token
     * @param $platform
     * @param $alert_data
     * @return array
     */
    public function sendAlertNotification($token, $platform, $alert_data)
    {
        if (self::MAINTENANCE) return array();
        if (empty($token)) return array();
        if ($platform == 'android') {
            $fields = array(
                'to' => $token,
                "priority" => "high",
                'data' => array(
                    'title' => 'RIASZTÁS (' . $alert_data["incident_id"] . ')',
                    'body' => $alert_data["incident_type"] . ", " . $alert_data["city"] . " " . $alert_data["address"],
                    'click_action' => "FLUTTER_NOTIFICATION_CLICK",
                    'sound' => 'alarm',
                    'status' => 'done',
                    'screen' => 'screenA',
                    'message' => 'FIRE_ALERT'
                )
            );
        } else {
            $fields = array(
                'to' => $token,
                'notification' => array(
                    'title' => 'RIASZTÁS (' . $alert_data["incident_id"] . ')',
                    'body' => $alert_data["incident_type"] . ", " . $alert_data["city"] . " " . $alert_data["address"],
                    'sound' => 'alarm.aiff',
                    'badge' => 1
                ),
                'data' => array(
                    'title' => 'RIASZTÁS (' . $alert_data["incident_id"] . ')',
                    'body' => $alert_data["incident_type"] . ", " . $alert_data["city"] . " " . $alert_data["address"],
                    'click_action' => "FLUTTER_NOTIFICATION_CLICK",
                    'sound' => 'alarm',
                ),
                "apns" => array(
                    "headers" => array(
                        "apns-priority" => "10",
                        "apns-push-type" => "background"
                    ),
                    "payload" => array(
                        "aps" => array(
                            "content-available" => 1
                        )
                    ),
                ),
            );
        }

        return $this->fcm_send($fields);
    }


    /**
     * @param $token
     * @param $platform
     * @param $incident_id
     * @return array
     */
    public static function sendAlertUpdateNotification($token, $platform, $incident_id)
    {
        if (parent::MAINTENANCE) return array();
        if (empty($token)) return array();
        if ($platform == 'android') {
            $fields = array(
                'to' => $token,
                "priority" => "high",
                'data' => array(
                    'title' => 'RIASZTÁS FRISSÍTVE (' . $incident_id . ')',
                    'body' => "A riasztás adatai módosultak! Koppintson a megtekintéshez...",
                    'click_action' => "FLUTTER_NOTIFICATION_CLICK",
                    'sound' => 'alarm',
                    'status' => 'done',
                    'screen' => 'screenA',
                    'message' => 'FIRE_ALERT_UPDATED'
                ),
                "apns" => array(
                    "headers" => array(
                        "apns-priority" => "10",
                        "apns-push-type" => "background"
                    ),
                    "payload" => array(
                        "aps" => array(
                            "content-available" => 1
                        )
                    ),
                ),
            );
        } else {
            $fields = array(
                'to' => $token,
                'notification' => array(
                    'title' => 'RIASZTÁS FRISSÍTVE (' . $incident_id . ')',
                    'body' => "A riasztás adatai módosultak! Koppintson a megtekintéshez...",
                    'sound' => 'alarm.aiff',
                    'badge' => 1
                ),
                "apns" => array(
                    "headers" => array(
                        "apns-priority" => "10",
                        "apns-push-type" => "background"
                    ),
                    "payload" => array(
                        "aps" => array(
                            "content-available" => 1
                        )
                    ),
                ),
            );
        }

        return parent::fcm_send($fields);
    }

    /**
     * @param $token
     * @param $platform
     * @param $incident_id
     * @return array
     */
    public function sendProblemNotification()
    {
        $db = $this->DB;
        $get = $db->prepare("SELECT fcm_token FROM users WHERE id = 1");
        $get->execute(array());
        $token = $get->fetch(PDO::FETCH_ASSOC)["fcm_token"];
        $fields = array(
            'to' => $token,
            'notification' => array(
                'title' => 'CRON IS DYING',
                'body' => "120+ mp telt el a legutóbbi beolvasás óta!",
                'sound' => 'alarm.aiff',
                'badge' => 1
            ),
            "apns" => array(
                "headers" => array(
                    "apns-priority" => "10",
                    "apns-push-type" => "background"
                ),
                "payload" => array(
                    "aps" => array(
                        "content-available" => 1
                    )
                ),
            ),
        );
        return parent::fcm_send($fields);
    }


    /**
     * test endpoint for html parse testing
     */

    public function testEmailBody()
    {
        // assembling alert data
        $alert_data = $this->parseEmail(base64_decode($this->request["email_body"]));
        $email_date = new DateTime();
        $alert_data["email_arrived_datetime"] = $email_date->format("Y-m-d H:i:s");
        $alert_data["email_uid"] = rand(0, 6000);
        $alert_data["org_email"] = "";

        // email példány mentése
        $db = $this->DB;
        $insert_alert = $db->prepare("INSERT INTO alerts(email_uid, email, incident_id, city, address, lat, lng, alert_datetime, email_arrived_datetime, incident_type, category, description, alarm_level, modified_alarm_level, risk, life_danger, sirens, alerted_units) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $insert_alert->execute(array(
            $alert_data["email_uid"],
            $alert_data["org_email"],
            $alert_data["incident_id"],
            $alert_data["city"],
            $alert_data["address"],
            $alert_data["lat"],
            $alert_data["lng"],
            $alert_data["alert_datetime"],
            $alert_data["email_arrived_datetime"],
            $alert_data["incident_type"],
            $alert_data["category"],
            $alert_data["description"],
            $alert_data["alarm_level"],
            $alert_data["modified_alarm_level"],
            $alert_data["risk"],
            $alert_data["life_danger"],
            $alert_data["sirens"],
            $alert_data["alerted_units"],
        ));

        return json_encode($insert_alert->errorInfo());
    }

    /**
     * -------------------- converters and utility functions --------------------
     */

    /**
     * @param $eov
     * @return array
     */

    private function EOVWGS_convert($eov)
    {
        $srcx = $eov[0] / 100000;
        $srcy = $eov[1] / 100000;
        $sptab = [6.686811163157894, 2.132602084210526, 19.30286714210526, 47.24925606842105];
        $vtab = [0.0, -1.389346302296581E-002, 2.831051369736226E-003, -8.995273182444177E-001, 7.604064482862907E-003, 4.967015339995485E-005, 8.631367816723606E-005, -8.022654203907561E-007, 1.257606424116442E-004, 1.205961554542139E-006, 3.774432450230807E-005, -7.666184426238757E-007, -2.944782243063789E-007, 2.119433442075802E-006, -1.031448749770506E-006, 2.908844783304452E-007, 3.794007440847319E-008, 1.888272007847316E-007, -3.020662118318374E-007, 8.574721117742268E-007, -6.945888828184749E-007, -9.565641665696682E-009];
        $wtab = [0.0, 8.543809825502319E-003, -1.321399864762058, -4.157635053997734E-003, 7.179927971415591E-005, -2.233913022628941E-002, -6.983677125129497E-005, 1.259158610444358E-004, 3.248808131515476E-006, -3.749610036201226E-004, -2.167397891004658E-006, -2.135320596732371E-007, 7.771437902469193E-006, -6.916062867883154E-007, -7.353443740667734E-006, -6.456221341945349E-008, -7.627132599514393E-008, 3.945874285553073E-007, -4.770309421567439E-007, 2.111119668870645E-007, -1.148366910964295E-006, 7.197970309876090E-007];

        $vg = $srcy - $sptab[1];
        $wg = $srcx - $sptab[0];
        $yg = $vtab[1] + $vtab[2] * $wg + $vtab[3] * $vg + $vtab[4] * $wg * $wg + $vtab[5] * $vg * $wg + $vtab[6] * $vg * $vg +
            $vtab[7] * $wg * $wg * $wg + $vtab[8] * $wg * $wg * $vg + $vtab[9] * $wg * $vg * $vg + $vtab[10] * $vg * $vg * $vg +
            $vtab[11] * $wg * $wg * $wg * $wg + $vtab[12] * $wg * $wg * $wg * $vg + $vtab[13] * $wg * $wg * $vg * $vg +
            $vtab[14] * $wg * $vg * $vg * $vg + $vtab[15] * $vg * $vg * $vg * $vg +
            $vtab[16] * $wg * $wg * $wg * $wg * $wg + $vtab[17] * $wg * $wg * $wg * $wg * $vg + $vtab[18] * $wg * $wg * $wg * $vg * $vg +
            $vtab[19] * $wg * $wg * $vg * $vg * $vg + $vtab[20] * $wg * $vg * $vg * $vg * $vg + $vtab[21] * $vg * $vg * $vg * $vg * $vg;
        $xg = $wtab[1] + $wtab[2] * $wg + $wtab[3] * $vg + $wtab[4] * $wg * $wg + $wtab[5] * $vg * $wg + $wtab[6] * $vg * $vg +
            $wtab[7] * $wg * $wg * $wg + $wtab[8] * $wg * $wg * $vg + $wtab[9] * $wg * $vg * $vg + $wtab[10] * $vg * $vg * $vg +
            $wtab[11] * $wg * $wg * $wg * $wg + $wtab[12] * $wg * $wg * $wg * $vg + $wtab[13] * $wg * $wg * $vg * $vg +
            $wtab[14] * $wg * $vg * $vg * $vg + $wtab[15] * $vg * $vg * $vg * $vg +
            $wtab[16] * $wg * $wg * $wg * $wg * $wg + $wtab[17] * $wg * $wg * $wg * $wg * $vg + $wtab[18] * $wg * $wg * $wg * $vg * $vg +
            $wtab[19] * $wg * $wg * $vg * $vg * $vg + $wtab[20] * $wg * $vg * $vg * $vg * $vg + $wtab[21] * $vg * $vg * $vg * $vg * $vg;
        $desty = $sptab[3] - $yg;
        $destx = $sptab[2] - $xg;
        return [$desty, $destx];
    }

    private function log($message)
    {
        file_put_contents(BASE_DIR . "/log/cron_" . date('Ymd') . ".log", $message . "\r\n", FILE_APPEND);
    }
}
