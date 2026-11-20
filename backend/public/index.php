<?php
declare(strict_types=1);

ini_set('display_errors', '0');
require dirname(__DIR__) . '/bootstrap/autoload.php';
$response = (new App\Http\Application())->handle(App\Http\Request::fromGlobals());
http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
echo $response->body;
