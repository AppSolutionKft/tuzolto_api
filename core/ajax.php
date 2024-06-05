<?php
require '../vendor/autoload.php';

use App\core\Core;

define('BASE_DIR', __DIR__ . "/../");

session_start();
$CoreEngine = new Core();

if(isset($_SESSION["id"])){
    switch($_POST["f"]){
        default:
            exit("Could not find route for function: ".$_POST["f"]);
    }
} else {
    switch($_POST["f"]){
        default:
            exit("Could not find route for function: ".$_POST["f"]);
    }
}

echo $CoreEngine->call($controller, $method, $params);
