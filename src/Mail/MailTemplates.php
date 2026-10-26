<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

use FirefightingEquipment\Device\Device;
use FirefightingEquipment\Device\DeviceEvent;
use FirefightingEquipment\Device\DeviceEventType;
use FirefightingEquipment\Device\DeviceField;
use FirefightingEquipment\Device\DeviceStatus;
use FirefightingEquipment\Device\Import\DeviceImportPlan;
use FirefightingEquipment\Device\Import\ImportChange;
use FirefightingEquipment\Device\Import\ImportResult;
use FirefightingEquipment\Device\Import\ImportRowPlan;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Time\GermanFormat;
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

    /** Längere Werte (z. B. Notizen) werden in der Benachrichtigung „Log“ gekürzt. */
    private const int LOG_VALUE_LENGTH = 200;

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
     * Der Super-Admin lädt einen Admin ein (neue Stadt oder weiterer Admin, auch erneut gesendet).
     * Das Konto hat noch keinen Namen; den gibt der Admin beim Annehmen an.
     *
     * @throws MailException
     */
    public static function adminInvitation(User $user, Tenant $tenant, string $link): MailMessage
    {
        $greeting = self::greeting($user);
        $area = self::area($tenant);
        $contacts = self::contactsHint();
        $text = <<<TXT
            {$greeting}

            Sie wurden als Admin für die Feuerwehr Geräteverwaltung eingeladen:

            {$area}

            Als Admin verwalten Sie Geräte, Standorte und die Benutzer Ihrer
            Feuerwehr. Über diesen Link legen Sie Ihren Namen und Ihr Passwort fest:

            {$link}

            Der Link ist 24 Stunden gültig und kann nur einmal verwendet werden.
            Ist er abgelaufen, bitten Sie die Person, die Sie eingeladen hat, die
            Einladung erneut zu senden. Falls Sie keine Einladung erwartet haben,
            ignorieren Sie diese E-Mail.

            {$contacts}
            TXT;

        return self::message($user, 'Einladung als Admin – ' . $area, $text);
    }

    /**
     * An einen Super-Admin: Ein eingeladener Admin hat die Einladung angenommen. Name und E-Mail
     * stehen in eigenen Zeilen (können länger als 76 Zeichen sein).
     *
     * @param string $url Startseite der Verwaltung (service)
     *
     * @throws MailException
     */
    public static function invitationAccepted(
        User $superAdmin,
        Tenant $tenant,
        User $admin,
        string $url,
        ?\DateTimeImmutable $at = null,
    ): MailMessage {
        $local = ($at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(self::TIME_ZONE));
        $when = $local->format('d.m.Y') . ' um ' . $local->format('H:i') . ' Uhr';
        $greeting = self::greeting($superAdmin);
        $area = self::area($tenant);
        $slug = $tenant->slug->value;
        $name = $admin->name?->value ?? '–';
        $email = $admin->email?->value ?? '–';
        $text = <<<TXT
            {$greeting}

            eine Einladung als Admin wurde am {$when} angenommen.

            Stadt: {$area} ({$slug})
            Name: {$name}
            E-Mail: {$email}

            Das Konto ist aktiv. Übersicht der Städte:

            {$url}
            TXT;

        return self::message($superAdmin, 'Einladung angenommen – ' . $area, $text);
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
     * Benachrichtigung „Log“: ein Vorgang an einem Gerät (alle Ereignisse einer Anfrage). Betreff
     * „Log <Stadt>: <Kennung> <Grund>“. Werte stehen in eigenen Zeilen (können länger als 76 Zeichen
     * sein; Zeilenumbrüche in Notizen werden zu Leerzeichen, lange Werte gekürzt).
     *
     * @param list<DeviceEvent> $events in der Reihenfolge des Schreibens, mindestens eines
     * @param string            $url    Startseite der Stadt
     *
     * @throws MailException
     */
    public static function deviceLog(User $recipient, Tenant $tenant, Device $device, array $events, string $url): MailMessage
    {
        if ($events === []) {
            throw new \InvalidArgumentException('Mindestens ein Ereignis erwartet.');
        }
        $first = $events[0];
        $greeting = self::greeting($recipient);
        $area = self::area($tenant);
        $lines = [
            'Vorgang: ' . self::deviceLogAction($events),
            'Gerät: ' . $device->identifier->value . ' (' . $device->deviceTypeName . ')',
            'Wer: ' . $first->userName,
            'Wann: ' . self::when($first->createdAt),
        ];
        foreach ($events as $event) {
            array_push($lines, ...self::deviceLogDetails($event));
        }
        if ($first->comment !== null) {
            $lines[] = 'Kommentar: ' . self::logValue($first->comment);
        }
        $details = implode("\n", $lines);
        $footer = self::deviceLogFooter();
        $text = <<<TXT
            {$greeting}

            bei der Feuerwehr Geräteverwaltung ({$area})
            wurde ein Vorgang protokolliert:

            {$details}

            Zur Anwendung:

            {$url}

            {$footer}
            TXT;

        $reason = $device->identifier->value . ' ' . self::deviceLogReason($events);

        return self::message($recipient, self::logSubject($tenant, $reason), $text);
    }

    /**
     * Benachrichtigung „Log“ für einen CSV-Import: eine Mail mit Zusammenfassung und höchstens
     * $maxLines Zeilen mit Einzelheiten (neue und geänderte Geräte), danach „… und N weitere“.
     *
     * @param string $url Startseite der Stadt
     *
     * @throws MailException
     */
    public static function deviceImportLog(
        User $recipient,
        Tenant $tenant,
        User $actor,
        DeviceImportPlan $plan,
        ?string $comment,
        \DateTimeImmutable $at,
        string $url,
        int $maxLines = 50,
    ): MailMessage {
        $new = $plan->count(ImportResult::New);
        $changed = $plan->count(ImportResult::Changed);
        $greeting = self::greeting($recipient);
        $area = self::area($tenant);
        $lines = [
            'Vorgang: CSV-Import',
            sprintf('Ergebnis: %d neu, %d geändert, %d unverändert, %d übersprungen', $new, $changed, $plan->count(ImportResult::Unchanged), $plan->count(ImportResult::Skipped)),
            'Wer: ' . $actor->label(),
            'Wann: ' . self::when($at),
        ];
        if ($comment !== null) {
            $lines[] = 'Kommentar: ' . self::logValue($comment);
        }
        $details = implode("\n", $lines);

        $rows = array_values(array_filter(
            $plan->rows,
            static fn (ImportRowPlan $row): bool => $row->result === ImportResult::New || $row->result === ImportResult::Changed,
        ));
        $rowLines = [];
        foreach (array_slice($rows, 0, max(0, $maxLines)) as $row) {
            $changes = implode(', ', array_map(
                static fn (ImportChange $c): string => $row->result === ImportResult::New
                    ? $c->column->label() . ' ' . self::logValue($c->new ?? '–')
                    : $c->column->label() . ' ' . self::logValue($c->old ?? '–') . ' → ' . self::logValue($c->new ?? '–'),
                $row->changes,
            ));
            $rowLines[] = sprintf('Zeile %d: %s %s: %s', $row->line, $row->identifier, $row->result === ImportResult::New ? 'neu' : 'geändert', $changes);
        }
        if (count($rows) > count($rowLines)) {
            $rowLines[] = sprintf('… und %d weitere', count($rows) - count($rowLines));
        }
        $rowText = implode("\n", $rowLines);
        $footer = self::deviceLogFooter();
        $text = <<<TXT
            {$greeting}

            bei der Feuerwehr Geräteverwaltung ({$area})
            wurde ein CSV-Import übernommen:

            {$details}

            Einzelheiten:

            {$rowText}

            Zur Anwendung:

            {$url}

            {$footer}
            TXT;

        return self::message($recipient, self::logSubject($tenant, sprintf('CSV-Import: %d neu, %d geändert', $new, $changed)), $text);
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

    /**
     * Bezeichnung des ganzen Vorgangs: der Zustandswechsel, falls vorhanden (Wiederaufnehmen schreibt
     * zusätzlich Stammdaten), sonst die Art des ersten Ereignisses.
     *
     * @param non-empty-list<DeviceEvent> $events
     */
    private static function deviceLogAction(array $events): string
    {
        foreach ($events as $event) {
            if ($event->type === DeviceEventType::StateChanged || $event->type === DeviceEventType::Created) {
                return $event->label();
            }
        }

        return $events[0]->label();
    }

    /**
     * Kurzer Grund für den Betreff, z. B. „umgebucht, Status defekt“.
     *
     * @param non-empty-list<DeviceEvent> $events
     */
    private static function deviceLogReason(array $events): string
    {
        foreach ($events as $event) {
            if ($event->type === DeviceEventType::Created) {
                return 'angelegt';
            }
            if ($event->type === DeviceEventType::StateChanged) {
                if ($event->toStatus === DeviceStatus::Retired) {
                    return 'ausgesondert';
                }
                if ($event->fromStatus === DeviceStatus::Retired) {
                    return 'wieder aufgenommen';
                }
                $parts = [];
                if ($event->toLocation !== null) {
                    $parts[] = 'umgebucht';
                }
                if ($event->toStatus !== null) {
                    $parts[] = 'Status ' . $event->toStatus->label();
                }

                return implode(', ', $parts);
            }
        }

        return $events[0]->type === DeviceEventType::DueChanged ? 'Prüfdatum geändert' : 'Stammdaten geändert';
    }

    /**
     * @return list<string>
     */
    private static function deviceLogDetails(DeviceEvent $event): array
    {
        $lines = [];
        if ($event->type === DeviceEventType::Created || $event->type === DeviceEventType::Imported) {
            $lines[] = 'Standort: ' . ($event->toLocation?->path() ?? '–');
            $lines[] = 'Status: ' . ($event->toStatus?->label() ?? '–');
        } elseif ($event->type === DeviceEventType::StateChanged) {
            if ($event->toLocation !== null) {
                $lines[] = 'Standort: ' . ($event->fromLocation?->path() ?? '–') . ' → ' . $event->toLocation->path();
            }
            if ($event->toStatus !== null) {
                $lines[] = 'Status: ' . ($event->fromStatus?->label() ?? '–') . ' → ' . $event->toStatus->label();
            }
        } elseif ($event->type === DeviceEventType::DueChanged) {
            $lines[] = 'Nächste Prüfung: ' . self::logDate($event->fromDue?->value) . ' → ' . self::logDate($event->toDue?->value);
        } elseif ($event->field !== null) {
            $format = $event->field === DeviceField::ProcuredOn
                ? self::logDate(...)
                : static fn (?string $v): string => $v === null ? '–' : self::logValue($v);
            $lines[] = $event->field->label() . ': ' . $format($event->oldValue) . ' → ' . $format($event->newValue);
        }

        return $lines;
    }

    private static function deviceLogFooter(): string
    {
        return "Sie erhalten diese E-Mail, weil die Benachrichtigung „Log“ für Ihr\n"
            . 'Konto eingeschaltet ist. Abschalten können Sie sie in Ihrem Konto.';
    }

    /**
     * Betreff „Log <Stadt>: <Grund>“, höchstens MailMessage::MAX_SUBJECT_LENGTH Zeichen.
     */
    private static function logSubject(Tenant $tenant, string $reason): string
    {
        $subject = 'Log ' . $tenant->name->value . ': ' . $reason;

        return mb_strlen($subject) > MailMessage::MAX_SUBJECT_LENGTH
            ? mb_substr($subject, 0, MailMessage::MAX_SUBJECT_LENGTH - 1) . '…'
            : $subject;
    }

    /**
     * Wert einer Zeile: Zeilenumbrüche und mehrfache Leerzeichen als ein Leerzeichen, höchstens
     * LOG_VALUE_LENGTH Zeichen.
     */
    private static function logValue(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_strlen($value) > self::LOG_VALUE_LENGTH ? mb_substr($value, 0, self::LOG_VALUE_LENGTH - 1) . '…' : $value;
    }

    private static function logDate(?string $date): string
    {
        return $date === null ? '–' : GermanFormat::date($date);
    }

    private static function when(\DateTimeImmutable $at): string
    {
        $local = $at->setTimezone(new \DateTimeZone(self::TIME_ZONE));

        return $local->format('d.m.Y') . ' um ' . $local->format('H:i') . ' Uhr';
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
