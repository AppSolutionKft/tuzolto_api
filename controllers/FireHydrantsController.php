<?php

namespace App\controllers;

use App\core\interfaces\CRUDControllerInterface;
use App\core\Core;
use PDO;

class FireHydrantsController extends Core
{
    public function setFireHydrantLocations()
    {
        set_time_limit(0);

        $db = $this->DB;
        $start_id = 14260;

        /** @var  $get_hydrants */
        $get_hydrants = $db->prepare("SELECT * FROM fire_hydrants WHERE id > :startId AND lat IS NULL AND lng IS NULL");
        $get_hydrants->execute(array(
            ':startId' => $start_id
        ));

        $data = [];

        while ($hydrant = $get_hydrants->fetch(PDO::FETCH_ASSOC)) {
            // https://nominatim.openstreetmap.org/search.php?q=%C3%89cs%09%C3%96reg+%C3%BAt+30.&format=jsonv2

            $address = $hydrant['city'] . ' ' . $hydrant['name'];
            $osmResponse = $this->osmSearch($address);
            if(empty($osmResponse)) {
                $address = str_replace('út', 'utca', $address);
                $osmResponse = $this->osmSearch($address);
            }

            $data[] = array(
                'hydrant' => $hydrant,
                'resp' => $osmResponse
            );

            if(!empty($osmResponse)) {
                $update_hydrant = $db->prepare("UPDATE fire_hydrants SET lat = :lat, lng = :lng WHERE id = :id");
                $update_hydrant->execute(array(
                    ':lat' => $osmResponse[0]['lat'],
                    ':lng' => $osmResponse[0]['lon'],
                    ':id' => $hydrant['id']
                ));
            }

            sleep(1);
        }

        return $this->_response(array("code" => 200, "status" => "SUCCESS", "data" => $data));
    }

    private function osmSearch($address)
    {
        $url = "https://nominatim.openstreetmap.org/search.php?q=" . urlencode($address) . "&format=jsonv2";

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_REFERER, $_SERVER["HTTP_REFERER"]);

        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            echo 'Error:' . curl_error($ch);
        }
        curl_close($ch);

        return json_decode($result, true);
    }
}