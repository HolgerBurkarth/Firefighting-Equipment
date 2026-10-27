<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Appearance;

use FirefightingEquipment\Api\V1\Devices\DeviceFields;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Tenant\AccentColor;
use FirefightingEquipment\Tenant\InvalidAccentColorException;
use FirefightingEquipment\Tenant\TenantAppearanceRepository;

/**
 * POST api/v1/tenant/appearance {accent_color} (Admin): Akzentfarbe der eigenen Stadt setzen, null =
 * Standardfarbe. Falsches Format oder zu wenig Kontrast zu weißer Schrift: 400 invalid_request (beim
 * Kontrast mit details {"contrast": 4.47, "min_contrast": 4.5}). Antwort wie GET api/v1/tenant.
 */
final readonly class AccentColorHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        DeviceFields::rejectUnknown($context, ['accent_color']);
        if (!$context->hasField('accent_color')) {
            throw HttpException::invalidRequest('Das Feld "accent_color" fehlt (null = Standardfarbe).');
        }
        $input = $context->optionalStringField('accent_color');

        try {
            $color = $input === null ? null : AccentColor::fromString($input);
        } catch (InvalidAccentColorException $e) {
            throw HttpException::invalidRequest(
                $e->getMessage(),
                $e->contrast === null ? null : ['contrast' => $e->contrast, 'min_contrast' => AccentColor::MIN_CONTRAST],
            );
        }

        $repository = new TenantAppearanceRepository($context->db->get());
        $repository->setAccentColor($tenant, $color);

        return new JsonResponse(AppearanceJson::tenant($tenant, $repository->find($tenant)));
    }
}
