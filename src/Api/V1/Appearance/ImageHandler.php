<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Appearance;

use FirefightingEquipment\Http\CacheableResponse;
use FirefightingEquipment\Http\CachePolicy;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Tenant\Logo\DefaultAppIcon;
use FirefightingEquipment\Tenant\Logo\LogoSize;
use FirefightingEquipment\Tenant\TenantAppearanceRepository;

/**
 * Öffentliche PNG-Dateien einer Stadt (für Anmeldeseite und App-Symbol):
 *
 * - GET api/v1/tenant/logo/{size}: Logo der Stadt; ohne Logo 404 not_found.
 * - GET api/v1/app-icon/{size}: Standard-Symbol (DefaultAppIcon); auch unter service (Symbol der
 *   App-Seite des Super-Admins, ohne Stadt).
 *
 * {size} nur 512, 192 oder 180 (sonst 404). Passt ?v= zur aktuellen Versionskennung, darf die Datei
 * dauerhaft zwischengespeichert werden (CachePolicy::Immutable); sonst (fehlt oder veraltet, z. B. aus
 * einem alten Manifest) kommt die aktuelle Datei mit CachePolicy::Revalidate. ETag
 * "<version>-<größe>", If-None-Match → 304.
 */
final readonly class ImageHandler implements RequestHandler
{
    public function __construct(private bool $defaultIcon)
    {
    }

    public function handle(RequestContext $context): CacheableResponse
    {
        $size = LogoSize::tryFrom($context->param('size')) ?? throw HttpException::notFound();

        if ($this->defaultIcon) {
            $version = DefaultAppIcon::version();
            $png = DefaultAppIcon::png($size);
        } else {
            $tenant = $context->tenant ?? throw new \LogicException('Logo nur unter einer Stadt.');
            $logo = new TenantAppearanceRepository($context->db->get())->logo($tenant, $size)
                ?? throw HttpException::notFound();
            ['version' => $version, 'png' => $png] = $logo;
        }

        return new CacheableResponse(
            $context->request,
            CacheableResponse::PNG,
            $png,
            sprintf('"%s-%d"', $version, $size->value),
            ($context->request->query()['v'] ?? null) === $version ? CachePolicy::Immutable : CachePolicy::Revalidate,
        );
    }
}
