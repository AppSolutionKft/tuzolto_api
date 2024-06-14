<?php

namespace App\controllers;

use App\controllers\AlertsController;
use App\controllers\EventsController;
use App\core\interfaces\CRUDControllerInterface;
use App\core\Core;
use PDO;

class IncidentsController extends Core implements CRUDControllerInterface
{

    /**
     * @api {get} /incidents/active Get active incidents
     * @apiName Get active incidents
     * @apiGroup incidents
     *
     * @apiHeader {String} JWT JWT token
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     * @apiSuccess {String} data data array
     * @apiSuccess {Array} data.incidents incidents
     * @apiSuccess {Array} data.events events
     */
    public function list()
    {
        $db = $this->DB;
        $id = $this->getCurrentUser()["id"];

        $get_incidents = $db->prepare("SELECT DISTINCT i.* FROM user_organizations uo INNER JOIN org_incidents oi ON uo.organization_id = oi.org_id INNER JOIN incidents i ON oi.incident_id = i.id WHERE uo.user_id = ? AND i.active = 1 AND i.lat IS NOT NULL AND i.lng IS NOT NULL;");
        $get_incidents->execute(array($this->getCurrentUser()["id"]));
        $incidents = [];
        while ($row = $get_incidents->fetch(PDO::FETCH_ASSOC)) {
            $get_mystatus = $db->prepare("SELECT status FROM user_incidents WHERE user_id = ? AND incident_id = ?");
            $get_mystatus->execute(array(
                $id,
                $row["id"]
            ));
            $row["my_status"] = $get_mystatus->fetch(PDO::FETCH_ASSOC)["status"];
            $get_started_users_count = $db->prepare("SELECT COUNT(*) FROM user_incidents WHERE incident_id = ? AND status NOT IN ('notified', 'offline', 'unreachable')");
            $get_started_users_count->execute(array($row["id"]));

            $row["startedMembers"] = $get_started_users_count->fetch(PDO::FETCH_NUM)[0];
            $row["startedMembers"] = strval($row["startedMembers"]);
            $row["id"] = strval($row["id"]);
            $row["org_id"] = strval($row["org_id"]);
            $row["active"] = strval($row["active"]);
            $row["lat"] = strval($row["lat"]);
            $row["lng"] = strval($row["lng"]);
            $row["participants"] = strval($row["participants"]);

            $incidents[] = $row;
        }

        $get_user_orgs = $db->prepare("SELECT * FROM user_organizations WHERE user_id = ?");
        $get_user_orgs->execute(array($id));
        $events = [];
        while ($org = $get_user_orgs->fetch(PDO::FETCH_ASSOC)) {
            $get_org_events = $db->prepare("SELECT * FROM events WHERE org_id = ? AND NOW() <= end_datetime ORDER BY start_datetime, id");
            $get_org_events->execute(array($org["organization_id"]));
            while ($ev = $get_org_events->fetch(PDO::FETCH_ASSOC)) {
                $ev["type"] = EventsController::types[$ev["type"]];
                $am_i_going = $db->prepare("SELECT * FROM user_events WHERE user_id = ? AND event_id = ?");
                $am_i_going->execute(array($id, $ev["id"]));
                $ev["am_i_going"] = $am_i_going->rowCount() ? true : false;
                $how_many_go = $db->prepare("SELECT COUNT(*) FROM user_events WHERE event_id = ?");
                $how_many_go->execute(array($ev["id"]));
                $ev["participants"] = $how_many_go->fetch(PDO::FETCH_NUM)[0];

                $ev["id"] = strval($ev["id"]);
                $ev["org_id"] = strval($ev["org_id"]);
                $ev["active"] = strval($ev["active"]);
                $ev["lat"] = strval($ev["lat"]);
                $ev["lng"] = strval($ev["lng"]);
                $ev["participants"] = strval($ev["participants"]);

                $events [] = $ev;
            }
        }
        return $this->_response(array("code" => 200, "status" => "SUCCESS", "data" => array("incidents" => $incidents, "events" => $events)));
    }

    /**
     * @api {post} /incidents/mystatus Set user status in incident
     * @apiName set status in incident
     * @apiGroup incidents
     *
     * @apiHeader {String} JWT JWT token
     *
     * @apiParam {int} incident_id incident id
     *
     * @apiParam {string} status user's incident status (onroadhq, onhq, onroadfield, onfield)
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     */
    public function mystatus()
    {
        $db = $this->DB;
        $user = $this->getCurrentUser();
        $inc_id = $this->request["incident_id"];
        $get = $db->prepare("SELECT * FROM user_incidents WHERE user_id = ? AND incident_id = ?");
        $get->execute(array($user["id"], $inc_id));
        if ($get->rowCount()) {
            $get_incident = $db->prepare("SELECT * FROM incidents WHERE id = ?");
            $get_incident->execute(array($inc_id));
            if ($get_incident->rowCount()) {
                $incident = $get_incident->fetch(PDO::FETCH_ASSOC);
                if (array_key_exists("active", $incident) && $incident["active"] == 1) {
                    $update = $db->prepare("UPDATE user_incidents SET status = ?, status_updated = NOW() WHERE id = ?");
                    $update->execute(array($this->request["status"], $get->fetch(PDO::FETCH_ASSOC)["id"]));

                    $this->sendNewStatusNotification($user, $inc_id, $this->getStatusName($this->request["status"]));
                    return $this->_response(array("code" => 200, "status" => "SUCCESS"));
                } else {
                    return $this->_response(array("code" => 404, "status" => "INCIDENT_ALREADY_CLOSED"));
                }
            } else {
                return $this->_response(array("code" => 404, "status" => "INCIDENT_NOT_FOUND"));
            }
        } else {
            return $this->_response(array("code" => 200, "status" => "NOT_IN_INCIDENT"));
        }
    }


    /**
     * @api {post} /incidents/end end incident (only commander)
     * @apiName end incident (only commander)
     * @apiGroup incidents
     *
     * @apiHeader {String} JWT JWT token
     *
     * @apiParam {int} incident_id incident id
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     */
    public function end()
    {
        $db = $this->DB;
        $user = $this->getCurrentUser();
        if ($user["type"] == 1) {
            $end = $db->prepare("UPDATE incidents SET active = 0, ended_datetime = NOW(), closed_by = 'commander', closed_by_commander_id = ? WHERE id = ?");
            $end->execute(array($user["id"], $this->request["incident_id"]));
            return $this->_response(array("code" => 200, "status" => "SUCCESS"));
        } else {
            return $this->_response(array("code" => 401, "status" => "UNAUTHORIZED"), 401);
        }
    }

    /**
     * @api {get} /incidents/fire_hydrants Get Fire Hydrants
     * @apiName Get Fire Hydrants
     * @apiGroup incidents
     *
     * @apiHeader {String} JWT JWT token
     *
     * @apiParam {Double} lat_from Lat From
     * @apiParam {Double} lat_to Lat To
     * @apiParam {Double} lng_from Lng From
     * @apiParam {Double} lng_to Lng to
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     */
    public function getFireHydrants()
    {
        $db = $this->DB;
        $hydrants = [];
//        $get_hydrants = $db->prepare("SELECT * FROM fire_hydrants");
        $get_hydrants = $db->prepare("SELECT * FROM fire_hydrants WHERE lat BETWEEN :lat_from AND :lat_to AND lng BETWEEN :lng_from AND :lng_to");
        $get_hydrants->bindParam(":lat_from", $this->request["lat_from"]);
        $get_hydrants->bindParam(":lat_to", $this->request["lat_to"]);
        $get_hydrants->bindParam(":lng_from", $this->request["lng_from"]);
        $get_hydrants->bindParam(":lng_to", $this->request["lng_to"]);
        $get_hydrants->execute();
        while ($hydrant = $get_hydrants->fetch(PDO::FETCH_ASSOC)) {
            $hydrant["lat"] = strval($hydrant["lat"]);
            $hydrant["lng"] = strval($hydrant["lng"]);
            if (!$hydrant['diameter']) {
                $hydrant['diameter'] = '-';
            }
            $hydrants[] = $hydrant;
        }

        return $this->_response(array("code" => 200, "status" => "SUCCESS", "data" => array("hydrants" => $hydrants)));
    }

    public function emailWebhook()
    {
        file_put_contents("email.log", json_encode($_POST) . "\r\n", FILE_APPEND);

        return $this->_response(array("code" => 200, "status" => "SUCCESS", "data" => array("data" => $_POST)));
    }

    /**
     * @api {get} /incidents/{id}/crewstatus Get incident crew status
     * @apiName incident crew status
     * @apiGroup incidents
     *
     * @apiHeader {String} JWT JWT token
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     */
    public function crewstatus()
    {
        $db = $this->DB;
        $get_statuses = $db->prepare("SELECT u.id, u.username, u.email, u.type as commander, ui.status FROM user_incidents ui LEFT JOIN users u ON ui.user_id = u.id WHERE incident_id = ?");
        $get_statuses->execute(array($this->request["aid"]));
        $statuses = [];
        while($member = $get_statuses->fetch(PDO::FETCH_ASSOC)) {
            $member['id'] = strval($member['id']);
            $member['commander'] = strval($member['commander']);
            if(empty($member['username'])) {
                $member['username'] = $member['email'];
            }
            unset($member['email']);
            $statuses[] = $member;
        }

        return $this->_response(array("code" => 200, "status" => "SUCCESS", "data" => array("statuses" => $statuses)));
    }


    public function testIncident()
    {
        $db = $this->DB;
        $alert_data = $this->request;

        $check_exists = $db->prepare("SELECT * FROM incidents WHERE incident_id = ?");
        $check_exists->execute(array($alert_data["incident_id"]));
        if (!$check_exists->rowCount()) {

            $inc_id = date("Y") . "TESZT" . str_pad(strval(rand(1, 99999)), 5, '0', STR_PAD_LEFT);

            $insert_incident = $db->prepare("INSERT INTO incidents( incident_id, city, address, lat, lng, alert_datetime, incident_type, category, description, alarm_level, modified_alarm_level, risk, life_danger, sirens, alerted_units) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $insert_incident->execute(array(
                $inc_id,
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
            ));
            $incident_id = $db->lastInsertId();

            $alert_data["incident_id"] = $inc_id;

            $store_org_incident = $db->prepare("INSERT INTO org_incidents (org_id, incident_id) VALUES (?,?)");
            $store_org_incident->execute(array(
                $alert_data["org_id"],
                $incident_id
            ));


            $get_crew = $db->prepare("SELECT user_id FROM user_organizations WHERE organization_id = ?");
            $get_crew->execute(array($alert_data["org_id"]));

            $ac = new AlertsController();

            while ($member_id = $get_crew->fetch(PDO::FETCH_ASSOC)["user_id"]) {
                $get_member = $db->prepare("SELECT * FROM users WHERE id = ?");
                $get_member->execute(array($member_id));
                $member = $get_member->fetch(PDO::FETCH_ASSOC);
                if ($member["push_notification"] == 1) {
                    $res = $ac->sendAlertNotification($member["fcm_token"], $member["curr_platform"], $alert_data);
                    if ($res["success"] == 1) {
                        $insert = $db->prepare("INSERT INTO user_incidents(user_id, incident_id, status) VALUES (?,?,'notified')");
                        $insert->execute(array($member["id"], $incident_id));
                    } else {
                        $insert = $db->prepare("INSERT INTO user_incidents(user_id, incident_id, status) VALUES (?,?,'unreachable')");
                        $insert->execute(array($member["id"], $incident_id));
                    }
                } else {
                    $insert = $db->prepare("INSERT INTO user_incidents(user_id, incident_id, status) VALUES (?,?,'offline')");
                    $insert->execute(array($member["id"], $incident_id));
                }
            }
        }
        return $this->_response(array("code" => 200, "status" => "SUCCESS"));
    }

    public function create()
    {
        // TODO: Implement create() method.
    }

    /**
     * @api {get} /incidents/{id}/get Get incident
     * @apiName Get incident
     * @apiGroup incidents
     *
     * @apiHeader {String} JWT JWT token
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     */
    public function get()
    {
        $db = $this->DB;
        $get_incident = $db->prepare("SELECT i.* FROM incidents i INNER JOIN user_organizations uo ON i.org_id = uo.organization_id WHERE uo.confirmed = 1 AND uo.user_id = ? AND i.id = ? ORDER BY i.created_datetime DESC");
        $get_incident->execute(array($this->getCurrentUser()["id"], $this->request["aid"]));
        if ($get_incident->rowCount()) {
            $incident = $get_incident->fetch(PDO::FETCH_ASSOC);
            return $this->_response(array("code" => 200, "status" => "SUCCESS", "data" => array("incident" => $incident)));
        } else {
            return $this->_response(array("code" => 404, "status" => "NOT_FOUND"));
        }
    }

    /**
     * @api {post} /incidents/updateLocation Update incident location
     * @apiName Update incident location
     * @apiGroup incidents
     *
     * @apiHeader {String} JWT JWT token
     *
     * @apiParam {int} incident_id incident id
     * @apiParam {double} lat lat
     * @apiParam {double} lng lng
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     */
    public function updateLocation()
    {
        $db = $this->DB;
        $update = $db->prepare("UPDATE incidents SET address = ?, lat = ?, lng = ? WHERE id = ?");
        $update->execute(array(
            $this->request["address"],
            $this->request["lat"],
            $this->request["lng"],
            $this->request["incident_id"]
        ));
        $this->sendLocationUpdateNotification($this->request["incident_id"]);
        return $this->_response(array("code" => 200, "status" => "SUCCESS"));
    }


    public function notifyMember()
    {
        $db = $this->DB;

        $get_user = $db->prepare("SELECT * FROM users WHERE id = ?");
        $get_user->execute(array($this->request["user_id"]));
        if ($get_user->rowCount()) {
            $user_to_notify = $get_user->fetch(PDO::FETCH_ASSOC);
            $get_inc = $db->prepare("SELECT * FROM incidents WHERE id = ?");
            $get_inc->execute(array($this->request["incident_id"]));
            if ($get_inc->rowCount()) {
                $inc_data = $get_inc->fetch(PDO::FETCH_ASSOC);

                $ac = new AlertsController();
                $ac->sendAlertNotification(
                    $user_to_notify["fcm_token"],
                    $user_to_notify["curr_platform"],
                    $inc_data
                );

                $update_user = $db->prepare("UPDATE user_incidents SET status = 'notified' WHERE user_id = ? AND incident_id = ?");
                $update_user->execute(array(
                    $this->request["user_id"],
                    $this->request["incident_id"]
                ));
                return $this->_response(array("code" => 200, "status" => "SUCCESS"));

            } else {
                return $this->_response(array("code" => 404, "status" => "INCIDENT_NOT_FOUND"));
            }
        } else {
            return $this->_response(array("code" => 404, "status" => "USER_NOT_FOUND"));
        }
    }

    public function update()
    {
        // TODO: Implement update() method.
    }

    public function delete()
    {
        // TODO: Implement delete() method.
    }

    private function sendLocationUpdateNotification($incident_id)
    {
        $db = $this->DB;
        $user = $this->getCurrentUser();

        $get_members = $db->prepare("SELECT DISTINCT u.fcm_token, u.curr_platform, u.push_notification FROM users u INNER JOIN user_incidents ui ON u.id = ui.user_id WHERE ui.incident_id = ? AND u.fcm_token IS NOT NULL AND u.id != ?");
        $get_members->execute(array($incident_id, $user["id"]));
        $members = $get_members->fetchAll(PDO::FETCH_ASSOC);

        $get_inc = $db->prepare("SELECT incident_id FROM incidents WHERE id = ?");
        $get_inc->execute(array($incident_id));
        $incident = $get_inc->fetch(PDO::FETCH_ASSOC);


        foreach ($members as $member) {

            if ($member["push_notification"] == 1) {
                if ($member["curr_platform"] == 'android') {
                    $fields = array(
                        'to' => $member["fcm_token"],
                        'data' => array(
                            'title' => 'LOKÁCIÓ FRISSÍTVE (' . $incident["incident_id"] . ')',
                            'body' => "Koppintson a megtekintéshez...",
                            'click_action' => "FLUTTER_NOTIFICATION_CLICK",
                            'sound' => 'default',
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
                        'to' => $member["fcm_token"],
                        'notification' => array(
                            'title' => 'LOKÁCIÓ FRISSÍTVE (' . $incident["incident_id"] . ')',
                            'body' => "Koppintson a megtekintéshez...",
                            'sound' => 'noti.aiff',
                            'badge' => 1
                        ),
                        'data' => array(
                            'title' => 'LOKÁCIÓ FRISSÍTVE (' . $incident["incident_id"] . ')',
                            'body' => "Koppintson a megtekintéshez...",
                            'click_action' => "FLUTTER_NOTIFICATION_CLICK",
                            'sound' => 'noti.aiff',
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
                $this->fcm_send($fields);
            }
        }
    }

    private function sendNewStatusNotification($user, $incident_id, $status)
    {
        $db = $this->DB;
        $get_members = $db->prepare("SELECT DISTINCT u.fcm_token, u.curr_platform, u.push_notification FROM users u INNER JOIN user_incidents ui ON u.id = ui.user_id WHERE ui.incident_id = ? AND u.fcm_token IS NOT NULL AND u.id != ?");
        $get_members->execute(array($incident_id, $user["id"]));
        $members = $get_members->fetchAll(PDO::FETCH_ASSOC);

        $get_inc = $db->prepare("SELECT incident_id FROM incidents WHERE id = ?");
        $get_inc->execute(array($incident_id));
        $inc = $get_inc->fetch(PDO::FETCH_ASSOC);


        foreach ($members as $member) {
            if ($member["push_notification"] == 1) {
                if ($member["curr_platform"] == 'android') {
                    $fields = array(
                        'to' => $member["fcm_token"],
                        'data' => array(
                            'title' => 'STÁTUSZ FRISSÍTVE (' . $inc["incident_id"] . ')',
                            'body' => $user["username"] . ": " . $status,
                            'click_action' => "FLUTTER_NOTIFICATION_CLICK",
                            'sound' => 'default',
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
                        'to' => $member["fcm_token"],
                        'notification' => array(
                            'title' => 'STÁTUSZ FRISSÍTVE (' . $inc["incident_id"] . ')',
                            'body' => $user["username"] . ": " . $status,
                            'sound' => 'noti.aiff',
                            'badge' => 1
                        ),
                        'data' => array(
                            'title' => 'STÁTUSZ FRISSÍTVE (' . $inc["incident_id"] . ')',
                            'body' => $user["username"] . ": " . $status,
                            'click_action' => "FLUTTER_NOTIFICATION_CLICK",
                            'sound' => 'noti.aiff',
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
                $this->fcm_send($fields);
            }
        }
    }

    public function autoCloseIncidents()
    {
        if ($this->method == "GET") {
            if ($_GET["key"] == "YI3T8O92O4WZ3JTFMXRG") {
                $db = $this->DB;
                $close = $db->prepare("UPDATE incidents SET active = 0, closed_by = 'auto', ended_datetime = NOW() WHERE active = 1 AND TIMESTAMPDIFF(HOUR, alert_datetime, NOW()) > 24");
                $close->execute(array());
            }
        }
    }

    private function getStatusName($status)
    {
        switch ($status) {
            default:
                return null;
            case "offline":
                return "NEM ELÉRHETŐ";
            case "notified":
                return "ÉRTESÍTVE";
            case "onroadhq":
                return "MEGYEK A SZERTÁRHOZ";
            case "onhq":
                return "MEGÉRKEZTEM A SZERTÁRHOZ";
            case "onroadfield":
                return "MEGYEK A HELYSZÍNRE";
            case "onfield":
                return "MEGÉRKEZTEM A HELYSZÍNRE";
        }
    }
}