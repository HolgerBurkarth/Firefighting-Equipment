<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Auth\InvitationAcceptThrottle;
use FirefightingEquipment\Auth\IpPrefix;
use FirefightingEquipment\Auth\LoginThrottle;
use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Auth\TokenPurpose;
use FirefightingEquipment\Auth\UserTokenRepository;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\User\InvalidUserValueException;
use FirefightingEquipment\User\Password\PasswordContext;
use FirefightingEquipment\User\Password\PasswordPolicy;
use FirefightingEquipment\User\Password\WeakPasswordException;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserStatus;

/**
 * POST api/v1/auth/invitation/accept {token, name, password} (öffentlich, nur unter einer Stadt):
 * Einladung als Admin annehmen. Name und Anzeigename werden name, die E-Mail-Adresse gilt als
 * bestätigt, das Konto ist danach aktiv. Keine Sitzung: Anmeldung mit dem neuen Passwort. Danach
 * bekommt jeder aktive, nicht gesperrte Super-Admin einen Hinweis.
 *
 * Ungültig (400 invalid_token, zählt in die Bremse) sind: falsches Format, unbekannt, anderer
 * Zweck, abgelaufen, schon benutzt, Konto nicht mehr invited oder gesperrt, Konto einer anderen
 * Stadt. Ungültiger Name (400) und zu schwaches Passwort (422) verbrauchen den Link nicht.
 */
final readonly class InvitationAcceptHandler implements RequestHandler
{
    public const string MESSAGE = 'Ihr Konto ist eingerichtet. Bitte melden Sie sich mit Ihrer E-Mail-Adresse und dem neuen Passwort an.';

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $tokenValue = $context->stringField('token');
        $nameValue = $context->stringField('name');
        $password = $context->stringField('password');

        $pdo = $context->db->get();
        $throttle = new InvitationAcceptThrottle($pdo);
        $ip = IpPrefix::fromAddress($context->request->remoteAddress);
        $retryAfter = $throttle->retryAfter($ip);
        if ($retryAfter !== null) {
            throw HttpException::tooManyAttempts($retryAfter);
        }

        $tokens = new UserTokenRepository($pdo);
        $tokens->deleteExpired();
        $users = new UserRepository($pdo);
        $token = OneTimeToken::fromString($tokenValue);
        $userId = $token === null ? null : $tokens->findUserId(TokenPurpose::SetPassword, $token);
        $user = $userId === null ? null : $users->findByTenantAndId($tenant, $userId);
        if ($token === null || $user === null || $user->status !== UserStatus::Invited || $user->isLocked()) {
            $throttle->record($tenant->id, $ip, false);
            throw HttpException::invalidToken();
        }

        try {
            $name = PersonName::fromString($nameValue);
        } catch (InvalidUserValueException $e) {
            throw HttpException::invalidRequest($e->getMessage());
        }
        try {
            PasswordPolicy::default()->check($password, new PasswordContext($user->email, [$name], $tenant));
        } catch (WeakPasswordException $e) {
            throw HttpException::weakPassword($e->getMessage());
        }

        // Löst den Link ein; false, wenn ihn inzwischen jemand anderes eingelöst hat oder das Konto
        // gesperrt wurde.
        if (!$users->acceptInvitation($user->id, $name, $context->hasher->hash($password), $token->hash())) {
            $throttle->record($tenant->id, $ip, false);
            throw HttpException::invalidToken();
        }
        $throttle->record($tenant->id, $ip, true);
        // Fehlversuche bei der Anmeldung vor dem Annehmen sollen die erste Anmeldung nicht bremsen.
        new LoginThrottle($pdo)->record($tenant->id, LoginThrottle::emailHash($user->email?->value ?? ''), $ip, true);

        $admin = $users->findById($user->id) ?? $user;
        foreach ($users->listActiveSuperAdmins() as $superAdmin) {
            $context->notifier()->send(
                'invitation_accepted',
                $superAdmin->id,
                static fn (): MailMessage => MailTemplates::invitationAccepted($superAdmin, $tenant, $admin, $context->urls->area(null)),
            );
        }

        return new JsonResponse(['message' => self::MESSAGE]);
    }
}
