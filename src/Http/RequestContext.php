<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Database\LazyConnection;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Alles, was ein Handler über die Anfrage wissen darf.
 */
final readonly class RequestContext
{
    public function __construct(
        public Request $request,
        /** Die aktive Stadt; null im Bereich service. */
        public ?Tenant $tenant,
        /** Die Verbindung wird erst beim ersten Zugriff aufgebaut. */
        public LazyConnection $db,
        public ErrorReporter $errors,
    ) {
    }
}
