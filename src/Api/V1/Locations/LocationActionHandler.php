<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Locations;

use FirefightingEquipment\Api\V1\DeviceErrors;
use FirefightingEquipment\Device\DeviceException;
use FirefightingEquipment\Device\LocationKind;
use FirefightingEquipment\Device\LocationName;
use FirefightingEquipment\Device\LocationNotFoundException;
use FirefightingEquipment\Device\LocationRepository;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * Standort anzeigen bzw. verwalten, siehe LocationAction. Antwort {"location": …} mit dem Stand
 * danach, beim Anlegen 201.
 *
 * Ungültige Felder 400 (auch ein unbekannter oder fremder parent_id), fremde oder unbekannte ID im
 * Pfad 404, Name schon vergeben 409 name_taken, Regelverstoß 409 rule_violation (zu tief, andere
 * Art, gesperrter übergeordneter Standort; Sperren nur leer, dann mit details {devices,
 * sublocations}). Sperren und Entsperren sind wiederholbar.
 */
final readonly class LocationActionHandler implements RequestHandler
{
    /** Größte ID (INT UNSIGNED). */
    private const int MAX_ID = 4_294_967_295;

    public function __construct(private LocationAction $action)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $locations = new LocationRepository($context->db->get());

        try {
            // Felder vor der Suche des Standorts lesen (400 vor 404).
            $location = match ($this->action) {
                LocationAction::Show => $locations->find($tenant, $context->param('id'))
                    ?? throw LocationNotFoundException::forId($context->param('id')),
                LocationAction::Create => $locations->create(
                    $tenant,
                    self::name($context),
                    self::kind($context),
                    $context->optionalIntField('parent_id', 1, self::MAX_ID),
                ),
                LocationAction::Rename => $locations->rename($tenant, $context->param('id'), self::name($context)),
                LocationAction::Lock => $locations->lock($tenant, $context->param('id')),
                LocationAction::Unlock => $locations->unlock($tenant, $context->param('id')),
            };
        } catch (DeviceException $e) {
            throw DeviceErrors::http($e);
        }

        return new JsonResponse(['location' => LocationJson::location($location)], $this->action === LocationAction::Create ? 201 : 200);
    }

    /**
     * @throws HttpException   400 invalid_request (Feld fehlt)
     * @throws DeviceException Name verletzt die Regeln
     */
    private static function name(RequestContext $context): LocationName
    {
        return LocationName::fromString($context->stringField('name'));
    }

    /**
     * @return LocationKind|null null = nicht angegeben (bei Unterstandorten: Art des übergeordneten)
     *
     * @throws HttpException 400 invalid_request
     */
    private static function kind(RequestContext $context): ?LocationKind
    {
        $value = $context->optionalStringField('kind');
        if ($value === null) {
            return null;
        }

        return LocationKind::tryFrom($value) ?? throw HttpException::invalidRequest(sprintf(
            'Das Feld "kind" muss einer der Werte %s sein.',
            implode(', ', array_map(static fn (LocationKind $k): string => sprintf('"%s"', $k->value), LocationKind::cases())),
        ));
    }
}
