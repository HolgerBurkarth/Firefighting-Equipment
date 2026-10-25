<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Auth\IpPrefix;
use FirefightingEquipment\Auth\LoginThrottle;
use FirefightingEquipment\Auth\PasswordResetThrottle;
use FirefightingEquipment\Auth\TokenPurpose;
use FirefightingEquipment\Auth\UserTokenRepository;
use FirefightingEquipment\Http\HttpException;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Http\MinimumDuration;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Http\RequestHandler;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\InvalidUserValueException;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserStatus;

/**
 * POST api/v1/auth/password-reset/request {email}: Link zum Zurücksetzen per Mail anfordern.
 *
 * Antwortet immer gleich (202, gleiche Meldung, Mindestdauer 1,5 s), ob die Adresse zu einem Konto
 * gehört oder nicht. Eine Mail bekommen nur aktive, nicht gesperrte Konten des Bereichs (unter
 * einer Stadt deren Konten, unter service nur Super-Admins). Ein neuer Link ersetzt den alten.
 * Nur eine ungültige Schreibweise der Adresse (400) und die Bremse (429) antworten anders; beides
 * verrät nichts über Konten.
 */
final readonly class PasswordResetRequestHandler implements RequestHandler
{
    public const float MIN_DURATION_SECONDS = 1.5;

    public const string MESSAGE =
        'Falls zu dieser Adresse ein Konto besteht, erhalten Sie in Kürze eine E-Mail mit einem Link zum Zurücksetzen des Passworts.';

    public function __construct(private MinimumDuration $duration = new MinimumDuration(self::MIN_DURATION_SECONDS))
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $started = $this->duration->start();

        try {
            $email = EmailAddress::fromString($context->stringField('email'));
        } catch (InvalidUserValueException) {
            throw HttpException::invalidRequest('Bitte eine gültige E-Mail-Adresse angeben.');
        }

        $pdo = $context->db->get();
        new LoginThrottle($pdo)->deleteOld();
        $tokens = new UserTokenRepository($pdo);
        $tokens->deleteExpired();

        $tenant = $context->tenant;
        $throttle = new PasswordResetThrottle($pdo);
        $emailHash = LoginThrottle::emailHash($email->value);
        $ip = IpPrefix::fromAddress($context->request->remoteAddress);
        $retryAfter = $throttle->requestRetryAfter($tenant?->id, $emailHash, $ip);
        if ($retryAfter !== null) {
            throw HttpException::tooManyAttempts($retryAfter);
        }
        $throttle->recordRequest($tenant?->id, $emailHash, $ip);

        $users = new UserRepository($pdo);
        $user = $tenant === null ? $users->findSuperAdminByEmail($email) : $users->findByTenantAndEmail($tenant, $email);
        if ($user !== null && self::mayReset($user)) {
            $token = $tokens->replace($user->id, TokenPurpose::ResetPassword);
            $context->notifier()->send(
                'password_reset',
                $user->id,
                static fn (): MailMessage => MailTemplates::passwordReset($user, $tenant, $context->urls->passwordReset($tenant, $token)),
            );
        }

        if (!$this->duration->waitSince($started)) {
            $context->errors->report(
                'password_reset_slow',
                new \RuntimeException(sprintf('Mindestdauer von %.1f s überschritten; bitte erhöhen.', $this->duration->seconds)),
            );
        }

        return new JsonResponse(['message' => self::MESSAGE], 202);
    }

    private static function mayReset(User $user): bool
    {
        return $user->status === UserStatus::Active && !$user->isLocked();
    }
}
