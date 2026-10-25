<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\InvalidUserValueException;
use FirefightingEquipment\User\Password\PasswordHasher;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserStatus;

/**
 * Prüft Anmeldedaten mit Bremse (LoginThrottle).
 *
 * Unbekannte E-Mail und falsches Passwort sind von außen nicht unterscheidbar: gleiche Meldung und
 * gleiche Laufzeit, weil ohne Konto (oder ohne Passwort, z. B. invited) gegen den Dummy-Hash des
 * Hashers (PasswordHasher::dummyHash(), gleiche Parameter wie echte Hashes) geprüft wird. Erst bei
 * richtigem Passwort wird ein gesperrtes oder noch nicht freigeschaltetes Konto genauer gemeldet.
 */
final readonly class Authenticator
{
    private UserRepository $users;
    private LoginThrottle $throttle;

    public function __construct(\PDO $pdo, private PasswordHasher $hasher = new PasswordHasher())
    {
        $this->users = new UserRepository($pdo);
        $this->throttle = new LoginThrottle($pdo);
    }

    /**
     * @param Tenant|null $tenant null = Bereich service (nur Super-Admins)
     *
     * @return User das aktive, nicht gesperrte Konto
     *
     * @throws TooManyAttemptsException
     * @throws InvalidCredentialsException
     * @throws AccountUnavailableException
     */
    public function login(?Tenant $tenant, string $email, #[\SensitiveParameter] string $password, string $ipPrefix): User
    {
        try {
            $address = EmailAddress::fromString($email);
        } catch (InvalidUserValueException) {
            $address = null;
        }
        $emailHash = LoginThrottle::emailHash($address?->value ?? $email);
        $this->assertNotThrottled($tenant, $emailHash, $ipPrefix);
        // Vor der Suche, damit ein erstmals berechneter Dummy-Hash nicht nur ohne Konto Zeit kostet.
        $dummyHash = $this->hasher->dummyHash();

        $user = match (true) {
            $address === null => null,
            $tenant === null => $this->users->findSuperAdminByEmail($address),
            default => $this->users->findByTenantAndEmail($tenant, $address),
        };
        // Auch ohne Konto abfragen und prüfen: gleiche Laufzeit.
        $hash = $this->users->passwordHash($user?->id ?? 0);
        $valid = $this->hasher->verify($password, $hash ?? $dummyHash) && $user !== null && $hash !== null;

        $this->throttle->record($tenant?->id, $emailHash, $ipPrefix, $valid);
        if (!$valid) {
            throw new InvalidCredentialsException();
        }

        if ($this->hasher->needsRehash($hash)) {
            $this->users->rehashPassword($user->id, $hash, $this->hasher->hash($password));
        }

        if ($user->isLocked()) {
            throw new AccountUnavailableException(AccountUnavailableException::LOCKED);
        }

        return match ($user->status) {
            UserStatus::Active => $user,
            UserStatus::Unverified => throw new AccountUnavailableException(AccountUnavailableException::EMAIL_NOT_VERIFIED),
            UserStatus::Pending => throw new AccountUnavailableException(AccountUnavailableException::PENDING_APPROVAL),
            // Ohne Passwort-Hash (per CHECK) und daher oben schon abgewiesen.
            UserStatus::Invited, UserStatus::Deleted => throw new InvalidCredentialsException(),
        };
    }

    /**
     * Prüft das aktuelle Passwort eines angemeldeten Kontos (z. B. vor einer Passwortänderung).
     * Fehlversuche zählen in dieselbe Bremse wie die Anmeldung.
     *
     * @param Tenant|null $tenant null = Bereich service
     *
     * @throws TooManyAttemptsException
     * @throws InvalidCredentialsException
     */
    public function verifyCurrentPassword(User $user, ?Tenant $tenant, #[\SensitiveParameter] string $password, string $ipPrefix): void
    {
        $emailHash = LoginThrottle::emailHash($user->email?->value ?? '');
        $this->assertNotThrottled($tenant, $emailHash, $ipPrefix);

        $hash = $this->users->passwordHash($user->id);
        $valid = $this->hasher->verify($password, $hash ?? $this->hasher->dummyHash()) && $hash !== null;

        $this->throttle->record($tenant?->id, $emailHash, $ipPrefix, $valid);
        if (!$valid) {
            throw new InvalidCredentialsException();
        }
    }

    /**
     * @throws TooManyAttemptsException
     */
    private function assertNotThrottled(?Tenant $tenant, string $emailHash, string $ipPrefix): void
    {
        $retryAfter = $this->throttle->retryAfter($tenant?->id, $emailHash, $ipPrefix);
        if ($retryAfter !== null) {
            throw new TooManyAttemptsException($retryAfter);
        }
    }
}
