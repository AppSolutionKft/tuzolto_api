<?php

namespace App\core;

use App\controllers\AuthController;
use Firebase\JWT\JWT;
use PDO;

class Core
{
    const MAINTENANCE = false;

    private $dbconfig;
    const firebaseServerKey = "AAAAR6zuwiA:APA91bErq8ueBfeDie_lkOAbw4xg3UZzGjgqXYxk4p68V9pWO1Fix3kZv5WWTRHTu7hJSt_SvH1JjZe1DjPz9t9au9A_HmMX2LO9jjKbnIiBclq2f8PcfVnlbXxU3ec0fQ28LTFXRTQk";

    protected $method;
    protected $args;
    protected $request;
    protected $DB;

    protected function __dbconnect()
    {
        $this->dbconfig = [
            "host" => $_ENV["DB_HOST"],
            "database" => $_ENV["DB_NAME"],
            "user" => $_ENV["DB_USER"],
            "pw" => $_ENV["DB_PASSWORD"],
            "port" => $_ENV["DB_PORT"]
        ];
        $dsn = "mysql:host={$this->dbconfig['host']};dbname={$this->dbconfig['database']};port={$this->dbconfig['port']}";
        $this->DB = new PDO($dsn, $this->dbconfig["user"], $this->dbconfig["pw"], array(PDO::MYSQL_ATTR_INIT_COMMAND => 'set names utf8'));
    }

    protected function _response($data, $status = 200)
    {
        http_response_code($status);
        header("Content-Type: application/json");
        return json_encode($data);
    }

    public function dump($var)
    {
        echo "<pre>";
        var_dump($var);
        echo "</pre>";
    }

    public function call($controller, $method, $params)
    {
        $class = "App\\controllers\\" . $controller;
        if (class_exists($class)) {
            $ins = new $class;
            if (method_exists($ins, $method)) {
                return $ins->$method($params);
            } else {
                die("Method \"" . $method . "\" not found in class \"" . $controller . "\"<br />");
            }
        } else {
            die("Class not found: " . $controller . "<br />");
        }
    }

    /**
     * Core constructor.
     * @throws \Exception
     */
    public function __construct()
    {
        $this->__dbconnect();
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->args = $_GET;
        if ($this->method == 'POST' && array_key_exists('HTTP_X_HTTP_METHOD', $_SERVER)) {
            if ($_SERVER['HTTP_X_HTTP_METHOD'] == 'DELETE') {
                $this->method = 'DELETE';
            } else if ($_SERVER['HTTP_X_HTTP_METHOD'] == 'PUT') {
                $this->method = 'PUT';
            } else {
                throw new \Exception("Unexpected Header");
            }
        }
        switch ($this->method) {
            case 'DELETE':
                break;
            case 'POST':
                $in = file_get_contents('php://input');
                $this->request = $this->_cleanInputs(json_decode($in, true));
                break;
            case 'GET':
                $this->request = $this->_cleanInputs($_GET);
                break;
            case 'PUT':
                $this->request = $this->_cleanInputs($_GET);
                $this->file = file_get_contents("php://input");
                break;
            default:
                $this->_response('Invalid Method', 405);
                break;
        }
    }


    private function _cleanInputs($data)
    {
        $clean_input = array();
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $clean_input[$k] = $this->_cleanInputs($v);
            }
        } else {
            $clean_input = trim(strip_tags($data));
        }
        return $clean_input;
    }

    protected function getCurrentUser()
    {
        $db = $this->DB;
        if (!isset($_SERVER['HTTP_JWT'])) return false;
        $token = $_SERVER['HTTP_JWT'];
        try {
            $decoded = JWT::decode($token, AuthController::JWT_KEY, array('HS256'));
            $id = $decoded->data->id;
            $get_user = $db->prepare("SELECT * FROM users WHERE id = ?");
            $get_user->execute(array($id));
            return $get_user->fetch(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function fcm_send($fields)
    {
        return FCM::sendNotification(array($fields['to']), $fields);
    }

    public static function fcm_send_legacy($fields)
    {

        $headers = array('Authorization: key=' . self::firebaseServerKey, 'Content-Type: application/json');
        $url = "https://fcm.googleapis.com/fcm/send";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        $result = curl_exec($ch);
        curl_close($ch);

        file_put_contents(BASE_DIR . "/log/log_" . date("Ymd") . ".json", "NOTIFICATION_ATTEMPT: " . date("Ymd-his") . "_" . json_encode($fields) . "_" . "RESULT: " . $result . "\r\n", FILE_APPEND);

        return json_decode($result, true);
    }

    protected function uuid($withoutP = false)
    {
        try {
            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
            if ($withoutP) {
                return vsprintf('%s%s%s%s%s%s%s%s', str_split(bin2hex($data), 4));
            } else {
                return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
            }
        } catch (\Exception $e) {
            exit($e);
        }

    }
}
