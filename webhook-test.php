<?php
file_put_contents("webhook.json", json_encode($_REQUEST) . "\r\n", FILE_APPEND);
file_put_contents("webhook_body.json", json_encode(file_get_contents("php://input")) . "\r\n", FILE_APPEND);