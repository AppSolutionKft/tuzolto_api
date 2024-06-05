<?php

namespace App\controllers;

use \Firebase\JWT\JWT;
use App\core\Core;
use PDO;

class AuthController extends Core
{
    public static $permissions = array(
        "user",
        "admin"
    );

    public const JWT_KEY = "106657C8018A740F467FACE3BB657245D51434259F09C26982DD7D1848F630C9BE8E6138045FC85E5BA1414723B255049DA6F0B5B5056DF";

    public function validateUser()
    {
        if (!isset($_SERVER['HTTP_JWT'])) return false;
        $token = $_SERVER['HTTP_JWT'];
        try {
            $decoded = JWT::decode($token, AuthController::JWT_KEY, array('HS256'));
            if ($decoded->data->id) {
                $db = $this->DB;
                $check_session = $db->prepare("SELECT * FROM user_sessions WHERE user_id = ? AND session_id = ?");
                $check_session->execute(array($decoded->data->id, $decoded->data->session_id));
                if ($check_session->rowCount()) {
                    return true;
                } else {
                    return false;
                }
            } else {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @api {post} /auth/login Login
     * @apiName Login
     * @apiGroup User
     *
     * @apiParam {String} email User email
     * @apiParam {String} password User password
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     * @apiSuccess {String} data data array
     * @apiSuccess {String} data.token JWT token
     * @apiSuccess {String} data.userdata user data
     */
    /**
     * /auth/login
     * @return false|string
     */
    public function login()
    {
        $db = $this->DB;
        if (isset($this->request["email"]) && isset($this->request["password"])) {
            if (mb_strlen($this->request["password"]) >= 6) {
                $check_auth = $db->prepare("SELECT * FROM users WHERE email = ? AND password = ? AND type IN (0,1) AND email_confirmed = 1");
                $check_auth->execute(array($this->request["email"], hash("sha256", $this->request["password"])));
                if ($check_auth->rowCount()) {
                    $user = $check_auth->fetch(PDO::FETCH_ASSOC);
                    $rm_session = $db->prepare("DELETE FROM user_sessions WHERE user_id = ?");
                    $rm_session->execute(array($user["id"]));
                    $session_id = $this->uuid();
                    $create_session = $db->prepare("INSERT INTO user_sessions(user_id, session_id) VALUES(?,?)");
                    $create_session->execute(array($user["id"], $session_id));
                    $payload = array(
                        "iss" => "https://tuzolto.appsolution.hu/api",
                        "aud" => "https://tuzolto.appsolution.hu/api",
                        "iat" => time(),
                        "nbf" => time(),
                        "data" => array(
                            "id" => $user["id"],
                            "session_id" => $session_id
                        )
                    );
                    $jwt = JWT::encode($payload, AuthController::JWT_KEY);

                    if (!empty($this->request["fcm_token"]) && array_key_exists("isAndroid", $this->request)) {
                        $curr_platform = $this->request["isAndroid"] == 1 ? 'android' : 'ios';
                        // deleting current fcm token from everywhere else
                        $rm_tok = $db->prepare("UPDATE users SET fcm_token = null WHERE fcm_token = ?");
                        $rm_tok->execute(array($this->request["fcm_token"]));

                        // storing fcm token and current platform
                        $store_token = $db->prepare("UPDATE users SET fcm_token = ?, curr_platform = ? WHERE id = ?");
                        $store_token->execute(array($this->request["fcm_token"], $curr_platform, $user["id"]));
                    } else {
                        file_put_contents("login.log", date("Ymd_his") . "no fcm token: " . json_encode($this->request) . "\r\n");
                    }
                    $store_login = $db->prepare("UPDATE users SET lastlogin = NOW() WHERE id = ?");
                    $store_login->execute(array($user["id"]));
                    unset($user["password"]);
                    return $this->_response(array("code" => 200, "status" => "SUCCESS", "data" => array("token" => $jwt, "userdata" => $user)));
                } else {
                    return $this->_response(array("code" => 401, "status" => "INVALID_CREDENTIALS"));
                }
            } else {
                return $this->_response(array("code" => 400, "status" => "WRONG_PASSWORD_FORMAT"));
            }
        } else {
            return $this->_response(array("code" => 400, "status" => "MISSING_PARAMS"));
        }
    }

    /**
     * @api {post} /auth/register Register
     * @apiName Register
     * @apiGroup User
     *
     * @apiParam {String} email User email
     * @apiParam {String} password User password
     * @apiParam {String} username Username
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     * @apiSuccess {String} data data array
     * @apiSuccess {String} data.token JWT token
     */
    /**
     * @return false|string
     */
    public function register()
    {
        $db = $this->DB;
        if (isset($this->request["email"]) && isset($this->request["password"])) {
            if (mb_strlen($this->request["password"]) >= 8) {
                $check_exists = $db->prepare("SELECT * FROM users WHERE email = ? ");
                $check_exists->execute(array($this->request["email"]));
                if (!$check_exists->rowCount()) {
                    $token = $this->uuid();

                    $create = $db->prepare("INSERT INTO users(email, password, username, type, tmp_token) VALUES (?, ?, ?, 0, ?)");
                    $create->execute(array(
                        $this->request["email"],
                        hash('sha256', $this->request["password"]),
                        $this->request["username"],
                        $token
                    ));

                    $email = $this->request["email"];
                    $subject = 'Sikeres regisztráció | ÖTRA';

                    $message = file_get_contents(__DIR__ . "/../../admin/assets/email/confirm.html");
                    $link = 'https://' . $_SERVER["HTTP_HOST"] . '/admin/auth/confirm?token=' . $token;
                    $message = str_replace("%%redir_url%%", $link, $message);
                    $message = str_replace("%%name%%", $this->request["username"], $message);
                    $message = str_replace("%%img_path%%", "https://" . $_SERVER["HTTP_HOST"] . "/admin/assets/media/image/logo70x110.png", $message);

                    $headers = "From: ÖTRA <" . strip_tags("no-reply@appsolution.hu") . ">\r\n";
                    $headers .= "Reply-To: " . strip_tags("no-reply@appsolution.hu") . "\r\n";
                    $headers .= "MIME-Version: 1.0\r\n";
                    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                    mail($email, $subject, $message, $headers);
                    return $this->_response(array("code" => 200, "status" => "SUCCESS"));
                } else {
                    $email = $this->request["email"];
                    $subject = 'Információ | ÖTRA';

                    $message = file_get_contents(__DIR__ . "/../../admin/assets/email/already.html");
                    $message = str_replace("%%name%%", $this->request["username"], $message);
                    $message = str_replace("%%img_path%%", "https://" . $_SERVER["HTTP_HOST"] . "/admin/assets/media/image/logo70x110.png", $message);

                    $headers = "From: ÖTRA <" . strip_tags("no-reply@appsolution.hu") . ">\r\n";
                    $headers .= "Reply-To: " . strip_tags("no-reply@appsolution.hu") . "\r\n";
                    $headers .= "MIME-Version: 1.0\r\n";
                    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                    mail($email, $subject, $message, $headers);
                    return $this->_response(array("code" => 200, "status" => "SUCCESS"));
                }
            } else {
                return $this->_response(array("code" => 400, "status" => "WRONG_PASSWORD_FORMAT"));
            }
        } else {
            return $this->_response(array("code" => 400, "status" => "MISSING_PARAMS"));
        }
    }

    /**
     * @api {post} /auth/forgotpassword Forgot password
     * @apiName Forgot password
     * @apiGroup User
     *
     * @apiParam {String} email User email
     *
     * @apiSuccess {String} code Response code
     * @apiSuccess {String} status Response status message
     * @apiSuccess {String} data data array
     */

    /**
     * @return false|string
     */
    public function forgotpassword()
    {
        $db = $this->DB;
        if (isset($this->request["email"])) {
            $token = $this->uuid();
            $update = $db->prepare("UPDATE users SET tmp_token = ? WHERE email = ?");
            $update->execute(array($token, $this->request["email"]));
            if ($update->rowCount()) {
                $get_user = $db->prepare("SELECT username FROM users WHERE email = ?");
                $get_user->execute(array($this->request["email"]));
                $user = $get_user->fetch(PDO::FETCH_ASSOC);
                $email = $this->request["email"];
                $subject = 'Elfelejtett jelszó | ÖTRA';

                $message = file_get_contents(__DIR__ . "/../../admin/assets/email/forgot.html");
                $link = 'https://' . $_SERVER["HTTP_HOST"] . '/admin/auth/forgot?token=' . $token;
                $message = str_replace("%%redir_url%%", $link, $message);
                $message = str_replace("%%name%%", $user["username"], $message);
                $message = str_replace("%%img_path%%", "https://" . $_SERVER["HTTP_HOST"] . "/admin/assets/media/image/logo70x110.png", $message);

                $headers = "From: ÖTRA <" . strip_tags("no-reply@appsolution.hu") . ">\r\n";
                $headers .= "Reply-To: " . strip_tags("no-reply@appsolution.hu") . "\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                mail($email, $subject, $message, $headers);
            } else {
                // nincs a db-ben
                $email = $this->request["email"];
                $subject = 'Elfelejtett jelszó | ÖTRA';

                $message = file_get_contents(__DIR__ . "/../../admin/assets/email/unknown.html");
                $message = str_replace("%%img_path%%", "https://" . $_SERVER["HTTP_HOST"] . "/admin/assets/media/image/logo70x110.png", $message);

                $headers = "From: ÖTRA <" . strip_tags("no-reply@appsolution.hu") . ">\r\n";
                $headers .= "Reply-To: " . strip_tags("no-reply@appsolution.hu") . "\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                mail($email, $subject, $message, $headers);
            }
            return $this->_response(array("code" => 200, "status" => "SUCCESS"));
        } else {
            return $this->_response(array("code" => 400, "status" => "MISSING_PARAMS"));
        }
    }

    public function logout()
    {
        unset($_SESSION["id"]);
        header("Location: /auth/login");
    }
}
