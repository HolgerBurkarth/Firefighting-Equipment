<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\User;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * fe_user_tokens: nur Hashes, Höchstgültigkeit je Zweck (24 h bzw. 1 h), je Benutzer und Zweck
 * höchstens ein offener Link.
 */
#[CoversNothing]
final class UserTokenSchemaTest extends AuthSchemaTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = $this->createUser($this->createTenant());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function maximalTokens(): iterable
    {
        yield 'E-Mail bestätigen 24 h' => ['verify_email', '2026-01-02 12:00:00'];
        yield 'Passwort setzen 24 h' => ['set_password', '2026-01-02 12:00:00'];
        yield 'Passwort zurücksetzen 1 h' => ['reset_password', '2026-01-01 13:00:00'];
    }

    #[DataProvider('maximalTokens')]
    public function testTokenWithMaximalValidityIsAccepted(string $purpose, string $expiresAt): void
    {
        $this->createToken(['purpose' => $purpose, 'expires_at' => $expiresAt]);

        self::assertSame(1, $this->countRows('fe_user_tokens'));
    }

    public function testTokensWithDifferentPurposesForSameUserAreAccepted(): void
    {
        $this->createToken(['purpose' => 'verify_email']);
        $this->createToken(['purpose' => 'reset_password']);

        self::assertSame(2, $this->countRows('fe_user_tokens'));
    }

    /**
     * @return iterable<string, array{array<string, scalar|null>}>
     */
    public static function invalidTokens(): iterable
    {
        yield 'E-Mail bestätigen über 24 h' => [['purpose' => 'verify_email', 'expires_at' => '2026-01-02 12:00:01']];
        yield 'Passwort setzen über 24 h' => [['purpose' => 'set_password', 'expires_at' => '2026-01-02 12:00:01']];
        yield 'Passwort zurücksetzen über 1 h' => [['purpose' => 'reset_password', 'expires_at' => '2026-01-01 13:00:01']];
        yield 'Ablauf gleich Anlegen' => [['expires_at' => self::CREATED_AT]];
        yield 'unbekannter Zweck' => [['purpose' => 'login']];
        yield 'Zweck groß' => [['purpose' => 'VERIFY_EMAIL']];
        yield 'Hash zu kurz' => [['token_hash' => str_repeat("\x01", 31)]];
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    #[DataProvider('invalidTokens')]
    public function testDatabaseRejectsInvalidToken(array $overrides): void
    {
        $this->assertRejected(self::ER_CONSTRAINT_FAILED, fn () => $this->createToken($overrides));
        self::assertSame(0, $this->countRows('fe_user_tokens'));
    }

    public function testDatabaseRejectsSecondOpenTokenWithSamePurpose(): void
    {
        $this->createToken(['purpose' => 'reset_password']);

        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->createToken(['purpose' => 'reset_password']),
        );
    }

    public function testDatabaseRejectsDuplicateHash(): void
    {
        $this->createToken(['token_hash' => self::hash('token')]);
        $otherUserId = $this->createUser($this->createTenant('tuttlingen'));

        $this->assertRejected(
            self::ER_DUP_ENTRY,
            fn () => $this->createToken(['user_id' => $otherUserId, 'token_hash' => self::hash('token')]),
        );
    }

    public function testDatabaseRejectsUnknownUser(): void
    {
        $this->assertRejected(self::ER_NO_REFERENCED_ROW, fn () => $this->createToken(['user_id' => 999]));
    }

    public function testDatabaseRejectsDeletingUserWithToken(): void
    {
        $this->createToken();

        $this->assertRejected(
            self::ER_ROW_IS_REFERENCED,
            fn () => $this->pdo()->exec('DELETE FROM fe_users WHERE id = ' . $this->userId),
        );
    }

    /**
     * @param array<string, scalar|null> $overrides
     */
    private function createToken(array $overrides = []): int
    {
        return $this->insert('fe_user_tokens', array_merge([
            'user_id' => $this->userId,
            'purpose' => 'verify_email',
            'token_hash' => self::hash('token-' . $this->countRows('fe_user_tokens')),
            'created_at' => self::CREATED_AT,
            'expires_at' => '2026-01-01 13:00:00',
        ], $overrides));
    }
}
