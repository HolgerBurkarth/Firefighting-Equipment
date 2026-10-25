<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Users;

use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\User\InvalidUserValueException;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\UserAdministration;
use FirefightingEquipment\User\UserNotFoundException;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;
use FirefightingEquipment\User\UserRuleViolationException;

/**
 * Benutzerverwaltung (Admin) auf ein Konto der eigenen Stadt, siehe UserAction. Antwort immer
 * {"user": …} mit dem Stand danach (gelöschte bzw. abgelehnte Konten anonymisiert).
 *
 * Fremde oder unbekannte IDs: 404 not_found. Verstöße gegen Regeln (letzter Admin, sich selbst
 * sperren, herabstufen oder löschen, falscher Status): 409 rule_violation. Ungültige Felder: 400.
 * Freischalten schickt „Ihr Konto wurde freigeschaltet“, Ablehnen „Ihre Registrierung wurde nicht
 * freigegeben“ (an die Adresse, die vor dem Anonymisieren gespeichert war).
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

        $admin = new UserAdministration(new UserRepository($context->db->get()), $tenant, $context->auth()->user);
        try {
            // Felder werden als Argumente gelesen, also vor der Suche des Kontos (400 vor 404).
            $user = match ($this->action) {
                UserAction::Show => $admin->find($id),
                UserAction::Approve => $admin->approve($id, self::displayName($context), self::role($context)),
                UserAction::Reject => $admin->reject($id),
                UserAction::Lock => $admin->lock($id),
                UserAction::Unlock => $admin->unlock($id),
                UserAction::ChangeRole => $admin->changeRole($id, self::role($context)),
                UserAction::ChangeDisplayName => $admin->changeDisplayName($id, self::displayName($context)),
                UserAction::Delete => $admin->delete($id),
            };
        } catch (UserNotFoundException) {
            throw HttpException::notFound();
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

        return new JsonResponse(['user' => UserJson::user($user)]);
    }

    /**
     * @throws HttpException 400 invalid_request
     */
    private static function role(RequestContext $context): UserRole
    {
        return match ($context->stringField('role')) {
            UserRole::Admin->value => UserRole::Admin,
            UserRole::User->value => UserRole::User,
            default => throw HttpException::invalidRequest('Das Feld "role" muss "user" oder "admin" sein.'),
        };
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
