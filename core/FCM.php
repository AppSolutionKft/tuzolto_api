<?php

namespace App\core;

use Kreait\Firebase\Exception\FirebaseException;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\RawMessageFromArray;

class FCM
{

    /**
     * @param $tokens
     * @param $title
     * @param $message
     * @param string $navigateTo
     * @return false|string
     * @throws FirebaseException
     * @throws MessagingException
     */
    public static function sendNotification($tokens, $fields)
    {
        $result = array('success' => 0, 'failed' => 0);
        try {
            $factory = (new Factory)->withServiceAccount($_SERVER["DOCUMENT_ROOT"] . '/modules/firebase/cred.json');
            $sToken = array();
            $uTokens = array_values(array_unique($tokens));
            if (count($uTokens) > 500) {
                $sToken = array_chunk($uTokens, 500);
            } else {
                $sToken[0] = $uTokens;
            }
            foreach ($sToken as $ktokens) {
                $message = new RawMessageFromArray($fields);
                $sendReport = ($factory->createMessaging())->sendMulticast($message, $ktokens);
                foreach ($sendReport->successes()->getItems() as $sItem) {
                    try {
                        $token = $sItem->message()->jsonSerialize()["token"];
                    } catch (\Exception $e) {
                    }
                }
                foreach ($sendReport->failures()->getItems() as $sItem) {
                    try {
                        $token = $sItem->message()->jsonSerialize()["token"];
                    } catch (\Exception $e) {
                    }
                }
                $result = array(
                    'success' => $sendReport->successes()->count(),
                    'failed' => $sendReport->failures()->count(),
                );
            }
        } catch (\Exception $e) {
        }
        return json_encode(array("status"=>200, "result"=>$result));
    }
}