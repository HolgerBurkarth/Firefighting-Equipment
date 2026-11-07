<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Claude\ClaudeConnectionRepository;
use FirefightingEquipment\Claude\ClaudeSessions;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\OAuth\RedirectUriRules;

/**
 * Eigene Claude-Verbindungen (docs/PLAN-MCP.md, O5; nur unter Städten, jedes angemeldete Konto):
 * - GET api/v1/auth/claude-connections: gültige Verbindungen, zuletzt genutzte zuerst (Verbindungen,
 *   die nicht mehr gelten, werden dabei gelöscht);
 * - DELETE api/v1/auth/claude-connections/{id} {}: eine eigene trennen (fremde und unbekannte 404).
 */
final readonly class ClaudeConnectionsHandler implements RequestHandler
{
    public function __construct(private bool $delete = false)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $auth = $context->auth();
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $pdo = $context->db->get();
        $connections = new ClaudeConnectionRepository($pdo);

        if ($this->delete) {
            if (!$connections->deleteForUserById($context->param('id'), $auth->user->id)) {
                throw HttpException::notFound();
            }

            return new JsonResponse(['deleted' => true]);
        }

        $sessions = new ClaudeSessions($pdo);
        $list = [];
        foreach ($connections->listForUser($auth->user->id) as $connection) {
            $session = $sessions->check($connection, $tenant);
            if ($session === null) {
                continue;
            }
            $list[] = [
                'id' => $connection->id,
                'client_name' => $connection->clientName,
                'target' => RedirectUriRules::target($connection->redirectUri),
                'access' => $session->access->value,
                'access_label' => $session->access->label(),
                'created_at' => AuthJson::time($connection->createdAt),
                'last_used_at' => AuthJson::time($connection->lastSeenAt),
            ];
        }

        return new JsonResponse(['connections' => $list]);
    }
}
