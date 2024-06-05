<?php

namespace App\controllers;

use App\core\interfaces\CRUDControllerInterface;
use App\core\Core;
use DateTime;
use PDO;

class EventsController extends Core implements CRUDControllerInterface
{
    public const types = [
        "community_event" => "Közösségi esemény",
        "general_meeting" => "Közgyűlés",
        "training" => "Képzés",
        "drill" => "Gyakorlat",
        "community_work" => "Közösségi munka",
        "cultural_event" => "Kulturális összejövetel",
        "other_event" => "Egyéb rendezvény",
        "own_incident" => "Káresemény",
        "own_incident_long" => "Elnyúló káresemény",
    ];

    public function update()
    {
    }

    public function get()
    {
    }

    /**
     * @api {get} /events/{id}/crewstatus Event participants
     * @apiName Event participants
     * @apiGroup events
     *
     * @apiHeader {String} JWT JWT token
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     * @apiSuccess {Array} data data
     * @apiSuccess {Array} data.participants participants
     * @apiSuccess {String} data.participants.id participants id
     * @apiSuccess {String} data.participants.username participant name
     * @apiSuccess {String} data.participants.commander 0=önkéntes 1=parancsnok
     */
    public function crewstatus()
    {
        $db = $this->DB;
        $get_event_members = $db->prepare("SELECT u.id, u.username, u.type as commander FROM user_events ue LEFT JOIN users u ON ue.user_id = u.id WHERE event_id = ?");
        $get_event_members->execute(array($this->request["aid"]));
        $statuses = $get_event_members->fetchAll(PDO::FETCH_ASSOC);
        return $this->_response(array("code" => 200, "status" => "SUCCESS", "data" => array("statuses" => $statuses)));
    }

    /**
     * @api {post} /events/{id}/participate Event participate
     * @apiName Event participate
     * @apiGroup events
     *
     * @apiHeader {String} JWT JWT token
     * @apiParam {int} incident_id incident id
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     */
    public function participate()
    {
        $db = $this->DB;
        $am_i_going = $db->prepare("SELECT * FROM user_events WHERE user_id = ? AND event_id = ?");
        $am_i_going->execute(array($this->getCurrentUser()["id"], $this->args["aid"]));
        if (!$am_i_going->rowCount()) {
            $insert = $db->prepare("INSERT INTO user_events (user_id, event_id) VALUES (?,?)");
            $insert->execute(array(
                $this->getCurrentUser()["id"],
                $this->args["aid"]
            ));
            if ($insert->rowCount()) {
                return $this->_response(array("code" => 200, "status" => "SUCCESS"));
            } else {
                return $this->_response(array("code" => 404, "status" => "EVENT_NOT_FOUND"));
            }
        } else {
            return $this->_response(array("code" => 400, "status" => "ALREADY_GOING"));
        }
    }

    public function create()
    {
        $db = $this->DB;
        try {
            $startDate = new DateTime($this->request["start_datetime"]);
            $endDate = new DateTime($this->request["end_datetime"]);
        } catch (\Exception $e) {
            return $this->_response(array("code" => 400, "status" => "WRONG_DATE_FORMAT"));
        }
        if (array_key_exists($this->request["type"], self::types)) {
            $create_event = $db->prepare("
            INSERT INTO events
                SET org_id = ?,
                `type` = ?,
                title = ?,
                `description` = ?,
                location = ?,
                lat = ?,
                lng = ?,
                start_datetime = ?,
                end_datetime = ?
            ");
            $create_event->execute(array(
                $this->request["org_id"],
                $this->request["type"],
                $this->request["title"],
                $this->request["description"],
                $this->request["location"],
                $this->request["lat"],
                $this->request["lng"],
                $startDate->format("Y-m-d H:i:s"),
                $endDate->format("Y-m-d H:i:s"),
            ));
            $get_crew = $db->prepare("SELECT user_id FROM user_organizations WHERE organization_id = ?");
            $get_crew->execute(array($this->request["org_id"]));

            $notificationParams = $this->request;
            $notificationParams["type"] = self::types[$this->request["type"]];
            $notificationParams["start_datetime"] = $startDate->format("Y. m. d.");
            while ($member_id = $get_crew->fetch(PDO::FETCH_ASSOC)["user_id"]) {
                $get_member = $db->prepare("SELECT push_notification, curr_platform, fcm_token FROM users WHERE id = ?");
                $get_member->execute(array($member_id));
                $member = $get_member->fetch(PDO::FETCH_ASSOC);
//                if ($member["push_notification"] == 1) {
                $this->sendEventNotification($member["fcm_token"], $member["curr_platform"], $notificationParams);
//                }
            }
            return $this->_response(array("code" => 200, "status" => "SUCCESS"));
        } else {
            return $this->_response(array("code" => 400, "status" => "INVALID_TYPE"));
        }
    }

    public function delete()
    {
        $db = $this->DB;
        $get_event = $db->prepare("SELECT * FROM events WHERE id = ?");
        $get_event->execute(array($this->args["aid"]));
        if ($get_event->rowCount()) {
            $event = $get_event->fetch(PDO::FETCH_ASSOC);
            $del_members = $db->prepare("DELETE FROM user_events WHERE event_id = ?");
            $del_members->execute(array($event["id"]));
            $del_event = $db->prepare("DELETE FROM events WHERE id = ?");
            $del_event->execute(array($event["id"]));
            return $this->_response(array("code" => 200, "status" => "SUCCESS"));
        } else {
            return $this->_response(array("code" => 404, "status" => "EVENT_NOT_FOUND"));
        }
    }

    public function getTypesList()
    {
        $db = $this->DB;
        $data = [];
        foreach (self::types as $typeKey => $typeName) {
            $data[] = array(
                'key' => $typeKey,
                'name' => $typeName
            );
        }
        return $this->_response(array("code" => 200, "status" => "SUCCESS", "data" => $data));
    }

    private function sendEventNotification($token, $platform, $data)
    {
        if (empty($token)) return;
        if ($platform == 'android') {
            $fields = array(
                'to' => $token,
                "priority" => "high",
                'data' => array(
                    'title' => $data["title"],
                    'body' => $data["location"] . ", " . $data['start_datetime'],
                    'click_action' => "FLUTTER_NOTIFICATION_CLICK",
                    'sound' => 'event',
                    'status' => 'done',
                    'screen' => 'screenA',
                    'message' => 'FIRE_ALERT'
                )
            );
        } else {
            $fields = array(
                'to' => $token,
                'notification' => array(
                    'title' => $data["title"],
                    'body' => $data["type"] . ", " . $data['start_datetime'],
                    'sound' => 'event_new.aiff'
                ),
                'data' => array(
                    'title' => $data["title"],
                    'body' => $data["type"] . ", " . $data['start_datetime'],
                    'click_action' => "FLUTTER_NOTIFICATION_CLICK",
                    'sound' => 'eventNew',
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
}