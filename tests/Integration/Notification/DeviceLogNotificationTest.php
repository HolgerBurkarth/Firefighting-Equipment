<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Notification;

use FirefightingEquipment\Device\DeviceEventRecorder;
use FirefightingEquipment\Device\DeviceEventRepository;
use FirefightingEquipment\Device\DeviceRepository;
use FirefightingEquipment\Http\AppUrls;
use FirefightingEquipment\Http\ErrorReporter;
use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\Notifier;
use FirefightingEquipment\User\UserRepository;
use FirefightingEquipment\Notification\DeviceLogNotifier;
use FirefightingEquipment\Tests\Integration\Import\ImportApiTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Benachrichtigung „Log“ über die ganze Anwendung: Empfänger (Admins der Stadt und Super-Admins mit
 * eingeschalteter Option, aktiv und nicht gesperrt), nie der Auslöser, Trennung der Städte, eine Mail
 * je Vorgang und Empfänger (Import: eine Sammelmail), Versand erst nach dem Commit, Versandfehler
 * ohne Folgen für den Vorgang.
 *
 * Konten: Admin „Kommandant“ ($admin, Option an), Benutzer „Gerätewart“ ($user), dazu
 * log-admin@example.org (Admin, an), aus@example.org (Admin, aus), gesperrt@example.org (Admin, an,
 * gesperrt), Admin von tuttlingen (an), Super-Admin chef@example.org (an) und still@example.org (aus).
 */
#[CoversClass(DeviceLogNotifier::class)]
#[CoversClass(DeviceEventRecorder::class)]
final class DeviceLogNotificationTest extends ImportApiTestCase
{
    private const string SUBJECT_PREFIX = 'Log Feuerwehr immendingen: ';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo()->exec('UPDATE fe_users SET notify_device_log = 1 WHERE id = ' . $this->adminId);
        $this->account('immendingen', 'log-admin@example.org', ['role' => 'admin', 'notify_device_log' => 1]);
        $this->account('immendingen', 'aus@example.org', ['role' => 'admin']);
        $this->account('immendingen', 'gesperrt@example.org', ['role' => 'admin', 'notify_device_log' => 1, 'locked_at' => self::CREATED_AT]);
        $this->account('tuttlingen', 'fremd@example.org', ['role' => 'admin', 'notify_device_log' => 1]);
        $this->account(null, 'chef@example.org', ['notify_device_log' => 1]);
        $this->account(null, 'still@example.org');
        $this->mailer->sent = [];
    }

    public function testCreateNotifiesAdminsOfTenantAndSuperAdminsButNotTheActor(): void
    {
        $id = $this->createViaApi('MA-01', ['comment' => 'Neu beschafft'])['id'];

        self::assertSame(['log-admin@example.org', 'chef@example.org'], $this->recipients());
        self::assertSame([2], $this->mailer->batches, 'eine Sammlung, also eine SMTP-Verbindung');
        $mail = $this->mailer->sent[0];
        self::assertSame(self::SUBJECT_PREFIX . 'MA-01 angelegt', $mail->subject);
        self::assertStringContainsString("Vorgang: Angelegt\nGerät: MA-01 (Atemschutzmaske)\nWer: Kommandant\nWann: ", $mail->text);
        self::assertStringContainsString("Standort: HLF 20 / G1\nStatus: einsatzbereit\nKommentar: Neu beschafft\n", $mail->text);
        self::assertStringContainsString(
            "\nZum Gerät in der Anwendung:\n\n" . self::BASE_URL . "/firefighting/immendingen/geraete/{$id}/details\n\nSie erhalten",
            $mail->text,
            'Admins: Link auf das Gerätedetail',
        );
        self::assertMatchesRegularExpression('/Wann: \d\d\.\d\d\.\d{4} um \d\d:\d\d Uhr/', $mail->text);
        self::assertNoLink($this->mailer->sent[1]);
        self::assertSame([], $this->log);
    }

    public function testStateChangeByUserNotifiesTheAdmin(): void
    {
        $device = $this->createViaApi('MA-01');
        $this->mailer->sent = [];

        $response = $this->api('POST', 'devices/' . $device['id'] . '/state', [
            'version' => 1, 'location_id' => $this->room, 'status' => 'defective', 'comment' => 'Ventil klemmt',
        ], $this->user);
        self::assertSame(200, $response->statusCode(), $response->body());

        self::assertSame(['admin@example.org', 'log-admin@example.org', 'chef@example.org'], $this->recipients());
        $mail = $this->mailer->sent[0];
        self::assertSame(self::SUBJECT_PREFIX . 'MA-01 umgebucht, Status defekt', $mail->subject);
        self::assertStringContainsString('Vorgang: Umgebucht und Status geändert', $mail->text);
        self::assertStringContainsString('Wer: Gerätewart', $mail->text);
        self::assertStringContainsString("Standort: HLF 20 / G1 → Lager\nStatus: einsatzbereit → defekt\nKommentar: Ventil klemmt", $mail->text);
    }

    public function testSubjectsOfAllActions(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $actions = [
            ['state', ['version' => 1, 'location_id' => $this->room], 'MA-01 umgebucht'],
            ['state', ['version' => 2, 'status' => 'maintenance'], 'MA-01 Status in Wartung'],
            ['inspection-due', ['version' => 3, 'next_inspection_due' => '2027-03-01'], 'MA-01 Prüfdatum geändert'],
            ['fields', ['version' => 4, 'identifier' => 'MA-1', 'model' => 'FPS 7000'], 'MA-1 Stammdaten geändert'],
            ['retire', ['version' => 5], 'MA-1 ausgesondert'],
            ['reinstate', ['version' => 6, 'status' => 'ready', 'identifier' => 'MA-01'], 'MA-01 wieder aufgenommen'],
        ];

        foreach ($actions as [$path, $body, $reason]) {
            $this->mailer->sent = [];
            $response = $this->api('POST', 'devices/' . $id . '/' . $path, $body);
            self::assertSame(200, $response->statusCode(), $response->body());
            self::assertSame(['log-admin@example.org', 'chef@example.org'], $this->recipients(), $path);
            self::assertSame(self::SUBJECT_PREFIX . $reason, $this->mailer->sent[0]->subject);
        }

        $mail = $this->mailer->sent[0];
        self::assertStringContainsString('Vorgang: Wieder aufgenommen', $mail->text);
        self::assertStringContainsString("Kennung: MA-1 → MA-01\nStatus: ausgesondert → einsatzbereit", $mail->text);
    }

    public function testFieldChangesAreListedInOneMail(): void
    {
        $id = $this->createViaApi('MA-01', ['procured_on' => '2020-01-15'])['id'];
        $this->mailer->sent = [];

        $this->api('POST', 'devices/' . $id . '/fields', [
            'version' => 1, 'serial_number' => 'S-1', 'procured_on' => '2021-02-03', 'note' => "Zeile 1\nZeile 2",
        ]);

        self::assertCount(2, $this->mailer->sent);
        $text = $this->mailer->sent[0]->text;
        self::assertStringContainsString("Seriennummer: – → S-1\nBeschaffungsdatum: 15.01.2020 → 03.02.2021\nNotiz: – → Zeile 1 Zeile 2\n", $text);
    }

    public function testNoMailWithoutChangeOrOnError(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->mailer->sent = [];

        // Gleicher Status: kein Ereignis.
        self::assertSame(200, $this->api('POST', 'devices/' . $id . '/state', ['version' => 1, 'status' => 'ready'])->statusCode());
        // Veraltete Version und Regelverstoß: nichts geschrieben.
        self::assertError(409, 'version_conflict', $this->api('POST', 'devices/' . $id . '/state', ['version' => 9, 'status' => 'defective']));
        self::assertError(409, 'rule_violation', $this->api('POST', 'devices/' . $id . '/reinstate', ['version' => 1, 'status' => 'ready']));
        // Nur lesen.
        self::assertSame(200, $this->api('GET', 'devices/' . $id)->statusCode());

        self::assertSame([], $this->mailer->sent);
    }

    public function testOtherTenantsActionsDoNotReachAdminsOfThisTenant(): void
    {
        $this->account('tuttlingen', 'akteur@example.org', ['role' => 'admin']);
        $response = $this->api('POST', 'devices', [
            'identifier' => 'T-1', 'device_type_id' => $this->otherTypeId, 'location_id' => $this->otherLocation,
        ], $this->loginCookie('tuttlingen', 'akteur@example.org'), 'tuttlingen');
        self::assertSame(201, $response->statusCode(), $response->body());

        self::assertSame(['fremd@example.org', 'chef@example.org'], $this->recipients());
        self::assertSame('Log Feuerwehr tuttlingen: T-1 angelegt', $this->mailer->sent[0]->subject);
    }

    public function testImportSendsOneSummaryMailPerRecipient(): void
    {
        $this->createDevice($this->tenantId, $this->typeId, $this->g1, ['identifier' => 'MA-01']);
        $file = self::csv([
            ['MA-01', 'Atemschutzmaske', 'Lager', 'defekt', '', '', '', '', '', ''],
            ['MA-02', 'Atemschutzmaske', 'HLF 20 / G1', '', '', '', '', '', '', ''],
            ['MA-03', 'Atemschutzmaske', 'HLF 20', 'in Wartung', '', '', '', '', '', ''],
        ]);

        $this->import($file, 'Inventur');

        self::assertSame(['log-admin@example.org', 'chef@example.org'], $this->recipients());
        self::assertSame([2], $this->mailer->batches);
        $mail = $this->mailer->sent[0];
        self::assertSame(self::SUBJECT_PREFIX . 'CSV-Import: 2 neu, 1 geändert', $mail->subject);
        self::assertStringContainsString("Vorgang: CSV-Import\nErgebnis: 2 neu, 1 geändert, 0 unverändert, 0 übersprungen\nWer: Kommandant\nWann: 15.06.2026 um 12:00 Uhr\nKommentar: Inventur\n", $mail->text);
        self::assertStringContainsString('Zeile 2: MA-01 geändert: Standort HLF 20 / G1 → Lager, Status einsatzbereit → defekt', $mail->text);
        self::assertStringContainsString('Zeile 3: MA-02 neu: ', $mail->text);
        self::assertStringContainsString('Zeile 4: MA-03 neu: ', $mail->text);
        self::assertStringContainsString(
            "\nZur Geräteliste in der Anwendung:\n\n" . self::BASE_URL . "/firefighting/immendingen/geraete\n\nSie erhalten",
            $mail->text,
        );
        self::assertNoLink($this->mailer->sent[1]);
    }

    public function testImportMailListsAtMostFiftyRows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 60; ++$i) {
            $rows[] = [sprintf('MA-%02d', $i), 'Atemschutzmaske', 'Lager', '', '', '', '', '', '', ''];
        }

        $this->import(self::csv($rows));

        $text = $this->mailer->sent[0]->text;
        self::assertSame(self::SUBJECT_PREFIX . 'CSV-Import: 60 neu, 0 geändert', $this->mailer->sent[0]->subject);
        self::assertStringContainsString('Zeile 51: MA-50 neu', $text);
        self::assertStringNotContainsString('MA-51', $text);
        self::assertStringContainsString("… und 10 weitere\n", $text);
    }

    public function testImportWithoutChangesSendsNothing(): void
    {
        $this->createDevice($this->tenantId, $this->typeId, $this->room, ['identifier' => 'MA-01']);

        $this->import(self::csv([['MA-01', 'Atemschutzmaske', 'Lager', 'einsatzbereit', '', '', '', '', '', '']]));

        self::assertSame([], $this->mailer->sent);
    }

    public function testMailFailureNeverFailsTheAction(): void
    {
        $this->mailer->fail = true;

        $device = $this->createViaApi('MA-01');

        self::assertSame(1, $device['version']);
        self::assertSame(1, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_device_events')->fetchColumn());
        self::assertCount(2, $this->log);
        self::assertMatchesRegularExpression('/^firefighting: mail_failed \(device_log, Konto #\d+\): /', $this->log[0]);
        foreach ($this->log as $line) {
            self::assertStringNotContainsString('@example.org', $line);
            self::assertStringNotContainsString('MA-01', $line);
        }
    }

    public function testRecipientsAreCapped(): void
    {
        for ($i = 1; $i <= DeviceLogNotifier::MAX_RECIPIENTS + 5; ++$i) {
            $this->account(null, sprintf('super%02d@example.org', $i), ['notify_device_log' => 1]);
        }

        $this->createViaApi('MA-01');

        self::assertCount(DeviceLogNotifier::MAX_RECIPIENTS, $this->mailer->sent);
        // Zuerst die Admins der Stadt.
        self::assertSame('log-admin@example.org', $this->mailer->sent[0]->to->value);
        self::assertCount(1, $this->log);
        self::assertStringStartsWith('firefighting: device_log_recipients_capped: RuntimeException: 25 von 32 Empfängern', $this->log[0]);
    }

    public function testNoFurtherMailAfterTimeBudget(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->mailer->sent = [];
        $eventId = (int) $this->pdo()->query('SELECT MAX(id) FROM fe_device_events')->fetchColumn();
        $log = [];
        $errors = new ErrorReporter(static function (string $line) use (&$log): void {
            $log[] = $line;
        });
        $notifier = new DeviceLogNotifier(
            new UserRepository($this->pdo()),
            new DeviceEventRepository($this->pdo()),
            new Notifier($this->mailer, $errors),
            $errors,
            AppUrls::fixed(self::BASE_URL),
            timeBudgetSeconds: 0.0,
        );
        $device = new DeviceRepository($this->pdo())->find($this->tenant('immendingen'), $id) ?? throw new \LogicException('Gerät fehlt.');
        $actor = new UserRepository($this->pdo())->findById($this->adminId) ?? throw new \LogicException('Konto fehlt.');

        self::assertSame(0, $notifier->deviceChanged($this->tenant('immendingen'), $actor, $device, [$eventId]));
        self::assertSame([], $this->mailer->sent);
        self::assertCount(2, $log);
        self::assertStringContainsString('Zeitbudget für den Versand überschritten', $log[0]);

        // Fremde oder unbekannte Ereignis-IDs ergeben keine Mail.
        self::assertSame(0, $notifier->deviceChanged($this->tenant('immendingen'), $actor, $device, [999_999]));
        self::assertSame(0, $notifier->deviceChanged($this->tenant('tuttlingen'), $actor, $device, [$eventId]));
    }

    /**
     * @return list<string> Empfänger der gesendeten Mails in dieser Reihenfolge
     */
    private function recipients(): array
    {
        return array_map(static fn (MailMessage $m): string => $m->to->value, $this->mailer->sent);
    }

    /**
     * Super-Admins sehen keine Geräte: ihre Log-Mail hat keinen Link (die Fußzeile bleibt).
     */
    private static function assertNoLink(MailMessage $mail): void
    {
        self::assertSame('chef@example.org', $mail->to->value);
        self::assertStringNotContainsString('http', $mail->text);
        self::assertStringNotContainsString('in der Anwendung:', $mail->text);
        self::assertMatchesRegularExpression('/\n\nSie erhalten diese E-Mail, weil/', $mail->text);
    }
}
