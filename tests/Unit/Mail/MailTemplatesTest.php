<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Mail;

use FirefightingEquipment\Mail\MailException;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRole;
use FirefightingEquipment\User\UserStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MailTemplates::class)]
#[UsesClass(MailException::class)]
#[UsesClass(MailMessage::class)]
#[UsesClass(EmailAddress::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Tenant::class)]
#[UsesClass(TenantName::class)]
#[UsesClass(TenantSlug::class)]
#[UsesClass(User::class)]
final class MailTemplatesTest extends TestCase
{
    private const string LINK = 'http://localhost:8000/firefighting/immendingen/passwort-zuruecksetzen#token=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function testPasswordReset(): void
    {
        $mail = MailTemplates::passwordReset(self::user(), self::tenant(), self::LINK);

        self::assertSame('erika@example.org', $mail->to->value);
        self::assertSame('Erika', $mail->toName, 'Anzeigename vor Name');
        self::assertSame('Passwort zurücksetzen', $mail->subject);
        self::assertStringStartsWith("Hallo Erika,\n", $mail->text);
        self::assertStringContainsString("\n" . self::LINK . "\n", $mail->text, 'Link in eigener Zeile');
        self::assertStringContainsString('(Feuerwehr Immendingen)', $mail->text);
        self::assertStringContainsString('1 Stunde gültig', $mail->text);
        self::assertStringContainsString("\n-- \nFeuerwehr Geräteverwaltung\n", $mail->text);
        self::assertTextRules($mail, self::LINK);
    }

    public function testPasswordResetUnderServiceAndWithoutDisplayName(): void
    {
        $mail = MailTemplates::passwordReset(self::user(displayName: null, role: UserRole::SuperAdmin), null, self::LINK);

        self::assertSame('Erika Musterfrau', $mail->toName);
        self::assertStringStartsWith("Hallo Erika Musterfrau,\n", $mail->text);
        self::assertStringContainsString('(Verwaltung)', $mail->text);
    }

    public function testPasswordChangedShowsGermanTimeAndDevice(): void
    {
        $at = new \DateTimeImmutable('2026-10-25 13:04:00', new \DateTimeZone('UTC'));

        $mail = MailTemplates::passwordChanged(self::user(), self::tenant(), 'http://localhost:8000/firefighting/immendingen/', 'Chrome auf Android', $at);

        self::assertSame('Ihr Passwort wurde geändert', $mail->subject);
        self::assertStringContainsString('wurde am 25.10.2026 um 14:04 Uhr geändert.', $mail->text, 'MEZ nach Ende der Sommerzeit');
        self::assertStringContainsString("\nGerät: Chrome auf Android\n", $mail->text);
        self::assertStringContainsString('informieren Sie Ihren Admin', $mail->text);
        self::assertStringContainsString("\nhttp://localhost:8000/firefighting/immendingen/\n", $mail->text);
        self::assertTextRules($mail, 'http://localhost:8000/firefighting/immendingen/');
    }

    public function testPasswordChangedInSummerTimeWithoutDevice(): void
    {
        $at = new \DateTimeImmutable('2026-07-01 06:30:00', new \DateTimeZone('UTC'));

        $mail = MailTemplates::passwordChanged(self::user(), null, 'https://www.prodad.de/firefighting/service/', null, $at);

        self::assertStringContainsString('wurde am 01.07.2026 um 08:30 Uhr geändert.', $mail->text);
        self::assertStringNotContainsString('Gerät:', $mail->text);
        self::assertStringContainsString('informieren Sie einen anderen Super-Admin', $mail->text);
    }

    public function testTestMail(): void
    {
        $mail = MailTemplates::testMail(EmailAddress::fromString('test@example.org'), new \DateTimeImmutable('2026-10-25 13:04:05', new \DateTimeZone('UTC')));

        self::assertSame('test@example.org', $mail->to->value);
        self::assertNull($mail->toName);
        self::assertStringContainsString('ä ö ü', $mail->subject);
        self::assertStringContainsString('25.10.2026 14:04:05', $mail->text);
        self::assertStringContainsString('ä ö ü Ä Ö Ü ß', $mail->text);
        self::assertTextRules($mail);
    }

    public function testAnonymizedAccountGetsNoMail(): void
    {
        $this->expectException(MailException::class);

        MailTemplates::passwordReset(self::user(email: null), self::tenant(), self::LINK);
    }

    /**
     * Klartext ohne HTML, Zeilen höchstens 76 Zeichen (außer der Zeile mit dem Link), LF am Ende.
     */
    private static function assertTextRules(MailMessage $mail, ?string $link = null): void
    {
        self::assertStringNotContainsString('<', str_replace($link ?? '', '', $mail->text), 'Kein HTML');
        self::assertStringEndsWith(".\n", $mail->text);
        foreach (explode("\n", $mail->text) as $line) {
            if ($line !== $link) {
                self::assertLessThanOrEqual(76, mb_strlen($line), $line);
            }
        }
    }

    private static function user(
        ?string $displayName = 'Erika',
        UserRole $role = UserRole::User,
        ?string $email = 'erika@example.org',
    ): User {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));

        return new User(
            7,
            $role === UserRole::SuperAdmin ? null : 1,
            $role,
            UserStatus::Active,
            $email === null ? null : EmailAddress::fromString($email),
            PersonName::fromString('Erika Musterfrau'),
            $displayName === null ? null : PersonName::fromString($displayName),
            $now,
            null,
            null,
            $now,
            $now,
        );
    }

    private static function tenant(): Tenant
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));

        return new Tenant(1, TenantSlug::fromString('immendingen'), TenantName::fromString('Feuerwehr Immendingen'), true, $now, $now);
    }
}
