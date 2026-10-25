<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Auth\IpPrefix;
use FirefightingEquipment\Auth\LoginThrottle;
use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Auth\PasswordResetThrottle;
use FirefightingEquipment\Auth\SessionCookie;
use FirefightingEquipment\Auth\SessionManager;
use FirefightingEquipment\Auth\TokenPurpose;
use FirefightingEquipment\Auth\UserTokenRepository;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\User\Password\PasswordContext;
use FirefightingEquipment\User\Password\PasswordPolicy;
use FirefightingEquipment\User\Password\WeakPasswordException;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\UserRepository;

/**
 * POST api/v1/auth/password-reset/confirm {token, new_password}: Passwort mit dem Link aus der Mail
 * neu setzen.
 *
 * Ungültig (400 invalid_token, zählt in die Bremse) sind: falsches Format, unbekannt, anderer
 * Zweck, abgelaufen, schon benutzt, Konto nicht mehr aktiv oder gesperrt, Konto eines anderen
 * Bereichs. Ein zu schwaches Passwort (422) verbraucht den Link nicht. Danach sind alle Sitzungen
 * beendet (keine neue: Anmeldung mit dem neuen Passwort), und das Konto bekommt eine
 * Benachrichtigung.
 */
final readonly class PasswordResetConfirmHandler implements RequestHandler
{
    public const string MESSAGE = 'Das Passwort wurde geändert. Bitte melden Sie sich mit dem neuen Passwort an.';

    public function handle(RequestContext $context): JsonResponse
    {
        $tokenValue = $context->stringField('token');
        $new = $context->stringField('new_password');

        $pdo = $context->db->get();
        $tenant = $context->tenant;
        $throttle = new PasswordResetThrottle($pdo);
        $ip = IpPrefix::fromAddress($context->request->remoteAddress);
        $retryAfter = $throttle->confirmRetryAfter($ip);
        if ($retryAfter !== null) {
            throw HttpException::tooManyAttempts($retryAfter);
        }

        $tokens = new UserTokenRepository($pdo);
        $tokens->deleteExpired();
        $users = new UserRepository($pdo);
        $token = OneTimeToken::fromString($tokenValue);
        $userId = $token === null ? null : $tokens->findUserId(TokenPurpose::ResetPassword, $token);
        $user = $userId === null ? null : $users->findById($userId);
        if ($token === null || $user === null || !SessionManager::mayUse($user, $tenant)) {
            $throttle->recordConfirm($tenant?->id, $ip, false);
            throw HttpException::invalidToken();
        }

        $names = array_values(array_filter([$user->name, $user->displayName], static fn (?PersonName $n): bool => $n !== null));
        try {
            PasswordPolicy::default()->check($new, new PasswordContext($user->email, $names, $tenant));
        } catch (WeakPasswordException $e) {
            throw HttpException::weakPassword($e->getMessage());
        }

        // Löst den Link ein und beendet alle Sitzungen; false, wenn ihn inzwischen jemand anderes
        // eingelöst hat oder das Konto gesperrt wurde.
        if (!$users->resetPassword($user->id, $context->hasher->hash($new), $token->hash())) {
            $throttle->recordConfirm($tenant?->id, $ip, false);
            throw HttpException::invalidToken();
        }
        $throttle->recordConfirm($tenant?->id, $ip, true);
        // Wer sich ausgesperrt hat und deshalb zurücksetzt, soll sich sofort anmelden können.
        new LoginThrottle($pdo)->record($tenant?->id, LoginThrottle::emailHash($user->email?->value ?? ''), $ip, true);

        $context->notifier()->send(
            'password_changed',
            $user->id,
            static fn (): MailMessage => MailTemplates::passwordChanged($user, $tenant, $context->urls->area($tenant)),
        );

        return new JsonResponse(
            ['message' => self::MESSAGE],
            200,
            SessionCookie::isPresent($context->request) ? ['Set-Cookie' => SessionCookie::clear($tenant)] : [],
        );
    }
}
