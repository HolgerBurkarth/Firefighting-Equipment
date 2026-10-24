<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Auth\SessionRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * POST api/v1/auth/sessions/logout-others {}: beendet alle Sitzungen des Kontos außer der aktuellen.
 */
final readonly class LogoutOthersHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $auth = $context->auth();
        $ended = new SessionRepository($context->db->get())->deleteOthers($auth->user->id, $auth->session->id);

        return new JsonResponse(['ended_sessions' => $ended]);
    }
}
