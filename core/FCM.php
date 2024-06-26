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
     * @return array
     * @throws FirebaseException
     * @throws MessagingException
     */
    public static function sendNotification($tokens, $fields)
    {
        $success = 0;
        $result = array('success' => $success, 'failed' => 0);
        try {
            $factory = (new Factory)->withServiceAccount($_SERVER["DOCUMENT_ROOT"] . 'tuzolto-app-1591806201105-firebase-adminsdk-yr451-8895c47a95.json');
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
//                foreach ($sendReport->successes()->getItems() as $sItem) {
//                    file_put_contents('error.log', json_encode($sItem->message()->jsonSerialize()) . "\r\n", FILE_APPEND);
//                }
                foreach ($sendReport->failures()->getItems() as $sItem) {
                    file_put_contents('error.log', date('Y-m-d H:i:s') . ' notification error: ' . json_encode($sItem->error()->getMessage()) . "\r\n", FILE_APPEND);
                }
                $success = $sendReport->successes()->count();
                $result = array(
                    'success' => $sendReport->successes()->count(),
                    'failed' => $sendReport->failures()->count(),
                );
            }
        } catch (\Exception $e) {
            file_put_contents('error.log', $e->getMessage() . "\r\n", FILE_APPEND);
        }
        return array("status" => 200, "success" => $success, "result" => $result);
    }
}