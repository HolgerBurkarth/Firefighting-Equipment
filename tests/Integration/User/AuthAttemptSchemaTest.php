<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fe_auth_attempts: bekannte Aktionen, E-Mail nur als Hash, IP-Adressen nur gekürzt
 * (IPv4 /24, IPv6 /48).
 */
#[CoversNothing]
final class AuthAttemptSchemaTest extends AuthSchemaTestCase
{
    private int $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantId = $this->createTenant();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function actions(): iterable
    {
        yield 'Anmeldung' => ['login'];
        yield 'Registrierung' => ['register'];
        yield 'Passwort zurücksetzen' => ['password_reset'];
        yield 'Link zum Zurücksetzen einlösen' => ['reset_confirm'];
        yield 'E-Mail bestätigen' => ['verify_email'];
        yield 'Bestätigungslink neu anfordern' => ['verify_resend'];
    }

    #[DataProvider('actions')]
    public function testAttemptIsAcceptedForEachAction(string $action): void
    {
        $id = $this->createAttempt(['action' => $action]);

        self::assertLessThan(60, abs((int) $this->pdo()->query(
            'SELECT TIMESTAMPDIFF(SECOND, attempted_at, UTC_TIMESTAMP()) FROM fe_auth_attempts WHERE id = ' . $id,
        )->fetchColumn()));
    }

    public function testShortenedIpv6AndServiceAreaAreAccepted(): void
    {
        $this->createAttempt(['ip_prefix' => (string) inet_pton('2001:db8:abcd::'), 'tenant_id' => null]);

        self::assertSame(1, $this->countRows('fe_auth_attempts'));
    }

    public function testVerifyEmailWithoutEmailHashIsAccepted(): void
    {
        $this->createAttempt(['action' => 'verify_email', 'email_hash' => null]);

        self::assertSame(1, $this->countRows('fe_auth_attempts'));
    }

    public function testResetConfirmWithoutEmailHashIsAccepted(): void
    {
        $this->createAttempt(['action' => 'reset_confirm', 'email_hash' => null]);

        self::assertSame(1, $this->countRows('fe_auth_attempts'));
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidAttempts(): iterable
    {
        yield 'unbekannte Aktion' => [['action' => 'logout']];
        yield 'Aktion groß' => [['action' => 'LOGIN']];
        yield 'Anmeldung ohne E-Mail-Hash' => [['email_hash' => null]];
        yield 'Registrierung ohne E-Mail-Hash' => [['action' => 'register', 'email_hash' => null]];
        yield 'Passwort zurücksetzen ohne E-Mail-Hash' => [['action' => 'password_reset', 'email_hash' => null]];
        yield 'Einlösen groß' => [['action' => 'RESET_CONFIRM', 'email_hash' => null]];
        yield 'Neu anfordern ohne E-Mail-Hash' => [['action' => 'verify_resend', 'email_hash' => null]];
        yield 'Neu anfordern groß' => [['action' => 'VERIFY_RESEND']];
        yield 'Einlösen mit zu kurzem E-Mail-Hash' => [['action' => 'reset_confirm', 'email_hash' => str_repeat("\x01", 31)]];
        yield 'E-Mail-Hash zu kurz' => [['email_hash' => str_repeat("\x01", 31)]];
        yield 'vollständige IPv4-Adresse' => [['ip_prefix' => (string) inet_pton('192.0.2.17')]];
        yield 'vollständige IPv6-Adresse' => [['ip_prefix' => (string) inet_pton('2001:db8:abcd:12::1')]];
        yield 'IPv6 nur auf /64 gekürzt' => [['ip_prefix' => (string) inet_pton('2001:db8:abcd:12::')]];
        yield 'IP mit 3 Byte' => [['ip_prefix' => "\xC0\x00\x02"]];
        yield 'IP mit 5 Byte' => [['ip_prefix' => "\xC0\x00\x02\x00\x00"]];
        yield 'leere IP' => [['ip_prefix' => '']];
        yield 'Erfolg 2' => [['succeeded' => 2]];
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    #[DataProvider('invalidAttempts')]
    public function testDatabaseRejectsInvalidAttempt(array $overrides): void
    {
        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->createAttempt($overrides));
        self::assertSame(0, $this->countRows('fe_auth_attempts'));
    }

    public function testDatabaseRejectsTooLongIp(): void
    {
        $this->assertRejected(
            self::ER_DATA_TOO_LONG,
            fn () => $this->createAttempt(['ip_prefix' => str_repeat("\x00", 17)]),
        );
    }

    public function testDatabaseRejectsUnknownTenant(): void
    {
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->createAttempt(['tenant_id' => 999]));
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    private function createAttempt(array $overrides = []): int
    {
        return $this->insert('fe_auth_attempts', array_merge([
            'action' => 'login',
            'tenant_id' => $this->tenantId,
            'email_hash' => self::hash('max@example.org'),
            'ip_prefix' => (string) inet_pton('192.0.2.0'),
            'succeeded' => 0,
        ], $overrides));
    }
}
