<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\OAuth;

use FirefightingEquipment\Auth\IpPrefix;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\OAuth\ClientRegistration;
use FirefightingEquipment\OAuth\OAuthClientRepository;
use FirefightingEquipment\OAuth\OAuthError;
use FirefightingEquipment\OAuth\OAuthRegisterThrottle;

/**
 * POST oauth/register (öffentlich, JSON; Dynamic Client Registration, RFC 7591): nur erlaubte
 * Rücksprung-Adressen, nur öffentliche Clients. Reihenfolge: Angaben (400 im OAuth-Format), Bremse
 * (429), Höchstzahl nie genutzter Registrierungen der Stadt (429), dann anlegen. Räumt dabei alte
 * Registrierungen der Stadt auf (Ersatz für einen Cronjob).
 */
final readonly class ClientRegistrationHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = ClaudeTenant::of($context);
        try {
            $registration = ClientRegistration::fromMetadata($context->body);
        } catch (OAuthError $e) {
            return $e->toResponse();
        }

        $pdo = $context->db->get();
        $ipPrefix = IpPrefix::fromAddress($context->request->remoteAddress);
        $throttle = new OAuthRegisterThrottle($pdo);
        $retryAfter = $throttle->retryAfter($ipPrefix);
        if ($retryAfter !== null) {
            throw HttpException::tooManyAttempts($retryAfter);
        }

        $clients = new OAuthClientRepository($pdo);
        $clients->deleteStale($tenant->id);
        if ($clients->countUnused($tenant->id) >= OAuthClientRepository::MAX_UNUSED_PER_TENANT) {
            throw HttpException::tooManyAttempts(3600);
        }
        $client = $clients->register($tenant->id, $registration->clientName, $registration->redirectUris);
        $throttle->record($tenant->id, $ipPrefix);

        $response = [
            'client_id' => $client->clientId,
            'client_id_issued_at' => time(),
            'redirect_uris' => $client->redirectUris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
        if ($client->clientName !== null) {
            $response['client_name'] = $client->clientName;
        }

        return new JsonResponse($response, 201);
    }
}
