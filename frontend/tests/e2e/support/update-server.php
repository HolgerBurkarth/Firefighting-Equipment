<?php

declare(strict_types=1);

/**
 * Nur für die Browser-Tests der Updates (tests/e2e/appUpdate.spec.ts), nie auf dem Server:
 * `php -S localhost:4174 frontend/tests/e2e/support/update-server.php` (startet Playwright).
 *
 * Wie public/index.php, aber das Frontend kommt je Bereich aus einem von zwei Builds: ohne
 * Schaltdatei aus frontend/dist (alte Version), mit test-builds/state/<bereich> aus
 * test-builds/next (neue Version, gebaut in tests/e2e/globalSetup.ts). Jeder Test hat einen
 * eigenen Bereich und schaltet nur seinen. Eine Datenbank gibt es nicht.
 */

use FirefightingEquipment\Database\LazyConnection;
use FirefightingEquipment\Http\Application;
use FirefightingEquipment\Http\Frontend\FrontendAssets;
use FirefightingEquipment\Http\Frontend\FrontendHandler;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Http\Routes;

ini_set('display_errors', '0');

$frontend = dirname(__DIR__, 3);
require dirname($frontend) . '/vendor/autoload.php';

$uri = $_SERVER['REQUEST_URI'] ?? '';
$area = preg_match('#^/firefighting/([a-z0-9-]+)/#', is_string($uri) ? $uri : '', $match) === 1
    ? $match[1]
    : '';
/*
 * In WebKit erreicht page.route() keine Anfragen einer Seite, die ein Service Worker kontrolliert;
 * sie kommen hier an. Daher die öffentlichen Aufrufe der Anmeldeseite wie in tests/e2e/fixtures.ts
 * (ohne Datenbank), alles andere unter api/ als 404.
 */
$path = is_string($uri) ? (string) parse_url($uri, PHP_URL_PATH) : '';
if (preg_match('#^/firefighting/[a-z0-9-]+/(api/.*|manifest\.webmanifest)$#', $path, $api) === 1) {
    header('Cache-Control: no-store');
    if ($api[1] === 'api/v1/tenant') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'slug' => $area,
            'name' => 'Feuerwehr Test',
            'accent_color' => '#b71c1c',
            'accent_color_is_default' => true,
            'logo_url' => null,
            'logo_urls' => null,
        ]);
    } elseif (preg_match('#^api/v1/app-icon/(512|192|180)$#', $api[1], $size) === 1) {
        header('Content-Type: image/png');
        readfile(dirname($frontend) . '/resources/app-icon/app-icon-' . $size[1] . '.png');
    } elseif ($api[1] === 'manifest.webmanifest') {
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode(['name' => 'Feuerwehr Test – Geräteverwaltung', 'start_url' => '.']);
    } elseif ($api[1] === 'api/v1/auth/email/verify' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        // Wie in appUpdate.spec.ts: nur das Token aus dem Mail-Link gilt (belegt, dass es nach dem
        // Neuladen noch ankommt).
        $body = json_decode((string) file_get_contents('php://input'), true);
        header('Content-Type: application/json; charset=utf-8');
        if (is_array($body) && ($body['token'] ?? null) === 'Upd8-Token_aus-der-Mail_0123456789abcdefghijk') {
            echo json_encode(['message' => 'Ihre E-Mail-Adresse ist bestätigt.']);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'invalid_token', 'message' => 'Link ungültig.']);
        }
    } else {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'not_found', 'message' => 'Nicht im Update-Testserver.']);
    }
    return;
}

$next = $area !== '' && is_file($frontend . '/test-builds/state/' . $area);
$directory = $next ? $frontend . '/test-builds/next' : FrontendAssets::defaultDirectory();

$noDatabase = new LazyConnection(static function (): never {
    throw new RuntimeException('Keine Datenbank im Update-Testserver.');
});

new Application(
    Routes::service(),
    Routes::tenant(),
    $noDatabase,
    frontend: new FrontendHandler(new FrontendAssets($directory)),
)->handle(Request::fromGlobals())->send();
