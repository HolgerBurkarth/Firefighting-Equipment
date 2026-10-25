<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Mail;

use FirefightingEquipment\Mail\MailException;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\Mail\MimeMessage;
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

    public function testVerifyEmail(): void
    {
        $link = 'http://localhost:8000/firefighting/immendingen/email-bestaetigen#token=AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

        $mail = MailTemplates::verifyEmail(self::user(displayName: null, status: UserStatus::Unverified), self::tenant(), $link);

        self::assertSame('Bitte bestätigen Sie Ihre E-Mail-Adresse', $mail->subject);
        self::assertStringStartsWith("Hallo Erika Musterfrau,\n", $mail->text, 'noch kein Anzeigename');
        self::assertStringContainsString("\n" . $link . "\n", $mail->text);
        self::assertStringContainsString('(Feuerwehr Immendingen)', $mail->text);
        self::assertStringContainsString('24 Stunden gültig', $mail->text);
        self::assertStringContainsString('nach 7 Tagen', $mail->text);
        self::assertContactsHint($mail);
        self::assertTextRules($mail, $link);
    }

    public function testRegistrationExists(): void
    {
        $url = 'http://localhost:8000/firefighting/immendingen/';

        $mail = MailTemplates::registrationExists(self::user(), self::tenant(), $url);

        self::assertSame('Registrierung mit Ihrer E-Mail-Adresse', $mail->subject);
        self::assertSame('erika@example.org', $mail->to->value);
        self::assertStringContainsString("es wurde kein neues
angelegt.", $mail->text);
        self::assertStringContainsString('„Passwort vergessen“', $mail->text);
        self::assertStringContainsString("\n" . $url . "\n", $mail->text);
        self::assertContactsHint($mail);
        self::assertTextRules($mail, $url);
    }

    public function testRegistrationExistsForInvitedAccountWithoutName(): void
    {
        $mail = MailTemplates::registrationExists(self::user(displayName: null, name: null), self::tenant(), 'https://www.prodad.de/firefighting/immendingen/');

        self::assertNull($mail->toName);
        self::assertStringStartsWith("Hallo,\n", $mail->text);
    }

    public function testRegistrationPendingNamesNewPersonToAdmin(): void
    {
        $url = 'http://localhost:8000/firefighting/immendingen/';
        $registered = self::user(displayName: null, email: 'max@example.org', name: 'Max Mustermann', status: UserStatus::Pending);

        $mail = MailTemplates::registrationPending(self::user(role: UserRole::Admin), self::tenant(), $registered, $url);

        self::assertSame('erika@example.org', $mail->to->value, 'an den Admin');
        self::assertSame('Neue Registrierung wartet auf Freischaltung', $mail->subject);
        self::assertStringStartsWith("Hallo Erika,\n", $mail->text);
        self::assertStringContainsString("\nName: Max Mustermann\nE-Mail: max@example.org\n", $mail->text);
        self::assertStringContainsString("\n" . $url . "\n", $mail->text);
        self::assertStringNotContainsString(MimeMessage::FROM_ADDRESS, $mail->text, 'kein Kontakt-Hinweis an Admins');
        self::assertTextRules($mail, $url);
    }

    public function testAccountApprovedNamesRole(): void
    {
        $url = 'http://localhost:8000/firefighting/immendingen/';

        $mail = MailTemplates::accountApproved(self::user(role: UserRole::Admin), self::tenant(), $url);

        self::assertSame('Ihr Konto wurde freigeschaltet', $mail->subject);
        self::assertStringContainsString('Ihre Rolle: Admin.', $mail->text);
        self::assertStringContainsString("\n" . $url . "\n", $mail->text);
        self::assertContactsHint($mail);
        self::assertTextRules($mail, $url);
        self::assertStringContainsString('Ihre Rolle: Benutzer.', MailTemplates::accountApproved(self::user(), self::tenant(), $url)->text);
    }

    public function testRegistrationRejectedIsShortAndNeutral(): void
    {
        $mail = MailTemplates::registrationRejected(self::user(displayName: null, status: UserStatus::Pending), self::tenant());

        self::assertSame('Ihre Registrierung wurde nicht freigegeben', $mail->subject);
        self::assertStringStartsWith("Hallo Erika Musterfrau,\n", $mail->text);
        self::assertStringContainsString('(Feuerwehr Immendingen)', $mail->text);
        self::assertStringContainsString('Ihre Angaben wurden gelöscht.', $mail->text);
        self::assertStringNotContainsString('http', $mail->text);
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

    private static function assertContactsHint(MailMessage $mail): void
    {
        self::assertStringContainsString(
            "fügen Sie bitte den\nAbsender feuerwehr-app@prodad.de zu Ihren Kontakten hinzu.\n",
            $mail->text,
        );
    }

    private static function user(
        ?string $displayName = 'Erika',
        UserRole $role = UserRole::User,
        ?string $email = 'erika@example.org',
        ?string $name = 'Erika Musterfrau',
        UserStatus $status = UserStatus::Active,
    ): User {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC'));

        return new User(
            7,
            $role === UserRole::SuperAdmin ? null : 1,
            $role,
            $status,
            $email === null ? null : EmailAddress::fromString($email),
            $name === null ? null : PersonName::fromString($name),
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
