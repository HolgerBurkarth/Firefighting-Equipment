<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http\Frontend;

use FirefightingEquipment\Http\AppPath;
use FirefightingEquipment\Http\CacheableResponse;
use FirefightingEquipment\Http\CachePolicy;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\Request;
use FirefightingEquipment\Tenant\InvalidTenantValueException;
use FirefightingEquipment\Tenant\TenantSlug;

/**
 * Liefert das Frontend für GET-Anfragen, zu denen es keine Route gibt (Application). Ohne
 * Datenbank und ohne Sitzung: Ob die Stadt existiert, fragt die App selbst über api/v1/tenant ab;
 * so funktioniert die Seite auch offline aus dem Service Worker.
 *
 * - assets/<datei>: Datei mit Hash im Namen, CachePolicy::Immutable; fehlt sie: 404.
 * - <datei> mit Dateiendung (sw.js, workbox-….js, build.json): CachePolicy::Revalidate; fehlt sie: 404
 *   (nie die App-Seite, sonst würde HTML als Skript geladen).
 * - alles andere (z. B. "", "geraete/12", "passwort-zuruecksetzen"): index.html mit Basispfad,
 *   CachePolicy::Revalidate und den Sicherheits-Headern.
 */
final readonly class FrontendHandler
{
    /** Pfade mit Dateiendung bekommen nie die App-Seite. */
    private const string FILE_LIKE_PATTERN = '/\.[A-Za-z0-9]{1,12}\z/';

    public function __construct(private FrontendAssets $assets)
    {
    }

    /** Nur GET und nur außerhalb von api/ (dort bleibt es bei 404 im Fehlerformat). */
    public static function handles(string $method, string $rest): bool
    {
        return $method === 'GET' && $rest !== 'api' && !str_starts_with($rest, 'api/');
    }

    /**
     * @throws HttpException 404 not_found, 503 frontend_unavailable
     */
    public function respond(Request $request, AppPath $path): CacheableResponse
    {
        if (!$path->isService()) {
            try {
                TenantSlug::fromString($path->scope);
            } catch (InvalidTenantValueException) {
                throw HttpException::notFound();
            }
        }

        $rest = $path->rest;
        if ($rest === '' || $rest === 'index.html') {
            return $this->indexHtml($request, $path);
        }
        if (str_starts_with($rest, 'assets/')) {
            return $this->file($request, $rest, CachePolicy::Immutable);
        }
        if (preg_match(self::FILE_LIKE_PATTERN, $rest) === 1) {
            return $this->file($request, $rest, CachePolicy::Revalidate);
        }

        return $this->indexHtml($request, $path);
    }

    private function file(Request $request, string $rest, CachePolicy $policy): CacheableResponse
    {
        $file = $this->assets->file($rest) ?? throw HttpException::notFound();

        return new CacheableResponse($request, $file->contentType, $file->body, $file->etag(), $policy);
    }

    private function indexHtml(Request $request, AppPath $path): CacheableResponse
    {
        try {
            $file = $this->assets->indexHtml(AppPath::PREFIX . '/' . $path->scope . '/');
        } catch (FrontendUnavailableException $e) {
            throw HttpException::frontendUnavailable($e);
        }

        return new CacheableResponse(
            $request,
            $file->contentType,
            $file->body,
            $file->etag(),
            CachePolicy::Revalidate,
            [
                'Content-Security-Policy' => ContentSecurityPolicy::header(),
                'Referrer-Policy' => 'same-origin',
                'X-Frame-Options' => 'DENY',
            ],
        );
    }
}
