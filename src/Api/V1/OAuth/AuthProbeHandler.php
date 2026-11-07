<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\OAuth;

use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * VORÜBERGEHEND: GET api/v1/auth-probe, nur in der Test-Stadt (sonst 404). Zeigt, unter welchem
 * Namen der Header Authorization bei PHP ankommt (AuthProbe: nur Namen, nie Werte; nichts wird
 * protokolliert oder gespeichert). Wird nach dem Live-Test von Runde 5 wieder entfernt.
 */
final readonly class AuthProbeHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        ClaudeTenant::of($context);

        return new JsonResponse(AuthProbe::describe(PHP_SAPI, $_SERVER, getenv()));
    }
}
