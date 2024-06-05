<?php

namespace App\controllers;

use App\core\interfaces\CRUDControllerInterface;
use App\core\Core;
use PDO;

class ProfileController extends Core implements CRUDControllerInterface
{

    public function create()
    {
        // TODO: Implement create() method.
    }

    /**
     * @api {get} /profile/get Get profile
     * @apiName get profile
     * @apiGroup profile
     *
     * @apiHeader {String} JWT JWT token
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     * @apiSuccess {String} data Response user data
     */
    public function get()
    {
        try {
            $db = $this->DB;
            $user_info = $this->getCurrentUser();
            unset($user_info["password"]);

            if (!empty($this->request["fcm_token"]) && array_key_exists("isAndroid", $this->request)) {
                $curr_platform = $this->request["isAndroid"] == 1 ? 'android' : 'ios';
                // deleting current fcm token from everywhere else
                $rm_tok = $db->prepare("UPDATE users SET fcm_token = null WHERE fcm_token = ?");
                $rm_tok->execute(array($this->request["fcm_token"]));

                // storing fcm token and current platform
                $store_token = $db->prepare("UPDATE users SET fcm_token = ?, curr_platform = ? WHERE id = ?");
                $store_token->execute(array($this->request["fcm_token"], $curr_platform, $user_info["id"]));
            }


            $get_myorgs = $db->prepare("SELECT organizations.id, organizations.name FROM user_organizations LEFT JOIN organizations ON user_organizations.organization_id = organizations.id WHERE user_organizations.user_id = ?");
            $get_myorgs->execute(array($this->getCurrentUser()["id"]));
            $user_info["organizations"] = $get_myorgs->fetchAll(PDO::FETCH_ASSOC);
            array_walk_recursive($user_info, function (&$item) {
                if ($item === null) $item = '';
                $item = strval($item);
            });
            return $this->_response(array("code" => 200, "status" => "SUCCESS", "data" => array("user" => $user_info)));
        } catch(\Exception $e){
            return $this->_response(array("code" => 500, "status" => "SUCCESS", "data" => array("message" => $e->getMessage())));

        }

    }

    /**
     * @api {post} /profile/update Update profile
     * @apiName update profile
     * @apiGroup profile
     *
     * @apiHeader {String} JWT JWT token
     *
     * @apiParam {String} username username
     * @apiParam {int} push_notification push notifications enabled (0 or 1)
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     * @apiSuccess {String} data Response user data
     */
    public function update()
    {
        $db = $this->DB;
        $update_user = $db->prepare("UPDATE users SET username = ?, push_notification = ? WHERE id = ?");
        $update_user->execute(array(
            $this->request["username"],
            $this->request["push_notification"],
            $this->getCurrentUser()["id"]
        ));
        return $this->_response(array("code" => 200, "status" => "200"));
    }

    /**
     * @api {post} /profile/updateSchedule Update schedule
     * @apiName Update schedule
     * @apiGroup profile
     *
     * @apiHeader {String} JWT JWT token
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     * @apiSuccess {String} data Response user data
     */
    public function updateSchedule()
    {
        $db = $this->DB;
        $standby = $this->request["standby"];
        if (count($standby) == 7) {
            $user_id = $this->getCurrentUser()["id"];
            $del_all = $db->prepare("DELETE FROM user_schedules WHERE user_id = ?");
            $del_all->execute(array($user_id));
            for ($i = 0; $i < 7; ++$i) {
                $insert = $db->prepare("INSERT INTO user_schedules (user_id, `type`, `day`, start, `end`) VALUES(?,?,?,?,?)");
                $insert->execute(array(
                    $user_id,
                    'standby',
                    $i,
                    $standby[$i]["from"],
                    $standby[$i]["to"],
                ));
            }
            return $this->_response(array("code" => 200, "status" => "SUCCESS"));
        } else {
            return $this->_response(array("code" => 400, "status" => "WRONG_FORMAT"));
        }
    }

    /**
     * @api {get} /profile/getSchedule Get schedule
     * @apiName Get schedule
     * @apiGroup profile
     *
     * @apiHeader {String} JWT JWT token
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     * @apiSuccess {String} data Response  data
     * @apiSuccess {String} data.standby Standby hours
     */
    public function getSchedule()
    {
        $db = $this->DB;
        $user_id = $this->getCurrentUser()["id"];
        $standby = [];
        $get_standby = $db->prepare("SELECT * FROM user_schedules WHERE user_id = ? AND `type` = ?");
        $get_standby->execute(array($user_id, 'standby'));
        while ($row = $get_standby->fetch(PDO::FETCH_ASSOC)) {

            $standby[] = array(
                "from" => $row["start"],
                "to" => $row["end"]
            );
        }
        return $this->_response(array("code" => 200, "status" => "SUCCESS", "data" => array("standby" => $standby)));
    }

    public function delete()
    {
        $db = $this->DB;
        $user = $this->getCurrentUser();
        $del_from_inc = $db->prepare("DELETE FROM user_incidents WHERE user_id = ?");
        $del_from_inc->execute(array($user["id"]));
        $del_from_events = $db->prepare("DELETE FROM user_events WHERE user_id = ?");
        $del_from_events->execute(array($user["id"]));
        $del_from_org = $db->prepare("DELETE FROM user_organizations WHERE user_id = ?");
        $del_from_org->execute(array($user["id"]));
        $del_user = $db->prepare("DELETE FROM users WHERE id = ?");
        $del_user->execute(array($user["id"]));
        return $this->_response(array("code" => 200, "status" => "SUCCESS"));
    }
}