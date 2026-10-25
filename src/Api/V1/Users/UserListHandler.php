<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Users;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserStatus;

/**
 * GET api/v1/users[?status=pending] (Admin): Konten der eigenen Stadt, nach ID. Ohne Filter alle
 * außer gelöschten (anonymisierten); mit status=deleted nur diese. Räumt vorher nie bestätigte
 * Registrierungen auf (UserRepository::anonymizeStaleUnverified).
 */
final readonly class UserListHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $status = self::statusFilter($context);

        $users = new UserRepository($context->db->get());
        $users->anonymizeStaleUnverified($tenant);
        $list = $users->listByTenant($tenant, $status);
        if ($status === null) {
            $list = array_values(array_filter($list, static fn (User $u): bool => !$u->isDeleted()));
        }

        return new JsonResponse(['users' => array_map(UserJson::user(...), $list)]);
    }

    /**
     * Filter ?status=… (auch für die Benutzerliste einer Stadt beim Super-Admin).
     *
     * @return UserStatus|null null = ohne Filter
     *
     * @throws HttpException 400 invalid_request bei unbekanntem Status
     */
    public static function statusFilter(RequestContext $context): ?UserStatus
    {
        $filter = $context->request->query()['status'] ?? null;
        $status = $filter === null ? null : UserStatus::tryFrom($filter);
        if ($filter !== null && $status === null) {
            throw HttpException::invalidRequest(sprintf(
                'Unbekannter Status. Erlaubt: %s.',
                implode(', ', array_map(static fn (UserStatus $s): string => $s->value, UserStatus::cases())),
            ));
        }

        return $status;
    }
}
