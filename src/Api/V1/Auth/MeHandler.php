<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * GET api/v1/auth/me: angemeldetes Konto, Stadt (null unter service) und aktuelle Sitzung.
 */
final readonly class MeHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        return new JsonResponse(AuthJson::me($context->auth(), $context->tenant));
    }
}
