<?php

declare(strict_types=1);

namespace FirefightingEquipment\Notification;

use FirefightingEquipment\Device\Device;
use FirefightingEquipment\Device\DeviceEventRepository;
use FirefightingEquipment\Device\Import\DeviceImportPlan;
use FirefightingEquipment\Device\Import\ImportResult;
use FirefightingEquipment\Device\Notice\DeviceNoticeRepository;
use FirefightingEquipment\Http\AppUrls;
use FirefightingEquipment\Http\ErrorReporter;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\Mail\Notifier;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\User;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\User\UserRole;

/**
 * Benachrichtigung „Log“: nach jedem Vorgang, der in der Geräte-Historie protokolliert wird, eine
 * Mail je Empfänger (eine je Anfrage, ein CSV-Import ergibt eine Sammelmail). Aufrufen erst nach dem
 * Commit; Fehler erreichen den Aufrufer nie (Notifier, Zweck device_log).
 *
 * Empfänger (UserRepository::listDeviceLogRecipients): aktive, nicht gesperrte Konten mit
 * eingeschalteter Option, Admins nur ihrer Stadt, Super-Admins aller Städte; nie der Auslöser.
 * Begrenzung: höchstens MAX_RECIPIENTS Empfänger (zuerst die Admins der Stadt, dann Super-Admins;
 * Überschreitung als device_log_recipients_capped im Protokoll) und ein Zeitbudget je Anfrage
 * (danach keine weitere Mail). Mehrere Mails gehen über eine SMTP-Verbindung (Mailer::sendMany).
 * Links: Admins direkt auf das Gerätedetail, den Hinweis bzw. (Import) die Geräteliste; Super-Admins
 * ohne Link.
 */
final readonly class DeviceLogNotifier
{
    public const string PURPOSE = 'device_log';
    /** Zweck der Mails zu Hinweisen (Offline-Buchungen) im Protokoll */
    public const string NOTICE_PURPOSE = 'device_notice';
    public const int MAX_RECIPIENTS = 25;
    public const float TIME_BUDGET_SECONDS = 10.0;
    public const int MAX_IMPORT_LINES = 50;

    public function __construct(
        private UserRepository $users,
        private DeviceEventRepository $events,
        private Notifier $notifier,
        private ErrorReporter $errors,
        private AppUrls $urls,
        /** Zeitbudget in Sekunden (andere als TIME_BUDGET_SECONDS nur für Tests) */
        private float $timeBudgetSeconds = self::TIME_BUDGET_SECONDS,
    ) {
    }

    public static function fromContext(RequestContext $context): self
    {
        $pdo = $context->db->get();

        return new self(new UserRepository($pdo), new DeviceEventRepository($pdo), $context->notifier(), $context->errors, $context->urls);
    }

    /**
     * Vorgang an einem Gerät: die Ereignisse einer Anfrage (IDs aus DeviceEventRecorder).
     *
     * @param list<int> $eventIds ohne Ereignis keine Mail
     *
     * @return int Anzahl übergebener Mails
     */
    public function deviceChanged(Tenant $tenant, User $actor, Device $device, array $eventIds): int
    {
        if ($eventIds === []) {
            return 0;
        }
        try {
            $events = $this->events->findForDevice($tenant, $device->id, $eventIds);
        } catch (\Throwable $e) {
            $this->errors->report('mail_failed (' . self::PURPOSE . ')', $e);

            return 0;
        }
        if ($events === []) {
            return 0;
        }
        $url = $this->urls->deviceDetails($tenant, $device->id);

        return $this->send(
            $tenant,
            $actor,
            static fn (User $recipient): MailMessage => MailTemplates::deviceLog($recipient, $tenant, $device, $events, self::linkFor($recipient, $url)),
        );
    }

    /**
     * Hinweis zu einer Offline-Buchung (ersetzt für diese Anfrage die Mail zum Vorgang, damit es nur
     * eine Mail gibt). Der Kommentar steht im Eintrag der Historie.
     *
     * @return int Anzahl übergebener Mails
     */
    public function notice(Tenant $tenant, User $actor, int $noticeId, DeviceNoticeRepository $notices): int
    {
        try {
            $notice = $notices->find($tenant, $noticeId) ?? throw new \LogicException(sprintf('Hinweis #%d fehlt.', $noticeId));
            $event = $notice->eventId === null ? [] : $this->events->findForDevice($tenant, $notice->deviceId, [$notice->eventId]);
        } catch (\Throwable $e) {
            $this->errors->report('mail_failed (' . self::NOTICE_PURPOSE . ')', $e);

            return 0;
        }
        $comment = ($event[0] ?? null)?->comment;
        $url = $this->urls->notice($tenant, $notice->id);

        return $this->send(
            $tenant,
            $actor,
            static fn (User $recipient): MailMessage => MailTemplates::deviceNotice($recipient, $tenant, $notice, $comment, self::linkFor($recipient, $url)),
            self::NOTICE_PURPOSE,
        );
    }

    /**
     * Übernommener CSV-Import; ohne neue oder geänderte Geräte keine Mail.
     *
     * @return int Anzahl übergebener Mails
     */
    public function imported(Tenant $tenant, User $actor, DeviceImportPlan $plan, ?string $comment, \DateTimeImmutable $at): int
    {
        if ($plan->count(ImportResult::New) + $plan->count(ImportResult::Changed) === 0) {
            return 0;
        }
        $url = $this->urls->devices($tenant);

        return $this->send(
            $tenant,
            $actor,
            static fn (User $recipient): MailMessage => MailTemplates::deviceImportLog(
                $recipient,
                $tenant,
                $actor,
                $plan,
                $comment,
                $at,
                self::linkFor($recipient, $url),
                self::MAX_IMPORT_LINES,
            ),
        );
    }

    /**
     * Links führen in die Admin-Konsole der Stadt; Super-Admins sehen dort keine Geräte und
     * erhalten keinen Link (KONZEPT.md, "Admin-Konsole – Entscheidungen", 36).
     */
    private static function linkFor(User $recipient, string $url): ?string
    {
        return $recipient->role === UserRole::Admin ? $url : null;
    }

    /**
     * @param \Closure(User): MailMessage $build
     */
    private function send(Tenant $tenant, User $actor, \Closure $build, string $purpose = self::PURPOSE): int
    {
        $started = microtime(true);
        try {
            $recipients = array_values(array_filter(
                $this->users->listDeviceLogRecipients($tenant),
                static fn (User $user): bool => $user->id !== $actor->id,
            ));
        } catch (\Throwable $e) {
            // Auch eine Störung beim Lesen der Empfänger darf den Vorgang nicht scheitern lassen.
            $this->errors->report('mail_failed (' . $purpose . ')', $e);

            return 0;
        }

        if (count($recipients) > self::MAX_RECIPIENTS) {
            $this->errors->report(
                'device_log_recipients_capped',
                new \RuntimeException(sprintf('%d von %d Empfängern benachrichtigt (Stadt #%d).', self::MAX_RECIPIENTS, count($recipients), $tenant->id)),
            );
            $recipients = array_slice($recipients, 0, self::MAX_RECIPIENTS);
        }
        if ($recipients === []) {
            return 0;
        }

        return $this->notifier->sendMany(
            $purpose,
            array_map(static fn (User $recipient): array => [$recipient->id, static fn (): MailMessage => $build($recipient)], $recipients),
            $started + $this->timeBudgetSeconds,
        );
    }
}
