<?php

namespace App\controllers;

use App\core\interfaces\CRUDControllerInterface;
use App\core\Core;
use PDO;

class OrganizationsController extends Core implements CRUDControllerInterface
{

    /**
     * @api {get} /organizations/list All organizations
     * @apiName list organizations
     * @apiGroup organizations
     *
     * @apiHeader {String} JWT JWT token
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     * @apiSuccess {String} data data array
     * @apiSuccess {String} data.organizations organizations
     */
    public function list()
    {
        $db = $this->DB;
        $me = $this->getCurrentUser();
        $get_org = $db->prepare("SELECT o.id, o.name, COUNT(uo.id) as members FROM organizations o LEFT JOIN user_organizations uo ON o.id = uo.organization_id AND uo.confirmed = 1  GROUP BY o.id");
        $get_org->execute(array());
        $orgs = [];
        while ($row = $get_org->fetch(PDO::FETCH_ASSOC)) {
            $am_i_member = $db->prepare("SELECT * FROM user_organizations WHERE user_id = ? AND organization_id = ?");
            $am_i_member->execute(array($me["id"], $row["id"]));
            $row["membership"] = $am_i_member->rowCount() ? true : false;
            $row["confirmed"] = $row["membership"] ? ($am_i_member->fetch(PDO::FETCH_ASSOC)["confirmed"] == 1) : false;
            $orgs[] = $row;
        }
        return $this->_response(array("code" => 200, "status" => "SUCCESS", "data" => array("organizations" => $orgs)));
    }

    /**
     * @api {get} /organizations/{id}/members Organization members
     * @apiName list organization members
     * @apiGroup organizations
     *
     * @apiHeader {String} JWT JWT token
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     * @apiSuccess {Array} data data array
     * @apiSuccess {String} data.members members list
     * @apiSuccess {String} data.members.id members id
     * @apiSuccess {String} data.members.username members username
     * @apiSuccess {String} data.members.schedule members schedule
     */
    public function getMembers()
    {
        $db = $this->DB;
        $get_members = $db->prepare("SELECT users.id, users.username, users.email FROM user_organizations LEFT JOIN users on users.id = user_organizations.user_id WHERE user_organizations.organization_id = ?");
        $get_members->execute(array($this->request["aid"]));
        $members = [];
        while ($member = $get_members->fetch(PDO::FETCH_ASSOC)) {
            if($member['id'] == 1) {
                continue;
            }
            $member['id'] = strval($member['id']);
            if(empty($member['username'])) {
                $member['username'] = $member['email'];
            }
            unset($member['email']);
            $member["schedule"] = [];
            $get_schedule = $db->prepare("SELECT `day`, start as `from`, `end` as `to` FROM user_schedules WHERE user_id = ? AND `type` = 'standby'");
            $get_schedule->execute(array($member["id"]));
            while ($sched = $get_schedule->fetch(PDO::FETCH_ASSOC)) {
                $sched['day'] = strval(  $sched['day'] );
                $sched['from'] = strval(  $sched['from'] );
                $sched['to'] = strval(  $sched['to'] );
                $member["schedule"][] = $sched;
            }
            $members[] = $member;
        }

        return $this->_response(array("code" => 200, "status" => "SUCCESS", "data" => array("members" => $members)));
    }

    /**
     * @api {post} /organizations/memberRequest Member request
     * @apiName member request
     * @apiGroup organizations
     *
     * @apiHeader {String} JWT JWT token
     * @apiParam {String} org_id Organization id
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     *
     * @apiError ORG_NOT_FOUND no organization with this id
     * @apiError ALREADY_MEMBER user is already member
     */
    public function memberRequest()
    {
        $db = $this->DB;
        $get_org = $db->prepare("SELECT * FROM organizations WHERE id = ?");
        $get_org->execute(array($this->request["aid"]));
        if ($get_org->rowCount()) {
            $user = $this->getCurrentUser();
            $get_member = $db->prepare("SELECT * FROM user_organizations WHERE user_id = ? AND organization_id = ?");
            $get_member->execute(array($user["id"], $this->request["org_id"]));
            if (!$get_member->rowCount()) {
                $add_req = $db->prepare("INSERT INTO user_organizations (user_id, organization_id, confirmed) VALUES (?,?,0)");
                $add_req->execute(array(
                    $user["id"],
                    $this->request["org_id"]
                ));
                return $this->_response(array("code" => 200, "status" => "SUCCESS"));
            } else {
                return $this->_response(array("code" => 400, "status" => "ALREADY_MEMBER"));
            }
        } else {
            return $this->_response(array("code" => 404, "status" => "ORG_NOT_FOUND"));
        }
    }

    public function getMemberRequests()
    {
        $db = $this->DB;
        $me = $this->getCurrentUser();
        if ($me["type"] == 1) {
            $get_my_orgs = $db->prepare("SELECT * FROM user_organizations WHERE user_id = ?");
            $get_my_orgs->execute(array($me["id"]));
            $requests = [];
            while ($org = $get_my_orgs->fetch(PDO::FETCH_ASSOC)) {
                $get = $db->prepare("SELECT uo.*, u.username FROM user_organizations uo LEFT JOIN users u ON uo.user_id = u.id WHERE organization_id = ? AND confirmed = 0");
                $get->execute(array($org["organization_id"]));
                while ($req = $get->fetch(PDO::FETCH_ASSOC)) {
                    $requests[] = $req;
                }
            }
            return $this->_response(array("code" => 200, "status" => "SUCCESS", "data" => array("requests" => $requests)));
        } else {
            return $this->_response(array("code" => 401, "status" => "UNAUTHORIZED"));
        }
    }

    public function acceptMemberRequest()
    {
        $db = $this->DB;
        $me = $this->getCurrentUser();
        if ($me["type"] == 1) {
            $update = $db->prepare("UPDATE user_organizations SET confirmed = 1 WHERE id = ? AND confirmed = 0");
            $update->execute(array($this->request["request_id"]));
            if ($update->rowCount()) {
                return $this->_response(array("code" => 200, "status" => "SUCCESS"));
            } else {
                return $this->_response(array("code" => 404, "status" => "REQUEST_NOT_FOUND"));
            }
        } else {
            return $this->_response(array("code" => 401, "status" => "UNAUTHORIZED"));
        }
    }

    public function create()
    {
        // TODO: Implement create() method.
    }

    public function get()
    {

    }

    public function update()
    {
        // TODO: Implement update() method.
    }

    public function delete()
    {
        // TODO: Implement delete() method.
    }

    public function addToAll()
    {
        if (!empty($this->request['id'])) {
            $db = $this->DB;
            $chck_user = $db->prepare('SELECT * FROM users WHERE id = ?');
            $chck_user->execute(array($this->request['id']));
            if($user = $chck_user->fetch(PDO::FETCH_ASSOC)) {
                echo "user: " . $user["username"] . " (" . $user["email"] . ")\r\n";
                $count = 0;
                $get_all = $db->prepare("SELECT * FROM organizations");
                $get_all->execute(array());
                while ($row = $get_all->fetch(PDO::FETCH_ASSOC)) {
                    $get_memb = $db->prepare("SELECT * FROM user_organizations WHERE user_id = ? AND organization_id = ?");
                    $get_memb->execute(array($this->request['id'], $row["id"]));
                    if (!$get_memb->rowCount()) {
                        $add = $db->prepare("INSERT INTO user_organizations SET user_id = ?, organization_id = ?");
                        $add->execute(array($this->request['id'], $row["id"]));
                        $count++;
                    }
                }
                echo "successfully added to " . $count . " organization(s).";
            } else {
                echo "user " . $this->request['id'] . " not found.";
            }
        } else {
            echo "parameter 'id' is required";
        }
    }
}