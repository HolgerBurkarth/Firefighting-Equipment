<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Auth\Authenticator;
use FirefightingEquipment\Auth\DeviceLabel;
use FirefightingEquipment\Auth\InvalidCredentialsException;
use FirefightingEquipment\Auth\IpPrefix;
use FirefightingEquipment\Auth\SessionCookie;
use FirefightingEquipment\Auth\SessionLifetime;
use FirefightingEquipment\Auth\SessionManager;
use FirefightingEquipment\Auth\TooManyAttemptsException;
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
 * POST api/v1/auth/password {current_password, new_password}: eigenes Passwort ändern.
 *
 * Prüft das aktuelle Passwort (Fehlversuche zählen in die Bremse der Anmeldung) und die
 * Passwortregeln (Kontext E-Mail, Name, Anzeigename, Stadt). Danach sind alle Sitzungen des Kontos
 * beendet; die aktuelle wird durch eine neue ersetzt (neues Cookie), angemeldet bleibt also nur
 * dieses Gerät. Das Konto bekommt eine Benachrichtigung (ein Fehler beim Versand ändert die
 * Antwort nicht).
 */
final readonly class PasswordChangeHandler implements RequestHandler
{
    public function handle(RequestContext $context): JsonResponse
    {
        $current = $context->stringField('current_password');
        $new = $context->stringField('new_password');
        $auth = $context->auth();
        $user = $auth->user;
        $pdo = $context->db->get();

        try {
            new Authenticator($pdo, $context->hasher)->verifyCurrentPassword(
                $user,
                $context->tenant,
                $current,
                IpPrefix::fromAddress($context->request->remoteAddress),
            );
        } catch (TooManyAttemptsException $e) {
            throw HttpException::tooManyAttempts($e->retryAfterSeconds);
        } catch (InvalidCredentialsException) {
            throw HttpException::invalidCurrentPassword();
        }

        $names = array_values(array_filter([$user->name, $user->displayName], static fn (?PersonName $n): bool => $n !== null));
        try {
            PasswordPolicy::default()->check($new, new PasswordContext($user->email, $names, $context->tenant));
        } catch (WeakPasswordException $e) {
            throw HttpException::weakPassword($e->getMessage());
        }

        // Beendet alle Sitzungen des Kontos, auch die aktuelle.
        new UserRepository($pdo)->setPassword($user->id, $context->hasher->hash($new));

        $label = $auth->session->deviceLabel ?? DeviceLabel::fromUserAgent($context->request->header('User-Agent'));
        $started = new SessionManager($pdo)->start($user, $label);

        $tenant = $context->tenant;
        $context->notifier()->send(
            'password_changed',
            $user->id,
            static fn (): MailMessage => MailTemplates::passwordChanged($user, $tenant, $context->urls->area($tenant), $label),
        );

        return new JsonResponse(
            AuthJson::me($started->auth, $context->tenant),
            200,
            ['Set-Cookie' => SessionCookie::issue($context->tenant, $started->token, SessionLifetime::maxSeconds($user->role))],
        );
    }
}
