<?php

namespace App\core;

use App\controllers\IncidentsController;

class RouteHandler extends Core
{
    private function getRoute($request)
    {
        $routeType = null;
        $controller = null;
        $method = null;
        $requiredParams = null;
        switch ($request["module"] ?? null) {
            case "auth":
                $controller = "AuthController";
                switch (array_key_exists('aid', $request) ? $request["aid"] : null) {
                    case "register":
                        $requiredParams = array("email", "password");
                        $routeType = Route::ROUTE_PUBLIC;
                        $method = "register";
                        break;
                    case "login":
                        $requiredParams = array();
                        $routeType = Route::ROUTE_PUBLIC;
                        $method = "login";
                        break;
                    case "forgotpassword":
                        $requiredParams = array();
                        $routeType = Route::ROUTE_PUBLIC;
                        $method = "forgotpassword";
                        break;
                    default:
                        return null;
                }
                break;
            case "alerts":
                switch (array_key_exists('aid', $request) ? $request["aid"] : null) {
                    case "getEmails":
                        $requiredParams = array();
                        $controller = "AlertsController";
                        $routeType = Route::ROUTE_PUBLIC;
                        $method = "getEmails";
                        break;
                    case "testEmailBody":
                        $requiredParams = array();
                        $controller = "AlertsController";
                        $routeType = Route::ROUTE_PUBLIC;
                        $method = "testEmailBody";
                        break;
                    default:
                        return null;
                }
                break;
            case "profile":
                switch (array_key_exists('aid', $request) ? $request["aid"] : null) {
                    case "get":
                        $requiredParams = array();
                        $controller = "ProfileController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "get";
                        break;
                    case "delete":
                        $requiredParams = array();
                        $controller = "ProfileController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "delete";
                        break;
                    case "update":
                        $requiredParams = array();
                        $controller = "ProfileController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "update";
                        break;
                    case "updateSchedule":
                        $requiredParams = array();
                        $controller = "ProfileController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "updateSchedule";
                        break;
                    case "getSchedule":
                        $requiredParams = array();
                        $controller = "ProfileController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "getSchedule";
                        break;
                    default:
                        return null;
                }
                break;
            case "organizations":
                switch (array_key_exists('aid', $request) ? $request["aid"] : null) {
                    case "list":
                        $requiredParams = array();
                        $controller = "OrganizationsController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "list";
                        break;
                    case $request["aid"]:
                        $requiredParams = array();
                        $controller = "OrganizationsController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "getMembers";
                        break;
                    case "memberRequest":
                        $requiredParams = array();
                        $controller = "OrganizationsController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "memberRequest";
                        break;
                    case "getMemberRequests":
                        $requiredParams = array();
                        $controller = "OrganizationsController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "getMemberRequests";
                        break;
                    default:
                        return null;
                }
                break;
            case "incidents":
                switch (array_key_exists('aid', $request) ? $request["aid"] : null) {
                    case "getFireHydrants":
                        $requiredParams = array("lat_from", "lat_to", "lng_from", "lng_to");
                        $controller = "IncidentsController";
                        $routeType = Route::ROUTE_PUBLIC;
                        $method = "getFireHydrants";
                        break;
                    case "autoCloseIncidents":
                        $requiredParams = array();
                        $controller = "IncidentsController";
                        $routeType = Route::ROUTE_PUBLIC;
                        $method = "autoCloseIncidents";
                        break;
                    case "create":
                        $requiredParams = array();
                        $controller = "IncidentsController";
                        $routeType = Route::ROUTE_PUBLIC;
                        $method = "testIncident";
                        break;
                    case "notifyMember":
                        $requiredParams = array();
                        $controller = "IncidentsController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "notifyMember";
                        break;
                    case "active":
                        $requiredParams = array();
                        $controller = "IncidentsController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "list";
                        break;
                    case "mystatus":
                        $requiredParams = array();
                        $controller = "IncidentsController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "mystatus";
                        break;
                    case "end":
                        $requiredParams = array();
                        $controller = "IncidentsController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "end";
                        break;
                    case "updateLocation":
                        $requiredParams = array();
                        $controller = "IncidentsController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "updateLocation";
                        break;
                    case "email_webhook":
                        $requiredParams = array();
                        $controller = "IncidentsController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "emailWebhook";
                        break;
                    case $request["aid"]:
                        switch (array_key_exists('aid2', $request) ? $request["aid2"] : null) {
                            case "get":
                                $requiredParams = array();
                                $controller = "IncidentsController";
                                $routeType = Route::ROUTE_PROTECTED;
                                $method = "get";
                                break;
                            case "crewstatus":
                                $requiredParams = array();
                                $controller = "IncidentsController";
                                $routeType = Route::ROUTE_PROTECTED;
                                $method = "crewstatus";
                                break;
                        }
                        break;
                    default:
                        return null;
                }
                break;
            case "events":
                switch (array_key_exists('aid', $request) ? $request["aid"] : null) {
                    case "create":
                        $requiredParams = array(
                            "org_id",
                            "type",
                            "title",
                            "description",
                            "location",
                            "lat",
                            "lng",
                            "start_datetime",
                            "end_datetime",
                        );
                        $controller = "EventsController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "create";
                        break;
                    case "types_list":
                        $requiredParams = array();
                        $controller = "EventsController";
                        $routeType = Route::ROUTE_PROTECTED;
                        $method = "getTypesList";
                        break;
                    case $request["aid"]:
                        switch (array_key_exists('aid2', $request) ? $request["aid2"] : null) {
                            case "crewstatus":
                                $requiredParams = array();
                                $controller = "EventsController";
                                $routeType = Route::ROUTE_PROTECTED;
                                $method = "crewstatus";
                                break;
                            case "delete":
                                $requiredParams = array();
                                $controller = "EventsController";
                                $routeType = Route::ROUTE_PROTECTED;
                                $method = "delete";
                                break;
                            case "participate":
                                $requiredParams = array();
                                $controller = "EventsController";
                                $routeType = Route::ROUTE_PROTECTED;
                                $method = "participate";
                                break;
                            default:
                                return null;
                        }
                        break;
                    default:
                        return null;
                }
                break;
            case "addall":
                $requiredParams = array();
                $controller = "OrganizationsController";
                $routeType = Route::ROUTE_PUBLIC;
                $method = "addToAll";
                break;
            case "fire_hydrants":
                $controller = "FireHydrantsController";
                switch (array_key_exists('aid', $request) ? $request["aid"] : null) {
                    case "set_locations":
                        $routeType = Route::ROUTE_PUBLIC;
                        $method = "setFireHydrantLocations";
                        break;
                    default:
                        return null;
                }
                break;
            default:
                return null;
        }
        return new Route($routeType, $controller, $method, $requiredParams);
    }

    /**
     * @param $request
     * @return array|mixed
     * @throws \Exception
     */
    public function getResponse($request)
    {
        $route = $this->getRoute($request);
        if ($route != null) {
            return $route->call();
        } else {
            throw new \Exception("Route not found", 404);
        }
    }
}
