<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRepository;

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
     * Nach der Registrierung und beim Neu-Anfordern: Link zum Bestätigen der E-Mail-Adresse.
     *
     * @throws MailException
     */
    public static function verifyEmail(User $user, Tenant $tenant, string $link): MailMessage
    {
        $greeting = self::greeting($user);
        $area = self::area($tenant);
        $days = UserRepository::UNVERIFIED_RETENTION_DAYS;
        $contacts = self::contactsHint();
        $text = <<<TXT
            {$greeting}

            vielen Dank für Ihre Registrierung bei der Feuerwehr Geräteverwaltung
            ({$area}).

            Bitte bestätigen Sie Ihre E-Mail-Adresse über diesen Link:

            {$link}

            Der Link ist 24 Stunden gültig und kann nur einmal verwendet werden.
            Danach prüft ein Admin Ihrer Feuerwehr die Registrierung und schaltet
            Ihr Konto frei; darüber erhalten Sie eine weitere E-Mail.

            Ist der Link abgelaufen, können Sie auf der Anmeldeseite einen neuen
            anfordern. Ohne Bestätigung wird die Registrierung nach {$days} Tagen
            gelöscht. Falls Sie sich nicht registriert haben, ignorieren Sie diese
            E-Mail.

            {$contacts}
            TXT;

        return self::message($user, 'Bitte bestätigen Sie Ihre E-Mail-Adresse', $text);
    }

    /**
     * Registrierung mit einer Adresse, für die in der Stadt schon ein Konto besteht (an den Besitzer;
     * es wurde nichts geändert).
     *
     * @param string $loginUrl Startseite der Stadt (Anmeldung, „Passwort vergessen“)
     *
     * @throws MailException
     */
    public static function registrationExists(User $user, Tenant $tenant, string $loginUrl): MailMessage
    {
        $greeting = self::greeting($user);
        $area = self::area($tenant);
        $contacts = self::contactsHint();
        $text = <<<TXT
            {$greeting}

            mit Ihrer E-Mail-Adresse wurde soeben eine Registrierung bei der
            Feuerwehr Geräteverwaltung ({$area}) versucht.
            Für diese Adresse besteht dort bereits ein Konto; es wurde kein neues
            angelegt.

            Falls Sie das waren: Melden Sie sich mit Ihrem bestehenden Konto an.
            Haben Sie Ihr Passwort vergessen, nutzen Sie „Passwort vergessen“ auf
            der Anmeldeseite. Ist Ihre Adresse noch nicht bestätigt, können Sie
            dort einen neuen Bestätigungslink anfordern:

            {$loginUrl}

            Falls nicht, ignorieren Sie diese E-Mail. An Ihrem Konto wurde nichts
            geändert.

            {$contacts}
            TXT;

        return self::message($user, 'Registrierung mit Ihrer E-Mail-Adresse', $text);
    }

    /**
     * An einen Admin der Stadt: Eine Registrierung ist bestätigt und wartet auf Freischaltung.
     * Name und E-Mail der neuen Person stehen in eigenen Zeilen (können länger als 76 Zeichen sein).
     *
     * @param string $url Startseite der Stadt (dort: Benutzerverwaltung)
     *
     * @throws MailException
     */
    public static function registrationPending(User $admin, Tenant $tenant, User $registered, string $url): MailMessage
    {
        $greeting = self::greeting($admin);
        $area = self::area($tenant);
        $name = $registered->name?->value ?? '–';
        $email = $registered->email?->value ?? '–';
        $text = <<<TXT
            {$greeting}

            bei der Feuerwehr Geräteverwaltung ({$area})
            hat sich eine neue Person registriert und ihre E-Mail-Adresse bestätigt:

            Name: {$name}
            E-Mail: {$email}

            Das Konto wartet auf Freischaltung. Bitte prüfen Sie die Registrierung
            in der Benutzerverwaltung und schalten Sie das Konto frei oder lehnen
            Sie es ab:

            {$url}
            TXT;

        return self::message($admin, 'Neue Registrierung wartet auf Freischaltung', $text);
    }

    /**
     * Ein Admin hat das Konto freigeschaltet.
     *
     * @throws MailException
     */
    public static function accountApproved(User $user, Tenant $tenant, string $loginUrl): MailMessage
    {
        $greeting = self::greeting($user);
        $area = self::area($tenant);
        $role = $user->role->label();
        $contacts = self::contactsHint();
        $text = <<<TXT
            {$greeting}

            Ihr Konto bei der Feuerwehr Geräteverwaltung ({$area})
            wurde freigeschaltet. Ihre Rolle: {$role}.

            Sie können sich ab sofort anmelden:

            {$loginUrl}

            {$contacts}
            TXT;

        return self::message($user, 'Ihr Konto wurde freigeschaltet', $text);
    }

    /**
     * Ein Admin hat die Registrierung abgelehnt (vor dem Anonymisieren gesendet).
     *
     * @throws MailException
     */
    public static function registrationRejected(User $user, Tenant $tenant): MailMessage
    {
        $greeting = self::greeting($user);
        $area = self::area($tenant);
        $text = <<<TXT
            {$greeting}

            Ihre Registrierung bei der Feuerwehr Geräteverwaltung
            ({$area}) wurde nicht freigegeben.
            Ihre Angaben wurden gelöscht.

            Bei Fragen wenden Sie sich bitte direkt an Ihre Feuerwehr.
            TXT;

        return self::message($user, 'Ihre Registrierung wurde nicht freigegeben', $text);
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

    /**
     * Hinweis in Mails zu Registrierung und Bestätigung: Absender als Kontakt speichern, damit
     * spätere Mails (Freischaltung, Passwort) nicht im Spam landen.
     */
    private static function contactsHint(): string
    {
        $from = MimeMessage::FROM_ADDRESS;

        return "Damit unsere E-Mails Sie zuverlässig erreichen, fügen Sie bitte den\n"
            . "Absender {$from} zu Ihren Kontakten hinzu.";
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
