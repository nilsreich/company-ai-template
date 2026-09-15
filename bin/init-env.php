<?php

$path = __DIR__.'/../.env';
$content = file_get_contents($path);
if ($content === false) {
    throw new RuntimeException('Missing .env');
}
$content = str_replace("APP_KEY=\n", 'APP_KEY=base64:'.base64_encode(random_bytes(32))."\n", $content);
$content = str_replace("DB_PASSWORD=\n", 'DB_PASSWORD='.bin2hex(random_bytes(24))."\n", $content);
$content = str_replace('DEV_LOGIN_ENABLED=false', 'DEV_LOGIN_ENABLED=true', $content);
file_put_contents($path, $content);
chmod($path, 0600);
