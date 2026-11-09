<?php

declare(strict_types=1);

namespace FirefightingEquipment\Notification;

use FirefightingEquipment\Device\Device;
use FirefightingEquipment\Device\DeviceEvent;
use FirefightingEquipment\Device\DeviceEventRepository;
use FirefightingEquipment\Device\EventChannel;
use FirefightingEquipment\Device\Import\DeviceImportPlan;
use FirefightingEquipment\Device\Import\ImportResult;
use FirefightingEquipment\Device\Notice\DeviceNoticeRepository;
use FirefightingEquipment\Http\AppUrls;
use FirefightingEquipment\Http\ErrorReporter;
use FirefightingEquipment\Http\RequestContext;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\Mail\Notifier;
use FirefightingEquipment\Permission\DeviceVisibility;
use FirefightingEquipment\Permission\PermissionRepository;
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
 * eingeschalteter Option, Konten der Stadt mit mail.log in ihrer Rolle, Super-Admins aller Städte;
 * nie der Auslöser. Datenfilter je Empfänger (Plan Phase 1, Abschnitt 4; Super-Admins sehen alles):
 * eine Mail zu einem Gerät nur, wenn es für ihn vorher oder nachher sichtbar ist, eine Sammelmail nur
 * mit seinen Zeilen (ohne sichtbare Zeilen keine Mail), ein Hinweis nur zu einem sichtbaren Gerät;
 * Standorte außerhalb erscheinen als „anderer Standort“.
 * Begrenzung: höchstens MAX_RECIPIENTS Empfänger (zuerst die Konten der Stadt, dann Super-Admins;
 * Überschreitung als device_log_recipients_capped im Protokoll) und ein Zeitbudget je Anfrage
 * (danach keine weitere Mail). Mehrere Mails gehen über eine SMTP-Verbindung (Mailer::sendMany).
 * Links: Konten der Stadt direkt auf das Gerätedetail, den Hinweis bzw. (Import) die Geräteliste;
 * Super-Admins ohne Link.
 */
final readonly class DeviceLogNotifier
{
    public const string PURPOSE = 'device_log';
    /** Zweck der Mails zu Hinweisen (Offline-Buchungen) im Protokoll */
    public const string NOTICE_PURPOSE = 'device_notice';
    public const int MAX_RECIPIENTS = 25;
    public const float TIME_BUDGET_SECONDS = 10.0;
    /** Zeitbudget, wenn ein Werkzeug des Claude-Zugangs die Änderung auslöst (docs/PLAN-MCP.md, Abschnitt 6) */
    public const float CLAUDE_TIME_BUDGET_SECONDS = 5.0;
    public const int MAX_IMPORT_LINES = 50;

    public function __construct(
        private UserRepository $users,
        /** ohne Datenfilter: maskiert wird je Empfänger */
        private DeviceEventRepository $events,
        private Notifier $notifier,
        private ErrorReporter $errors,
        private AppUrls $urls,
        private PermissionRepository $permissions,
        /** ohne Datenfilter: maskiert wird je Empfänger */
        private DeviceNoticeRepository $notices,
        /** Zeitbudget in Sekunden (andere als TIME_BUDGET_SECONDS nur für Tests und über Claude) */
        private float $timeBudgetSeconds = self::TIME_BUDGET_SECONDS,
        /** Weg der Änderung („Wer: … (über Claude)“ beim CSV-Import) */
        private EventChannel $channel = EventChannel::App,
        /** Mail-Auswahl der Empfänger (P2.10); null = alle Themen für alle */
        private ?MailPreferenceRepository $preferences = null,
    ) {
    }

    public static function fromContext(RequestContext $context): self
    {
        $pdo = $context->db->get();

        return new self(
            new UserRepository($pdo),
            new DeviceEventRepository($pdo, DeviceVisibility::all()),
            $context->notifier(),
            $context->errors,
            $context->urls,
            new PermissionRepository($pdo),
            new DeviceNoticeRepository($pdo, DeviceVisibility::all()),
            $context->channel() === EventChannel::Claude ? self::CLAUDE_TIME_BUDGET_SECONDS : self::TIME_BUDGET_SECONDS,
            $context->channel(),
            new MailPreferenceRepository($pdo),
        );
    }

    /**
     * Vorgang an einem Gerät: die Ereignisse einer Anfrage (IDs aus DeviceEventRecorder).
     *
     * @param list<int> $eventIds ohne Ereignis keine Mail
     * @param Device|null $before Stand vor der Änderung (DeviceEventRecorder::before), für den
     *                            Datenfilter der Empfänger
     *
     * @return int Anzahl übergebener Mails
     */
    public function deviceChanged(Tenant $tenant, User $actor, Device $device, array $eventIds, ?Device $before = null): int
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
            static fn (DeviceVisibility $v): bool => self::sees($v, $device, $before),
            static fn (User $recipient, DeviceVisibility $v): MailMessage => MailTemplates::deviceLog(
                $recipient,
                $tenant,
                $device,
                self::masked($events, $v),
                self::linkFor($recipient, $url),
            ),
            topics: MailTopic::forEvents($events),
        );
    }

    /**
     * Sammelaktion der Geräteliste (POST devices/bulk/…): EINE Mail je Empfänger mit allen geänderten
     * Geräten, die er sieht; sieht er nur eines, die Mail wie deviceChanged().
     *
     * @param list<array{0: Device, 1: list<int>, 2?: ?Device}> $changes je geändertem Gerät die IDs
     *                                                            seiner Ereignisse und der Stand vorher
     *
     * @return int Anzahl übergebener Mails
     */
    public function bulkChanged(Tenant $tenant, User $actor, array $changes): int
    {
        $changes = array_values(array_filter($changes, static fn (array $change): bool => $change[1] !== []));
        if ($changes === []) {
            return 0;
        }
        if (count($changes) === 1) {
            return $this->deviceChanged($tenant, $actor, $changes[0][0], $changes[0][1], $changes[0][2] ?? null);
        }
        try {
            /** @var list<array{Device, non-empty-list<DeviceEvent>, ?Device}> $entries */
            $entries = [];
            foreach ($changes as $change) {
                $events = $this->events->findForDevice($tenant, $change[0]->id, $change[1]);
                if ($events !== []) {
                    $entries[] = [$change[0], $events, $change[2] ?? null];
                }
            }
        } catch (\Throwable $e) {
            $this->errors->report('mail_failed (' . self::PURPOSE . ')', $e);

            return 0;
        }
        if ($entries === []) {
            return 0;
        }
        $visibleEntries = static fn (DeviceVisibility $v): array => array_values(array_map(
            static fn (array $entry): array => [$entry[0], self::masked($entry[1], $v)],
            array_filter($entries, static fn (array $entry): bool => self::sees($v, $entry[0], $entry[2])),
        ));
        $listUrl = $this->urls->devices($tenant);

        return $this->send(
            $tenant,
            $actor,
            static fn (DeviceVisibility $v): bool => $visibleEntries($v) !== [],
            function (User $recipient, DeviceVisibility $v) use ($tenant, $visibleEntries, $listUrl): MailMessage {
                $visible = $visibleEntries($v);
                if (count($visible) === 1) {
                    [$device, $events] = $visible[0];

                    return MailTemplates::deviceLog($recipient, $tenant, $device, $events, self::linkFor($recipient, $this->urls->deviceDetails($tenant, $device->id)));
                }

                return MailTemplates::deviceBulkLog($recipient, $tenant, $visible, self::linkFor($recipient, $listUrl));
            },
            topics: MailTopic::forEvents(array_merge(...array_map(static fn (array $entry): array => $entry[1], $entries))),
        );
    }

    /**
     * Hinweis zu einer Offline-Buchung (ersetzt für diese Anfrage die Mail zum Vorgang, damit es nur
     * eine Mail gibt). Der Kommentar steht im Eintrag der Historie.
     *
     * @return int Anzahl übergebener Mails
     */
    public function notice(Tenant $tenant, User $actor, int $noticeId): int
    {
        try {
            $notice = $this->notices->find($tenant, $noticeId) ?? throw new \LogicException(sprintf('Hinweis #%d fehlt.', $noticeId));
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
            static fn (DeviceVisibility $v): bool => $notice->visibleFor($v),
            static fn (User $recipient, DeviceVisibility $v): MailMessage => MailTemplates::deviceNotice(
                $recipient,
                $tenant,
                $notice->maskedFor($v),
                $comment,
                self::linkFor($recipient, $url),
            ),
            self::NOTICE_PURPOSE,
            [MailTopic::Notices],
        );
    }

    /**
     * Übernommener CSV-Import; ohne neue oder geänderte Geräte (die der Empfänger sieht) keine Mail.
     *
     * @return int Anzahl übergebener Mails
     */
    public function imported(Tenant $tenant, User $actor, DeviceImportPlan $plan, ?string $comment, \DateTimeImmutable $at): int
    {
        if ($plan->count(ImportResult::New) + $plan->count(ImportResult::Changed) === 0) {
            return 0;
        }
        $url = $this->urls->devices($tenant);
        $channel = $this->channel;
        $changesFor = static function (DeviceVisibility $v) use ($plan): int {
            $visible = $plan->visibleFor($v);

            return $visible->count(ImportResult::New) + $visible->count(ImportResult::Changed);
        };

        return $this->send(
            $tenant,
            $actor,
            static fn (DeviceVisibility $v): bool => $changesFor($v) > 0,
            static fn (User $recipient, DeviceVisibility $v): MailMessage => MailTemplates::deviceImportLog(
                $recipient,
                $tenant,
                $actor,
                $plan->visibleFor($v),
                $comment,
                $at,
                self::linkFor($recipient, $url),
                self::MAX_IMPORT_LINES,
                $channel,
            ),
            topics: [MailTopic::MasterData],
        );
    }

    /** Sichtbar für den Empfänger: vorher oder nachher. */
    private static function sees(DeviceVisibility $visibility, Device $after, ?Device $before): bool
    {
        return $visibility->allowsDevice($after) || ($before !== null && $visibility->allowsDevice($before));
    }

    /**
     * @param non-empty-list<DeviceEvent> $events
     *
     * @return non-empty-list<DeviceEvent>
     */
    private static function masked(array $events, DeviceVisibility $visibility): array
    {
        return array_map(static fn (DeviceEvent $e): DeviceEvent => $e->maskedFor($visibility), $events);
    }

    /**
     * Links führen in die Admin-Konsole der Stadt; Super-Admins sehen dort keine Geräte und
     * erhalten keinen Link (KONZEPT.md, "Admin-Konsole – Entscheidungen", 36).
     */
    private static function linkFor(User $recipient, string $url): ?string
    {
        return $recipient->role === UserRole::SuperAdmin ? null : $url;
    }

    /**
     * @param \Closure(DeviceVisibility): bool         $sees  ob der Empfänger etwas sieht
     * @param \Closure(User, DeviceVisibility): MailMessage $build
     * @param list<MailTopic> $topics Themen der Mail: wer eine Auswahl getroffen hat, bekommt sie nur zu einem davon (leer = immer)
     */
    private function send(Tenant $tenant, User $actor, \Closure $sees, \Closure $build, string $purpose = self::PURPOSE, array $topics = []): int
    {
        $started = microtime(true);
        try {
            $recipients = [];
            $candidates = array_values(array_filter(
                $this->users->listDeviceLogRecipients($tenant),
                static fn (User $user): bool => $user->id !== $actor->id,
            ));
            $chosen = $topics === [] || $this->preferences === null
                ? []
                : $this->preferences->topicsForUsers(array_map(static fn (User $user): int => $user->id, $candidates));
            foreach ($candidates as $user) {
                if (isset($chosen[$user->id]) && !array_any($topics, static fn (MailTopic $topic): bool => in_array($topic, $chosen[$user->id], true))) {
                    continue;
                }
                $visibility = $user->tenantId === null
                    ? DeviceVisibility::all()
                    : $this->permissions->forTenantUser($user->tenantId, $user->id)->visibility();
                if ($sees($visibility)) {
                    $recipients[] = [$user, $visibility];
                }
            }
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
            array_map(
                static fn (array $r): array => [$r[0]->id, static fn (): MailMessage => $build($r[0], $r[1])],
                $recipients,
            ),
            $started + $this->timeBudgetSeconds,
        );
    }
}
