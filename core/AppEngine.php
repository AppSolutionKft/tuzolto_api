<?php

namespace App\core;

use App\core\Core;
use App\core\RouteHandler;

class AppEngine extends Core
{
    private $routeHandler;

    public function __construct()
    {
        parent::__construct();
        $this->routeHandler = new RouteHandler();
    }

    public function createResponse()
    {
        try {
            return $this->routeHandler->getResponse($this->args);
        } catch (\Exception $e) {
            switch ($e->getCode()) {
                case 400:
                    return $this->_response(array(
                        "code" => 400,
                        "status" => "BAD_REQUEST",
                        "error" => $e->getMessage()
                    ), 400);
                    break;
                case 401:
                    return $this->_response(array(
                        "code" => 401,
                        "status" => "UNAUTHORIZED",
                    ), 401);
                    break;
                case 404:
                    return $this->_response(array(
                        "code" => 404,
                        "status" => "NOT_FOUND",
                    ));
                    break;
                default:
                    return $this->_response(array(
                        "code" => 500,
                        "status" => "SERVER_FAILURE",
                        "error" => $e->getMessage()
                    ));
                    break;
            }
        }
    }

}
