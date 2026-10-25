<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Invitation\InvitationCodeCreateHandler;
use FirefightingEquipment\Api\V1\Invitation\InvitationCodeListHandler;
use FirefightingEquipment\Api\V1\Invitation\InvitationCodeRevokeHandler;
use FirefightingEquipment\Api\V1\Invitation\InvitationJson;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Invitation\InvitationCodeRepository;
use FirefightingEquipment\Invitation\InvitationCodeValue;
use FirefightingEquipment\User\UserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

#[CoversClass(InvitationCodeCreateHandler::class)]
#[CoversClass(InvitationCodeListHandler::class)]
#[CoversClass(InvitationCodeRevokeHandler::class)]
#[CoversClass(InvitationJson::class)]
final class InvitationCodeEndpointsTest extends AuthTestCase
{
    private int $admin;
    private string $cookie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->account('immendingen', 'admin@example.org', ['role' => 'admin', 'display_name' => 'Wehrführer']);
        $this->cookie = $this->loginCookie('immendingen', 'admin@example.org');
    }

    public function testCreateReturnsPlainCodeOnce(): void
    {
        $response = $this->create(['label' => '  Dienstabend März ', 'valid_days' => 14, 'max_uses' => 25]);

        self::assertSame(201, $response->statusCode(), $response->body());
        $json = self::json($response);
        self::assertMatchesRegularExpression('/^[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}-[A-HJKMNP-Z2-9]{4}$/D', $json['code']);
        $code = $json['invitation_code'];
        self::assertSame('Dienstabend März', $code['label']);
        self::assertSame('active', $code['status']);
        self::assertSame('aktiv', $code['status_label']);
        self::assertSame(0, $code['use_count']);
        self::assertSame(25, $code['max_uses']);
        self::assertNull($code['revoked_at']);
        self::assertSame(['id' => $this->admin, 'label' => 'Wehrführer'], $code['created_by']);
        self::assertSame(14 * 86400, strtotime($code['expires_at']) - strtotime($code['created_at']));
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $code['created_at']);

        $value = InvitationCodeValue::fromInput(strtolower($json['code'])) ?? self::fail('Code nicht lesbar.');
        self::assertSame($code['id'], new InvitationCodeRepository($this->pdo())->findUsableId($this->tenant('immendingen'), $value));

        $list = $this->send('GET', self::url('immendingen', 'invitation-codes'), cookie: $this->cookie);
        self::assertStringNotContainsString(str_replace('-', '', $json['code']), $list->body());
        self::assertStringNotContainsString($json['code'], $list->body());
    }

    public function testEmptyLabelMeansNone(): void
    {
        foreach ([['label' => '   '], ['label' => null], []] as $label) {
            $response = $this->create([...$label, 'valid_days' => 1, 'max_uses' => 1]);

            self::assertSame(201, $response->statusCode(), $response->body());
            self::assertNull(self::json($response)['invitation_code']['label']);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): iterable
    {
        $valid = ['valid_days' => 7, 'max_uses' => 5];

        yield 'ohne Gültigkeit' => [['max_uses' => 5], 'Das Feld "valid_days" muss eine ganze Zahl von 1 bis 90 sein.'];
        yield 'Gültigkeit 0' => [[...$valid, 'valid_days' => 0], 'Das Feld "valid_days" muss eine ganze Zahl von 1 bis 90 sein.'];
        yield 'Gültigkeit 91' => [[...$valid, 'valid_days' => 91], 'Das Feld "valid_days" muss eine ganze Zahl von 1 bis 90 sein.'];
        yield 'Gültigkeit als Text' => [[...$valid, 'valid_days' => '7'], 'Das Feld "valid_days" muss eine ganze Zahl von 1 bis 90 sein.'];
        yield 'ohne Nutzungen' => [['valid_days' => 7], 'Das Feld "max_uses" muss eine ganze Zahl von 1 bis 500 sein.'];
        yield 'Nutzungen 501' => [[...$valid, 'max_uses' => 501], 'Das Feld "max_uses" muss eine ganze Zahl von 1 bis 500 sein.'];
        yield 'Nutzungen als Kommazahl' => [[...$valid, 'max_uses' => 1.5], 'Das Feld "max_uses" muss eine ganze Zahl von 1 bis 500 sein.'];
        yield 'Bezeichnung zu lang' => [[...$valid, 'label' => str_repeat('x', 101)], 'Die Bezeichnung darf höchstens 100 Zeichen lang sein (angegeben: 101).'];
        yield 'Bezeichnung mit Zeilenumbruch' => [[...$valid, 'label' => "a\nb"], 'Die Bezeichnung darf keine Steuerzeichen (z. B. Zeilenumbrüche) enthalten.'];
        yield 'Bezeichnung als Zahl' => [[...$valid, 'label' => 5], 'Das Feld "label" ist kein Text.'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function testCreateRejectsInvalidBody(array $body, string $message): void
    {
        $response = $this->create($body);

        self::assertError(400, 'invalid_request', $response);
        self::assertSame($message, self::json($response)['message']);
        self::assertSame(0, $this->countRows('fe_invitation_codes'));
    }

    public function testListShowsStatusUsesAndCreatorOfOwnTenantOnly(): void
    {
        $tenantId = $this->tenant('immendingen')->id;
        $other = $this->account('immendingen', 'admin2@example.org', ['role' => 'admin']);
        $expired = $this->createInvitationCode($tenantId, $other, ['label' => 'Alt']);
        $exhausted = $this->createInvitationCode($tenantId, $this->admin, ['use_count' => 3, 'max_uses' => 3]);
        $revoked = $this->createInvitationCode($tenantId, $this->admin, ['revoked_at' => '2026-01-05 08:00:00']);
        $foreignAdmin = $this->account('tuttlingen', 'admin@example.org', ['role' => 'admin']);
        $this->createInvitationCode($this->tenant('tuttlingen')->id, $foreignAdmin);
        new UserRepository($this->pdo())->anonymize($other);

        $response = $this->send('GET', self::url('immendingen', 'invitation-codes'), cookie: $this->cookie);

        self::assertSame(200, $response->statusCode(), $response->body());
        $codes = self::json($response)['invitation_codes'];
        self::assertSame([$revoked, $exhausted, $expired], array_column($codes, 'id'));
        self::assertSame(['revoked', 'exhausted', 'expired'], array_column($codes, 'status'));
        self::assertSame(['widerrufen', 'aufgebraucht', 'abgelaufen'], array_column($codes, 'status_label'));
        self::assertSame('2026-01-05T08:00:00Z', $codes[0]['revoked_at']);
        self::assertSame(3, $codes[1]['use_count']);
        self::assertSame('2026-01-01T12:00:00Z', $codes[2]['created_at']);
        self::assertSame('2026-01-31T12:00:00Z', $codes[2]['expires_at']);
        self::assertSame(['id' => $other, 'label' => sprintf('Gelöschter Benutzer #%d', $other)], $codes[2]['created_by']);
        foreach ($codes as $code) {
            self::assertSame(
                ['id', 'label', 'status', 'status_label', 'use_count', 'max_uses', 'expires_at', 'created_at', 'revoked_at', 'created_by'],
                array_keys($code),
                'nie Klartext oder Hash',
            );
        }
    }

    public function testRevoke(): void
    {
        $id = self::json($this->create(['valid_days' => 1, 'max_uses' => 1]))['invitation_code']['id'];

        $first = $this->revoke($id);
        $second = $this->revoke($id);

        self::assertSame(200, $first->statusCode(), $first->body());
        self::assertSame('revoked', self::json($first)['invitation_code']['status']);
        self::assertSame(['id' => $this->admin, 'label' => 'Wehrführer'], self::json($first)['invitation_code']['created_by']);
        self::assertSame(200, $second->statusCode(), 'wiederholbar');
        self::assertSame(self::json($first)['invitation_code']['revoked_at'], self::json($second)['invitation_code']['revoked_at']);
    }

    public function testRevokeOfOtherTenantOrUnknownIs404(): void
    {
        $foreignAdmin = $this->account('tuttlingen', 'admin@example.org', ['role' => 'admin']);
        $foreign = $this->createInvitationCode($this->tenant('tuttlingen')->id, $foreignAdmin);

        self::assertError(404, 'not_found', $this->revoke($foreign));
        self::assertError(404, 'not_found', $this->revoke(999999));
        self::assertSame(0, (int) $this->pdo()->query('SELECT COUNT(*) FROM fe_invitation_codes WHERE revoked_at IS NOT NULL')->fetchColumn());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function create(array $body): JsonResponse
    {
        return $this->send('POST', self::url('immendingen', 'invitation-codes'), $body, $this->cookie);
    }

    private function revoke(int $id): JsonResponse
    {
        return $this->send('POST', self::url('immendingen', 'invitation-codes/' . $id . '/revoke'), [], $this->cookie);
    }
}
