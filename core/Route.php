<?php

namespace App\core;

use App\controllers\AuthController;

class Route
{
    const ROUTE_PROTECTED = 0;
    const ROUTE_PUBLIC = 1;

    private $route_type;
    private $controller;
    private $method;
    private $requiredParams;

    public function __construct($route_type, $controller, $method, $requiredParams)
    {
        $this->route_type = $route_type;
        $this->controller = $controller;
        $this->method = $method;
        $this->requiredParams = $requiredParams;
    }

    /**
     * @return mixed
     * @throws \Exception
     */
    public function call()
    {
        $class = "App\\controllers\\" . $this->controller;
        if (class_exists($class)) {
            $controller = new $class;
            if (method_exists($controller, $this->method)) {
                $method = $this->method;
                $params = $_REQUEST;
                $jsonParams = json_decode(file_get_contents("php://input"), true);
                if(is_array($jsonParams)) {
                    $params = array_merge($params, $jsonParams);
                }
                $missingParams = $this->validateParams($params);
                if(empty($missingParams)) {
                    if ($this->route_type == Route::ROUTE_PUBLIC) {
                        return $controller->$method();
                    } else {
                        $ac = new AuthController();
                        if ($ac->validateUser()) {
                            return $controller->$method();
                        } else {
                            throw new \Exception("Method \"" . $this->method . "\" unauthorized", 401);
                        }
                    }
                } else {
                    throw new \Exception("Missing parameters: ".implode(', ', $missingParams), 400);
                }
            } else {
                throw new \Exception("Method \"" . $this->method . "\" not found in class " . $this->controller . "\"", 404);
            }
        } else {
            throw new \Exception("Class not found: " . $this->controller, 404);
        }
    }

    private function validateParams($body)
    {
        $missing = array();
        foreach ($this->requiredParams as $param) {
            if (empty($body) || !array_key_exists($param, $body)) {
                $missing[] = $param;
            }
        }
        return $missing;
    }

    private function validateUser(){

    }
}
