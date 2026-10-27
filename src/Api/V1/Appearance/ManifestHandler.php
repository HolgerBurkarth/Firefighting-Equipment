<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Appearance;

use FirefightingEquipment\Http\CacheableResponse;
use FirefightingEquipment\Http\CachePolicy;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Tenant\Logo\LogoSize;
use FirefightingEquipment\Tenant\TenantAppearanceRepository;

/**
 * GET manifest.webmanifest (öffentlich, /firefighting/<kürzel>/manifest.webmanifest): Web-App-Manifest
 * der Stadt mit Akzentfarbe als theme_color und dem Logo (sonst Standard-Symbol) in 192 und 512 px.
 * CachePolicy::Revalidate mit ETag über den Inhalt.
 *
 * start_url ist die Startseite der Stadt im Frontend (= scope); den Link auf das Manifest setzt das
 * Frontend nur unter Städten (src/runtime/appearance.ts). Das Standard-Symbol liefert das Backend
 * (DefaultAppIcon), nicht das Frontend.
 */
final readonly class ManifestHandler implements RequestHandler
{
    public const string NAME_FORMAT = '%s – Geräteverwaltung';
    public const string SHORT_NAME = 'FF-Geräte';

    /**
     * Hintergrund des Ladebildschirms beim Start der installierten App: --bg (hell) aus
     * frontend/src/styles/tokens.css (per Test gleich). Das Manifest kennt nur einen Wert, daher hell.
     */
    public const string BACKGROUND_COLOR = '#f3f4f6';

    /** Größen im Manifest (180 ist nur das apple-touch-icon) */
    private const array ICON_SIZES = [LogoSize::Medium, LogoSize::Large];

    public function handle(RequestContext $context): CacheableResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $appearance = new TenantAppearanceRepository($context->db->get())->find($tenant);
        $area = AppearanceJson::areaPath($tenant);

        $icons = [];
        foreach (self::ICON_SIZES as $size) {
            $icons[] = [
                'src' => $appearance->logoVersion === null
                    ? AppearanceJson::appIconUrl($tenant, $size)
                    : AppearanceJson::logoUrl($tenant, $appearance->logoVersion, $size),
                'sizes' => sprintf('%1$dx%1$d', $size->value),
                'type' => 'image/png',
                'purpose' => 'any',
            ];
        }

        $body = json_encode([
            'id' => $area,
            'name' => sprintf(self::NAME_FORMAT, $tenant->name->value),
            'short_name' => self::SHORT_NAME,
            'lang' => 'de',
            'dir' => 'ltr',
            'start_url' => $area,
            'scope' => $area,
            'display' => 'standalone',
            'theme_color' => $appearance->effectiveAccentColor()->value,
            'background_color' => self::BACKGROUND_COLOR,
            'icons' => $icons,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new CacheableResponse(
            $context->request,
            CacheableResponse::MANIFEST,
            $body,
            sprintf('"%s"', substr(hash('sha256', $body), 0, 16)),
            CachePolicy::Revalidate,
        );
    }
}
