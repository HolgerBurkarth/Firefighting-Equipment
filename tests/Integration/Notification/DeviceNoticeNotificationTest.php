<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Notification;

use FirefightingEquipment\Mail\MailMessage;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\Notification\DeviceLogNotifier;
use FirefightingEquipment\Tests\Integration\Device\DeviceApiTestCase;
use FirefightingEquipment\Tests\Integration\Device\OfflineBookings;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Mails zu Hinweisen (Offline-Buchungen): an die Empfänger der Benachrichtigung „Log“ (Admins der
 * Stadt und Super-Admins mit Option, nie der Auslöser), statt der Mail zum Vorgang, nach dem Commit,
 * Versandfehler ohne Folgen.
 */
#[CoversClass(DeviceLogNotifier::class)]
#[CoversClass(MailTemplates::class)]
final class DeviceNoticeNotificationTest extends DeviceApiTestCase
{
    use OfflineBookings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpOffline();
        $this->pdo()->prepare("UPDATE fe_users SET display_name = 'Kommandant', notify_device_log = 1 WHERE id = ?")->execute([$this->adminId]);
        $this->pdo()->prepare("UPDATE fe_users SET display_name = 'Gerätewart' WHERE id = ?")->execute([$this->userId]);
        $this->account('immendingen', 'log-admin@example.org', ['role' => 'admin', 'notify_device_log' => 1]);
        $this->account('immendingen', 'aus@example.org', ['role' => 'admin']);
        $this->account('tuttlingen', 'fremd@example.org', ['role' => 'admin', 'notify_device_log' => 1]);
        $this->account(null, 'chef@example.org', ['notify_device_log' => 1]);
    }

    public function testNoticeMailReplacesLogMail(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->api('POST', "devices/{$id}/state", ['version' => 1, 'status' => 'defective'], $this->admin);
        $this->setStateChangedAt($id, '-3 hours');
        $this->mailer->sent = [];

        $json = $this->offline($id, [
            'version' => 1, 'location_id' => $this->room, 'status' => 'maintenance', 'comment' => 'Ventil klemmt', 'occurred_at' => $this->at('-1 hour'),
        ]);
        self::assertSame('overwritten', $json['result']['reason']);

        self::assertSame(['admin@example.org', 'log-admin@example.org', 'chef@example.org'], $this->recipients());
        $mail = $this->mailer->sent[0];
        self::assertSame('Log Feuerwehr immendingen: Hinweis – MA-01 Stand überschrieben', $mail->subject);
        self::assertStringContainsString("Hinweis: Anderen Stand überschrieben\nGerät: MA-01 (Atemschutzmaske)\n", $mail->text);
        self::assertStringContainsString("Buchung vom Handy:\nWer: Gerätewart (user@example.org)\nAktionszeit: ", $mail->text);
        self::assertStringContainsString("Gewünscht: Standort Lager, Status in Wartung\nKommentar: Ventil klemmt\n", $mail->text);
        self::assertStringContainsString("Überschriebener Stand:\nWer: Kommandant (admin@example.org)\nAktionszeit: ", $mail->text);
        self::assertStringContainsString("Stand: HLF 20 / G1, defekt\n", $mail->text);
        self::assertStringContainsString(
            "\nBitte prüfen und den Hinweis in der Anwendung erledigen:\n\n"
            . self::BASE_URL . '/firefighting/immendingen/protokoll/' . $json['result']['notice_id'] . "\n\nSie erhalten",
            $mail->text,
            'Admins: Link auf den Hinweis',
        );
        $superAdminMail = $this->mailer->sent[2];
        self::assertStringNotContainsString('http', $superAdminMail->text, 'Super-Admins: kein Link');
        self::assertStringContainsString("\nDen Hinweis prüfen und erledigen die Admins der Stadt in der Anwendung.\n\nSie erhalten", $superAdminMail->text);
        self::assertStringNotContainsString('<', $mail->text);
        self::assertStringNotContainsString('Vorgang:', $mail->text);
        foreach (explode("\n", $mail->text) as $line) {
            self::assertLessThanOrEqual(76, mb_strlen($line), $line);
        }
        self::assertSame([], $this->log);
    }

    public function testSubjectsOfAllKindsAndNoMailToTheActor(): void
    {
        $older = $this->createViaApi('MA-01')['id'];
        $this->api('POST', "devices/{$older}/state", ['version' => 1, 'status' => 'defective'], $this->user);
        $retired = $this->createViaApi('MA-02')['id'];
        $this->api('POST', "devices/{$retired}/retire", ['version' => 1]);
        $locked = $this->createViaApi('MA-03')['id'];
        $keller = $this->createLocation($this->tenantId, ['name' => 'Keller', 'kind' => 'room']);
        $this->api('POST', "locations/{$keller}/lock", []);
        $stale = $this->createViaApi('MA-04')['id'];
        $this->setStateChangedAt($stale, '-30 days');

        $cases = [
            'MA-01 nur Historie' => [$older, ['version' => 1, 'location_id' => $this->room, 'occurred_at' => $this->at('-1 day')]],
            'MA-02 nicht übernommen (ausgesondert)' => [$retired, ['version' => 1, 'status' => 'defective', 'occurred_at' => $this->at('-1 minute')]],
            'MA-03 nicht übernommen (Standort gesperrt)' => [$locked, ['version' => 1, 'location_id' => $keller, 'occurred_at' => $this->at('-1 minute')]],
            'MA-04 alte Buchung nach Rückfrage' => [$stale, ['version' => 1, 'status' => 'defective', 'occurred_at' => $this->at('-10 days'), 'confirmed_stale' => true]],
        ];
        foreach ($cases as $reason => [$id, $body]) {
            $this->mailer->sent = [];
            // Der Admin bucht selbst: Er erhält keine Mail.
            $this->offline($id, $body, $this->admin);
            self::assertSame(['log-admin@example.org', 'chef@example.org'], $this->recipients(), $reason);
            self::assertSame('Log Feuerwehr immendingen: Hinweis – ' . $reason, $this->mailer->sent[0]->subject);
        }
        self::assertStringContainsString("Die Buchung war älter als erlaubt und wurde nach Rückfrage\ngesendet.", $this->mailer->sent[0]->text);
    }

    public function testOfflineBookingWithoutNoticeSendsLogMailAndReplaySendsNothing(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->mailer->sent = [];
        $body = ['version' => 1, 'status' => 'defective', 'occurred_at' => $this->at('-1 minute'), 'client_ref' => $this->ref()];

        $this->offline($id, $body);
        self::assertSame('Log Feuerwehr immendingen: MA-01 Status defekt', $this->mailer->sent[0]->subject);

        $this->mailer->sent = [];
        $this->offline($id, $body);
        self::assertSame([], $this->mailer->sent);
    }

    public function testMailFailureDoesNotAffectTheBooking(): void
    {
        $id = $this->createViaApi('MA-01')['id'];
        $this->api('POST', "devices/{$id}/retire", ['version' => 1]);
        $this->mailer->fail = true;

        $json = $this->offline($id, ['version' => 1, 'status' => 'defective', 'occurred_at' => $this->at('-1 minute')]);

        self::assertSame('not_applied', $json['result']['outcome']);
        self::assertIsInt($json['result']['notice_id']);
        self::assertNotSame([], $this->log);
        foreach ($this->log as $line) {
            self::assertStringContainsString('mail_failed (device_notice', $line);
            self::assertStringNotContainsString('@example.org', $line);
        }
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        return array_map(static fn (MailMessage $mail): string => $mail->to->value, $this->mailer->sent);
    }
}
