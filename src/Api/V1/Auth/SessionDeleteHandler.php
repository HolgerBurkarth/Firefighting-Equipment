<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Auth\SessionCookie;
use FirefightingEquipment\Auth\SessionRepository;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;

/**
 * DELETE api/v1/auth/sessions/{id} {}: beendet eine eigene Sitzung. Fremde und unbekannte IDs
 * sind nicht unterscheidbar (404). Die aktuelle Sitzung zu beenden wirkt wie eine Abmeldung.
 */
final readonly class SessionDeleteHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $auth = $context->auth();
        $id = $context->param('id');

        if (!new SessionRepository($context->db->get())->deleteForUser($id, $auth->user->id)) {
            throw HttpException::notFound();
        }

        $current = $id === $auth->session->id;

        return new JsonResponse(
            ['deleted' => true, 'current' => $current],
            200,
            $current ? ['Set-Cookie' => SessionCookie::clear($context->tenant)] : [],
        );
    }
}
