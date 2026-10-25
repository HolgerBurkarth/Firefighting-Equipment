<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\User;

/**
 * Deutsche Textbausteine aller Mails (nur Klartext). Zeilen höchstens 76 Zeichen, außer Links
 * (die müssen am Stück bleiben). Zeitangaben in deutscher Zeit (Europe/Berlin).
 */
final class MailTemplates
{
    public const string TIME_ZONE = 'Europe/Berlin';

    /** "-- " mit Leerzeichen: Trennzeile einer Signatur (Mailprogramme blenden sie ab hier ab). */
    private const string SIGNATURE = "\n\n-- \nFeuerwehr Geräteverwaltung\n"
        . "Diese E-Mail wurde automatisch erstellt. Bitte nicht darauf antworten.\n";

    /**
     * @param Tenant|null $tenant null = Bereich service
     *
     * @throws MailException
     */
    public static function passwordReset(User $user, ?Tenant $tenant, string $link): MailMessage
    {
        $greeting = self::greeting($user);
        $area = self::area($tenant);
        $text = <<<TXT
            {$greeting}

            für Ihr Konto bei der Feuerwehr Geräteverwaltung ({$area})
            wurde das Zurücksetzen des Passworts angefordert.

            Über diesen Link legen Sie ein neues Passwort fest:

            {$link}

            Der Link ist 1 Stunde gültig und kann nur einmal verwendet werden.

            Falls Sie das nicht angefordert haben, ignorieren Sie diese E-Mail.
            Ihr bisheriges Passwort bleibt dann gültig.
            TXT;

        return self::message($user, 'Passwort zurücksetzen', $text);
    }

    /**
     * @param Tenant|null $tenant      null = Bereich service
     * @param string      $loginUrl    Startseite des Bereichs (dort: „Passwort vergessen“)
     * @param string|null $deviceLabel Gerät, auf dem geändert wurde (z. B. "Chrome auf Android")
     *
     * @throws MailException
     */
    public static function passwordChanged(
        User $user,
        ?Tenant $tenant,
        string $loginUrl,
        ?string $deviceLabel = null,
        ?\DateTimeImmutable $at = null,
    ): MailMessage {
        $local = ($at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(self::TIME_ZONE));
        $when = $local->format('d.m.Y') . ' um ' . $local->format('H:i') . ' Uhr';
        $device = $deviceLabel === null ? '' : "\nGerät: " . $deviceLabel;
        $inform = $tenant === null ? 'einen anderen Super-Admin' : 'Ihren Admin';
        $greeting = self::greeting($user);
        $area = self::area($tenant);

        $text = <<<TXT
            {$greeting}

            das Passwort Ihres Kontos bei der Feuerwehr Geräteverwaltung
            ({$area}) wurde am {$when} geändert.{$device}

            Bestehende Anmeldungen wurden beendet.

            Falls Sie das selbst waren, ist nichts weiter zu tun.

            Falls nicht: Setzen Sie Ihr Passwort sofort über „Passwort vergessen“
            auf der Anmeldeseite zurück und informieren Sie {$inform}:

            {$loginUrl}
            TXT;

        return self::message($user, 'Ihr Passwort wurde geändert', $text);
    }

    /**
     * Testmail für bin/mail-test.php (Zustellung, SPF, DKIM, Umlaute).
     *
     * @throws MailException
     */
    public static function testMail(EmailAddress $to, \DateTimeImmutable $at): MailMessage
    {
        $local = $at->setTimezone(new \DateTimeZone(self::TIME_ZONE));
        $when = $local->format('d.m.Y H:i:s');

        $text = <<<TXT
            Hallo,

            dies ist eine Testmail der Feuerwehr Geräteverwaltung, erzeugt am
            {$when} (deutsche Zeit) mit bin/mail-test.php.

            Umlaute zur Kontrolle: ä ö ü Ä Ö Ü ß – „Anführungszeichen“ – €

            Zum Prüfen der Zustellung im Mailprogramm die Kopfzeilen bzw. den
            Quelltext anzeigen (SPF, DKIM, Return-Path).
            TXT;

        return new MailMessage($to, null, 'Testmail der Feuerwehr Geräteverwaltung (ä ö ü)', $text . self::SIGNATURE);
    }

    private static function greeting(User $user): string
    {
        $name = $user->displayName ?? $user->name;

        return $name === null ? 'Hallo,' : 'Hallo ' . $name->value . ',';
    }

    /**
     * Bereich des Kontos: Name der Stadt bzw. die Verwaltung (service).
     */
    private static function area(?Tenant $tenant): string
    {
        return $tenant === null ? 'Verwaltung' : $tenant->name->value;
    }

    /**
     * @throws MailException
     */
    private static function message(User $user, string $subject, string $text): MailMessage
    {
        $email = $user->email ?? throw new MailException(sprintf('Konto #%d hat keine E-Mail-Adresse.', $user->id));

        return new MailMessage($email, ($user->displayName ?? $user->name)?->value, $subject, $text . self::SIGNATURE);
    }
}
