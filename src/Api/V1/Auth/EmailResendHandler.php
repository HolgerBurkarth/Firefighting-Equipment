<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\Auth;

use FirefightingEquipment\Auth\IpPrefix;
use FirefightingEquipment\Auth\LoginThrottle;
use FirefightingEquipment\Auth\RegistrationThrottle;
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
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserStatus;

/**
 * POST api/v1/auth/email/resend {email} (öffentlich, nur unter einer Stadt): neuen
 * Bestätigungslink anfordern.
 *
 * Antwortet immer gleich (202, gleiche Meldung, Mindestdauer 1,5 s), ob die Adresse zu einer
 * unbestätigten Registrierung gehört oder nicht. Eine Mail bekommen nur Konten der Stadt im Status
 * unverified, die nicht gesperrt sind; ein neuer Link ersetzt den alten. Nur eine ungültige
 * Schreibweise (400) und die Bremse (429) antworten anders.
 */
final readonly class EmailResendHandler implements RequestHandler
{
    public const float MIN_DURATION_SECONDS = 1.5;

    public const string MESSAGE =
        'Falls zu dieser Adresse eine unbestätigte Registrierung besteht, erhalten Sie in Kürze eine E-Mail mit einem neuen Bestätigungslink.';

    public function __construct(private MinimumDuration $duration = new MinimumDuration(self::MIN_DURATION_SECONDS))
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $started = $this->duration->start();
        $tenant = $context->tenant ?? throw new \LogicException('Nur unter einer Stadt.');

        try {
            $email = EmailAddress::fromString($context->stringField('email'));
        } catch (InvalidUserValueException) {
            throw HttpException::invalidRequest('Bitte eine gültige E-Mail-Adresse angeben.');
        }

        $pdo = $context->db->get();
        new LoginThrottle($pdo)->deleteOld();
        $tokens = new UserTokenRepository($pdo);
        $tokens->deleteExpired();
        $users = new UserRepository($pdo);
        $users->anonymizeStaleUnverified($tenant);

        $throttle = new RegistrationThrottle($pdo);
        $emailHash = LoginThrottle::emailHash($email->value);
        $ip = IpPrefix::fromAddress($context->request->remoteAddress);
        $retryAfter = $throttle->resendRetryAfter($tenant->id, $emailHash, $ip);
        if ($retryAfter !== null) {
            throw HttpException::tooManyAttempts($retryAfter);
        }
        $throttle->recordResend($tenant->id, $emailHash, $ip);

        $user = $users->findByTenantAndEmail($tenant, $email);
        if ($user !== null && $user->status === UserStatus::Unverified && !$user->isLocked()) {
            $token = $tokens->replace($user->id, TokenPurpose::VerifyEmail);
            $context->notifier()->send(
                'verify_email',
                $user->id,
                static fn (): MailMessage => MailTemplates::verifyEmail($user, $tenant, $context->urls->verifyEmail($tenant, $token)),
            );
        }

        if (!$this->duration->waitSince($started)) {
            $context->errors->report(
                'verify_resend_slow',
                new \RuntimeException(sprintf('Mindestdauer von %.1f s überschritten; bitte erhöhen.', $this->duration->seconds)),
            );
        }

        return new JsonResponse(['message' => self::MESSAGE], 202);
    }
}
