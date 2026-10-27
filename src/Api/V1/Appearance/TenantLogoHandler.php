<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Appearance;

use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Tenant\Logo\InvalidLogoException;
use FirefightingEquipment\Tenant\Logo\LogoRenderer;
use FirefightingEquipment\Tenant\TenantAppearanceRepository;

/**
 * Logo der eigenen Stadt (Admin):
 *
 * - POST api/v1/tenant/logo {file}: PNG als Base64 (Whitespace erlaubt; die Route erlaubt
 *   MAX_BODY_BYTES). Geprüft von PngInspector, neu erzeugt von LogoRenderer; gespeichert werden nur
 *   die neuen Dateien. Regelverstoß: 400 invalid_request, ggf. mit details.
 * - DELETE api/v1/tenant/logo {}: zurück zum Standard-Symbol (wiederholbar).
 *
 * Antwort wie GET api/v1/tenant.
 */
final readonly class TenantLogoHandler implements RequestHandler
{
    /**
     * Body-Grenze der Upload-Route: 500 KB als Base64 (682 668 Zeichen) auch mit CRLF alle 76 Zeichen
     * (in JSON als vier Zeichen je Umbruch, zusammen rund 718 KB) plus JSON
     */
    public const int MAX_BODY_BYTES = 720 * 1024;

    public function __construct(private bool $delete)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $repository = new TenantAppearanceRepository($context->db->get());

        if ($this->delete) {
            $repository->deleteLogo($tenant);

            return new JsonResponse(AppearanceJson::tenant($tenant, $repository->find($tenant)));
        }

        DeviceFields::rejectUnknown($context, ['file']);
        $bytes = base64_decode($context->stringField('file'), true);
        if ($bytes === false) {
            throw HttpException::invalidRequest('Das Feld "file" muss die PNG-Datei als Base64 enthalten.');
        }

        try {
            $images = LogoRenderer::fromUpload($bytes);
        } catch (InvalidLogoException $e) {
            throw HttpException::invalidRequest($e->getMessage(), $e->details === [] ? null : $e->details);
        }
        $repository->saveLogo($tenant, $images, $context->auth()->user->id);

        return new JsonResponse(AppearanceJson::tenant($tenant, $repository->find($tenant)));
    }
}
