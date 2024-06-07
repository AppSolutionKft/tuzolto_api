<?php
require 'vendor/autoload.php';

use App\core\AppEngine;

error_reporting(0);


session_start();
define("BASE_DIR", __DIR__);
define("HOME_DIR", "/");


$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

try {
    $engine = new AppEngine();
    echo $engine->createResponse();
} catch (Throwable $e) {
    header("HTTP/1.1 500 Internal Server Error");
    header("Content-Type: application/json");
    $response["error_code"] = $e->getCode();
    $response["message"] = $e->getMessage();
    exit(json_encode($response));
}
