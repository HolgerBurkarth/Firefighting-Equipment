<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Auth\Session;
use FirefightingEquipment\Auth\SessionRepository;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * GET api/v1/auth/sessions: gültige Sitzungen des eigenen Kontos, zuletzt benutzte zuerst.
 */
final readonly class SessionListHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $auth = $context->auth();
        $sessions = new SessionRepository($context->db->get())->listValidByUser($auth->user->id, $auth->user->role);

        return new JsonResponse([
            'sessions' => array_map(
                static fn (Session $session): array => AuthJson::session($session, $auth->session->id),
                $sessions,
            ),
        ]);
    }
}
