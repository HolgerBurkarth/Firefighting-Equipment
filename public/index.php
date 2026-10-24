<?php

declare(strict_types=1);

use FirefightingEquipment\Http\JsonResponse;

require dirname(__DIR__) . '/vendor/autoload.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? rtrim($path, '/') : '';

// Mandant und Router folgen später; vorerst nur der Health-Check.
if ($method === 'GET' && str_ends_with($path, '/api/v1/health')) {
    $response = new JsonResponse(['status' => 'ok']);
} else {
    $response = new JsonResponse(['error' => 'not_found'], 404);
}

$response->send();
