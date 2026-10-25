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
use FirefightingEquipment\Invitation\InvitationCodeNotUsableException;
use FirefightingEquipment\Invitation\InvitationCodeRepository;
use FirefightingEquipment\Invitation\InvitationCodeValue;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\InvalidUserValueException;
use FirefightingEquipment\User\Password\PasswordContext;
use FirefightingEquipment\User\Password\PasswordPolicy;
use FirefightingEquipment\User\Password\WeakPasswordException;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\UserRepository;

/**
 * POST api/v1/auth/register {code, email, password, name} (öffentlich, nur unter einer Stadt):
 * Registrierung mit Einladungscode.
 *
 * Reihenfolge: Schreibweise (400), Bremse (429), Einladungscode (400 invalid_invitation_code, eine
 * gemeinsame Meldung, zählt in die Bremse), Passwortregeln mit E-Mail, Name und Stadt (422), dann
 * UserRepository::register. Neues Konto: Status unverified, Bestätigungslink per Mail. Ist die
 * E-Mail in der Stadt schon vergeben, entsteht kein Konto und die Code-Nutzung zählt nicht; der
 * Besitzer der Adresse bekommt einen Hinweis. Beides antwortet gleich (202, gleiche Meldung,
 * Argon2id in beiden Fällen, Mindestdauer 1,5 s, je eine Mail).
 */
final readonly class RegisterHandler implements RequestHandler
{
    public const float MIN_DURATION_SECONDS = 1.5;

    public const string MESSAGE =
        'Bitte prüfen Sie Ihr E-Mail-Postfach: Sie erhalten in Kürze eine E-Mail mit den nächsten Schritten.';

    public function __construct(private MinimumDuration $duration = new MinimumDuration(self::MIN_DURATION_SECONDS))
    {
    }

    public function handle(RequestContext $context): JsonResponse
    {
        $started = $this->duration->start();
        $tenant = $context->tenant ?? throw new \LogicException('Registrierung nur unter einer Stadt.');

        $codeInput = $context->stringField('code');
        $password = $context->stringField('password');
        try {
            $email = EmailAddress::fromString($context->stringField('email'));
        } catch (InvalidUserValueException) {
            throw HttpException::invalidRequest('Bitte eine gültige E-Mail-Adresse angeben.');
        }
        try {
            $name = PersonName::fromString($context->stringField('name'));
        } catch (InvalidUserValueException $e) {
            throw HttpException::invalidRequest($e->getMessage());
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
        $retryAfter = $throttle->registerRetryAfter($tenant->id, $emailHash, $ip);
        if ($retryAfter !== null) {
            throw HttpException::tooManyAttempts($retryAfter);
        }

        $code = InvitationCodeValue::fromInput($codeInput);
        if ($code === null || new InvitationCodeRepository($pdo)->findUsableId($tenant, $code) === null) {
            $throttle->recordRegister($tenant->id, $emailHash, $ip, false);
            throw HttpException::invalidInvitationCode();
        }

        try {
            PasswordPolicy::default()->check($password, new PasswordContext($email, [$name], $tenant));
        } catch (WeakPasswordException $e) {
            throw HttpException::weakPassword($e->getMessage());
        }

        try {
            $registration = $users->register($tenant, $code, $email, $name, $context->hasher->hash($password));
        } catch (InvitationCodeNotUsableException) {
            // Inzwischen aufgebraucht oder widerrufen.
            $throttle->recordRegister($tenant->id, $emailHash, $ip, false);
            throw HttpException::invalidInvitationCode();
        }
        $throttle->recordRegister($tenant->id, $emailHash, $ip, true);

        $user = $registration->user;
        if ($registration->created) {
            $token = $tokens->replace($user->id, TokenPurpose::VerifyEmail);
            $context->notifier()->send(
                'verify_email',
                $user->id,
                static fn (): MailMessage => MailTemplates::verifyEmail($user, $tenant, $context->urls->verifyEmail($tenant, $token)),
            );
        } else {
            $context->notifier()->send(
                'registration_exists',
                $user->id,
                static fn (): MailMessage => MailTemplates::registrationExists($user, $tenant, $context->urls->area($tenant)),
            );
        }

        if (!$this->duration->waitSince($started)) {
            $context->errors->report(
                'registration_slow',
                new \RuntimeException(sprintf('Mindestdauer von %.1f s überschritten; bitte erhöhen.', $this->duration->seconds)),
            );
        }

        return new JsonResponse(['message' => self::MESSAGE], 202);
    }
}
