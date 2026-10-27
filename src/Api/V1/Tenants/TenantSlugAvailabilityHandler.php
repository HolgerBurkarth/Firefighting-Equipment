<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Tenants;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Tenant\InvalidTenantValueException;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;

/**
 * GET api/v1/tenants/slug-available?slug=… (Super-Admin): Ist das Kürzel gültig und noch frei?
 * Antwort immer 200 {slug, valid, available, message, suggestion}; slug = Eingabe unverändert,
 * suggestion = gültige Schreibweise (bisher nur in Kleinbuchstaben) oder null. Vergeben ist ein
 * Kürzel auch durch eine gesperrte Stadt (Kürzel werden nie frei). Fehlt slug: 400.
 *
 * Nur eine Vorabprüfung für das Formular; verbindlich bleibt POST tenants (409 slug_taken).
 */
final readonly class TenantSlugAvailabilityHandler implements RequestHandler
{
    public const string AVAILABLE = 'Das Kürzel ist verfügbar.';
    public const string TAKEN = 'Das Kürzel ist bereits vergeben.';

    public function handle(RequestContext $context): JsonResponse
    {
        $value = $context->request->query()['slug'] ?? throw HttpException::invalidRequest(
            'Der Parameter "slug" fehlt.',
        );

        try {
            $slug = TenantSlug::fromString($value);
        } catch (InvalidTenantValueException $e) {
            return self::response($value, false, false, $e->getMessage(), $e->suggestion);
        }

        $available = new TenantRepository($context->db->get())->findBySlug($slug) === null;

        return self::response($value, true, $available, $available ? self::AVAILABLE : self::TAKEN, null);
    }

    private static function response(string $slug, bool $valid, bool $available, string $message, ?string $suggestion): JsonResponse
    {
        return new JsonResponse([
            'slug' => $slug,
            'valid' => $valid,
            'available' => $available,
            'message' => $message,
            'suggestion' => $suggestion,
        ]);
    }
}
