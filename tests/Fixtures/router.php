<?php

// Router script for PHP's built-in web server: a tiny fake backend used by the health-check tests.

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

switch ($path) {
    case '/ok':
        http_response_code(200);
        echo 'ok';
        break;
    case '/created':
        http_response_code(201);
        break;
    case '/redirect':
        header('Location: /ok', true, 302);
        break;
    case '/moved':
        header('Location: /ok', true, 301);
        break;
    case '/forbidden':
        http_response_code(403);
        break;
    case '/unauthorized':
        http_response_code(401);
        break;
    case '/error':
        http_response_code(500);
        break;
    case '/slow':
        sleep(8);
        echo 'slow';
        break;
    default:
        http_response_code(404);
}
