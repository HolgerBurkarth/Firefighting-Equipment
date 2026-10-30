<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Auth\IpPrefix;
use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Auth\RegistrationThrottle;
use FirefightingEquipment\Auth\TokenPurpose;
use FirefightingEquipment\Auth\UserTokenRepository;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\User\UserRepository;

/**
 * POST api/v1/auth/email/verify {token} (öffentlich, nur unter einer Stadt): E-Mail-Adresse mit
 * dem Link aus der Mail bestätigen. Das Konto wartet danach auf Freischaltung (pending), und jeder
 * aktive, nicht gesperrte Admin der Stadt bekommt einen Hinweis.
 *
 * Ungültig (400 invalid_token, zählt in die Bremse) sind: falsches Format, unbekannt, anderer
 * Zweck, abgelaufen, schon benutzt, Konto nicht mehr unverified oder gesperrt, Konto einer anderen
 * Stadt. Ein Cookie spielt keine Rolle (es entsteht keine Sitzung).
 */
final readonly class EmailVerifyHandler implements RequestHandler
{
    public const string MESSAGE = 'Ihre E-Mail-Adresse ist bestätigt. Ein Admin Ihrer Feuerwehr schaltet Ihr Konto frei;'
        . ' darüber erhalten Sie eine E-Mail.';

    public function handle(RequestContext $context): JsonResponse
    {
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');
        $tokenValue = $context->stringField('token');

        $pdo = $context->db->get();
        $throttle = new RegistrationThrottle($pdo);
        $ip = IpPrefix::fromAddress($context->request->remoteAddress);
        $retryAfter = $throttle->verifyRetryAfter($ip);
        if ($retryAfter !== null) {
            throw HttpException::tooManyAttempts($retryAfter);
        }

        $tokens = new UserTokenRepository($pdo);
        $tokens->deleteExpired();
        $users = new UserRepository($pdo);
        $users->anonymizeStaleUnverified($tenant);

        $token = OneTimeToken::fromString($tokenValue);
        $userId = $token === null ? null : $tokens->findUserId(TokenPurpose::VerifyEmail, $token);
        $user = $userId === null ? null : $users->findByTenantAndId($tenant, $userId);
        // verifyEmail prüft Status und Sperre selbst (in der Transaktion) und löst den Link ein.
        if ($token === null || $user === null || !$users->verifyEmail($user->id, $token->hash())) {
            $throttle->recordVerify($tenant->id, $ip, false);
            throw HttpException::invalidToken();
        }
        $throttle->recordVerify($tenant->id, $ip, true);

        $registered = $users->findById($user->id) ?? $user;
        foreach ($users->listActiveAdmins($tenant) as $admin) {
            $context->notifier()->send(
                'registration_pending',
                $admin->id,
                static fn (): MailMessage => MailTemplates::registrationPending($admin, $tenant, $registered, $context->urls->users($tenant)),
            );
        }

        return new JsonResponse(['message' => self::MESSAGE]);
    }
}
