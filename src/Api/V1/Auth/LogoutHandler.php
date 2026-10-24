<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Auth\SessionCookie;
use FirefightingEquipment\Auth\SessionManager;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * POST api/v1/auth/logout {}: öffentlich und wiederholbar. Beendet die Sitzung des Cookies (falls
 * vorhanden) und löscht das Cookie immer.
 */
final readonly class LogoutHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $token = SessionCookie::tokenFrom($context->request);
        if ($token !== null) {
            new SessionManager($context->db->get())->end($token);
        }

        return new JsonResponse(['logged_out' => true], 200, ['Set-Cookie' => SessionCookie::clear($context->tenant)]);
    }
}
