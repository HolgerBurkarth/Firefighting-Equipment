<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Appearance;

use FirefightingEquipment\Http\AppPath;
use FirefightingEquipment\Tenant\Logo\DefaultAppIcon;
use FirefightingEquipment\Tenant\Logo\LogoSize;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantAppearance;

/**
 * Antwort von GET api/v1/tenant und der Routen zum Erscheinungsbild, dazu die Adressen von Logo und
 * Standard-Symbol. Adressen sind pfadrelativ (/firefighting/<kürzel>/…, ohne base_url) und tragen die
 * Versionskennung als ?v=….
 */
final class AppearanceJson
{
    /**
     * @return array<string, mixed>
     */
    public static function tenant(Tenant $tenant, TenantAppearance $appearance): array
    {
        $logoUrls = null;
        if ($appearance->logoVersion !== null) {
            $logoUrls = [];
            foreach (LogoSize::cases() as $size) {
                $logoUrls[(string) $size->value] = self::logoUrl($tenant, $appearance->logoVersion, $size);
            }
        }

        return [
            'slug' => $tenant->slug->value,
            'name' => $tenant->name->value,
            'accent_color' => $appearance->effectiveAccentColor()->value,
            'accent_color_is_default' => $appearance->accentColor === null,
            'logo_url' => $logoUrls === null ? null : $logoUrls[(string) LogoSize::Large->value],
            'logo_urls' => $logoUrls,
        ];
    }

    /** Startseite der Stadt (mit / am Ende): start_url, scope und id des Manifests. */
    public static function areaPath(Tenant $tenant): string
    {
        return AppPath::PREFIX . '/' . $tenant->slug->value . '/';
    }

    public static function logoUrl(Tenant $tenant, string $version, LogoSize $size): string
    {
        return sprintf('%sapi/v1/tenant/logo/%d?v=%s', self::areaPath($tenant), $size->value, $version);
    }

    public static function appIconUrl(Tenant $tenant, LogoSize $size): string
    {
        return sprintf('%sapi/v1/app-icon/%d?v=%s', self::areaPath($tenant), $size->value, DefaultAppIcon::version());
    }
}
