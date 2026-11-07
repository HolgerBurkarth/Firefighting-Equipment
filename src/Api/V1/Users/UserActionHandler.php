<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Users;

use FirefightingEquipment\Claude\ClaudeAccess;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\Role\RoleNotFoundException;
use FirefightingEquipment\Role\RoleRepository;
use FirefightingEquipment\Role\SystemRole;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\InvalidUserValueException;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\UserAdministration;
use FirefightingEquipment\User\UserNotFoundException;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRuleViolationException;

/**
 * Benutzerverwaltung auf ein Konto der eigenen Stadt, siehe UserAction. Antwort immer {"user": …}
 * mit dem Stand danach (gelöschte bzw. abgelehnte Konten anonymisiert).
 *
 * Rolle (approve, role): role_id (Rolle der Stadt, Plan Phase 1, Schritt 1.6) oder wie bisher role
 * (user/admin = Standard-Rolle Benutzer bzw. Admin; bleibt für alte Apps), nicht beides.
 *
 * Fremde oder unbekannte IDs: 404 not_found. Unbekannte Rolle (role_id): 400. Verstöße gegen Regeln
 * (letzter Admin, sich selbst sperren, löschen oder die eigene Rolle ändern, falscher Status,
 * Schutzregeln F5 in UserAdministration): 409 rule_violation. Ungültige Felder: 400. Freischalten
 * schickt „Ihr Konto wurde freigeschaltet“, Ablehnen „Ihre Registrierung wurde nicht freigegeben“
 * (an die Adresse, die vor dem Anonymisieren gespeichert war).
 *
 * Claude-Zugang (claude-access, claude-connections/end; F12 in UserAdministration): nur in der
 * Test-Stadt, nur die Rolle Admin, nur für Konten ohne Admin, nie am eigenen Konto; sonst 409.
 */
final readonly class UserActionHandler implements RequestHandler
{
    public function __construct(private UserAction $action)
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $id = $context->param('id');

        $pdo = $context->db->get();
        $roles = new RoleRepository($pdo);
        $admin = self::administration($context, $tenant);
        try {
            // Felder werden als Argumente gelesen, also vor der Suche des Kontos (400 vor 404).
            $user = match ($this->action) {
                UserAction::Show => $admin->find($id),
                UserAction::Approve => $admin->approve($id, self::displayName($context), self::roleId($context, $roles, $tenant)),
                UserAction::Reject => $admin->reject($id),
                UserAction::Lock => $admin->lock($id),
                UserAction::Unlock => $admin->unlock($id),
                UserAction::ChangeRole => $admin->assignRole($id, self::roleId($context, $roles, $tenant))[0],
                UserAction::ChangeDisplayName => $admin->changeDisplayName($id, self::displayName($context)),
                UserAction::Delete => $admin->delete($id),
                UserAction::SetClaudeAccess => $admin->setClaudeAccess($id, self::claudeAccess($context)),
                UserAction::EndClaudeConnections => $admin->endClaudeConnections($id),
            };
        } catch (UserNotFoundException) {
            throw HttpException::notFound();
        } catch (RoleNotFoundException $e) {
            throw HttpException::invalidRequest($e->getMessage() . ' (Feld "role_id")');
        } catch (UserRuleViolationException $e) {
            throw HttpException::ruleViolation($e->getMessage());
        }

        if ($this->action === UserAction::Approve) {
            $context->notifier()->send(
                'account_approved',
                $user->id,
                static fn (): MailMessage => MailTemplates::accountApproved($user, $tenant, $context->urls->area($tenant)),
            );
        } elseif ($this->action === UserAction::Reject) {
            $context->notifier()->send(
                'registration_rejected',
                $user->id,
                static fn (): MailMessage => MailTemplates::registrationRejected($user, $tenant),
            );
            $user = $admin->find($id);
        }

        return new JsonResponse(['user' => UserJson::forTenant($pdo, $tenant)($user)]);
    }

    /** Verwaltung mit Konto und Rechten der Sitzung (auch für die Sammelaktion). */
    public static function administration(RequestContext $context, Tenant $tenant): UserAdministration
    {
        $pdo = $context->db->get();

        return new UserAdministration(new UserRepository($pdo), new RoleRepository($pdo), $tenant, $context->auth()->user, $context->permissions());
    }

    /**
     * Rolle aus role_id (Rolle der Stadt, ob sie existiert, prüft UserRepository) oder role
     * (Standard-Rolle Benutzer bzw. Admin, für alte Apps).
     *
     * @throws HttpException 400 invalid_request
     */
    private static function roleId(RequestContext $context, RoleRepository $roles, Tenant $tenant): int
    {
        $hasRoleId = $context->hasField('role_id');
        if ($hasRoleId && $context->hasField('role')) {
            throw HttpException::invalidRequest('Bitte nur "role_id" angeben (nicht zusätzlich "role").');
        }
        if (!$hasRoleId && !$context->hasField('role')) {
            throw HttpException::invalidRequest('Das Feld "role_id" fehlt.');
        }
        if ($hasRoleId) {
            return $context->intField('role_id', 1, PHP_INT_MAX);
        }
        $systemRole = match ($context->stringField('role')) {
            'admin' => SystemRole::Admin,
            'user' => SystemRole::User,
            default => throw HttpException::invalidRequest('Das Feld "role" muss "user" oder "admin" sein.'),
        };

        return ($roles->findSystemRole($tenant, $systemRole) ?? throw new \LogicException('Standard-Rolle fehlt.'))->id;
    }

    /**
     * Feld access: off, read oder write (auch für den Super-Admin, TenantActionHandler).
     *
     * @throws HttpException 400 invalid_request
     */
    public static function claudeAccess(RequestContext $context): ClaudeAccess
    {
        return ClaudeAccess::tryFrom($context->stringField('access'))
            ?? throw HttpException::invalidRequest('Das Feld "access" muss "off", "read" oder "write" sein.');
    }

    /**
     * @throws HttpException 400 invalid_request
     */
    private static function displayName(RequestContext $context): PersonName
    {
        try {
            return PersonName::fromString($context->stringField('display_name'));
        } catch (InvalidUserValueException $e) {
            throw HttpException::invalidRequest($e->getMessage());
        }
    }
}
