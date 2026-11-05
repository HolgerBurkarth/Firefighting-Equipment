<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Users;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Role\RoleNotFoundException;
use FirefightingEquipment\Role\RoleRepository;
use FirefightingEquipment\User\UserNotFoundException;
use FirefightingEquipment\User\UserRuleViolationException;

/**
 * POST api/v1/users/bulk/role {user_ids, role_id} (Benutzer: Ändern; Plan Phase 1, Schritt 1.6, F6):
 * weist mehreren Konten der Stadt dieselbe Rolle zu, je Konto in einer eigenen Transaktion mit
 * allen Regeln von POST users/{id}/role (UserAdministration::assignRole); Teilerfolg ist erlaubt.
 *
 * Antwort immer 200 {"results": [{id, outcome, user, error}], "summary": {total, applied, unchanged,
 * errors}} in der Reihenfolge der Anfrage, wie die Sammelaktionen der Geräte: outcome applied,
 * unchanged oder error; user der Stand danach (bei error der aktuelle, null bei fremder oder
 * unbekannter ID); error null bzw. {error, message} wie die Antwort der einzelnen Route (not_found,
 * rule_violation).
 *
 * Fehler der ganzen Anfrage (vor jeder Änderung): Felder ungültig, unbekannte Felder, kein Konto,
 * mehr als MAX_USERS oder ein Konto mehrfach, Rolle unbekannt (400); Rolle, die das Konto der
 * Sitzung nicht vergeben darf (409 rule_violation, F5).
 */
final readonly class UserBulkRoleHandler implements RequestHandler
{
    /** Höchstzahl der Konten je Anfrage (wie DeviceBulkHandler::MAX_DEVICES). */
    public const int MAX_USERS = 50;

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $ids = self::userIds($context);
        $roleId = $context->intField('role_id', 1, PHP_INT_MAX);

        $pdo = $context->db->get();
        $admin = UserActionHandler::administration($context, $tenant);
        $role = new RoleRepository($pdo)->find($tenant, $roleId)
            ?? throw HttpException::invalidRequest(RoleNotFoundException::forId($roleId)->getMessage() . ' (Feld "role_id")');
        try {
            $admin->assertMayAssign($role);
        } catch (UserRuleViolationException $e) {
            throw HttpException::ruleViolation($e->getMessage());
        }

        $json = UserJson::forTenant($pdo, $tenant);
        $results = [];
        $counts = ['applied' => 0, 'unchanged' => 0, 'errors' => 0];
        foreach ($ids as $id) {
            try {
                [$user, $changed] = $admin->assignRole($id, $role->id);
                $outcome = $changed ? 'applied' : 'unchanged';
                $error = null;
            } catch (UserNotFoundException $e) {
                [$user, $outcome, $error] = [null, 'error', ['error' => 'not_found', 'message' => $e->getMessage()]];
            } catch (UserRuleViolationException | RoleNotFoundException $e) {
                $error = $e instanceof RoleNotFoundException
                    ? ['error' => 'invalid_request', 'message' => $e->getMessage() . ' (Feld "role_id")']
                    : ['error' => 'rule_violation', 'message' => $e->getMessage()];
                [$user, $outcome] = [$admin->find($id), 'error'];
            }
            ++$counts[$outcome === 'error' ? 'errors' : $outcome];
            $results[] = ['id' => $id, 'outcome' => $outcome, 'user' => $user === null ? null : $json($user), 'error' => $error];
        }

        return new JsonResponse(['results' => $results, 'summary' => ['total' => count($ids), ...$counts]]);
    }

    /**
     * @return list<int>
     *
     * @throws HttpException 400 invalid_request
     */
    private static function userIds(RequestContext $context): array
    {
        foreach (array_keys($context->body) as $key) {
            if ($key !== 'user_ids' && $key !== 'role_id') {
                throw HttpException::invalidRequest(sprintf('Das Feld "%s" ist hier nicht vorgesehen (möglich: user_ids, role_id).', $key));
            }
        }
        $ids = $context->body['user_ids'] ?? null;
        if (!is_array($ids) || !array_is_list($ids) || $ids === [] || count($ids) > self::MAX_USERS) {
            throw HttpException::invalidRequest(sprintf('Das Feld "user_ids" muss eine Liste mit 1 bis %d Konten sein.', self::MAX_USERS));
        }
        foreach ($ids as $index => $id) {
            if (!is_int($id) || $id < 1) {
                throw HttpException::invalidRequest(sprintf('Eintrag %d in "user_ids" muss eine positive Ganzzahl sein.', $index + 1));
            }
        }
        /** @var list<int> $ids */
        if (count(array_unique($ids)) !== count($ids)) {
            throw HttpException::invalidRequest('Ein Konto steht mehrfach in "user_ids".');
        }

        return $ids;
    }
}
