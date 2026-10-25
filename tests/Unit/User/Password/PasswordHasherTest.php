<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\User\Password;

use FirefightingEquipment\Tests\Support\TestPasswordHasher;
use FirefightingEquipment\User\Password\PasswordHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Bewusst mit den Standardparametern (new PasswordHasher()), wie in Produktion; nur die Tests zu
 * anderen Parametern verwenden TestPasswordHasher.
 */
#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    public function testHashIsArgon2idWithDefaultParametersAndVerifies(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('Unsere Feuerwehr fährt rot');

        self::assertSame('argon2id', password_get_info($hash)['algoName']);
        self::assertSame([
            'memory_cost' => PASSWORD_ARGON2_DEFAULT_MEMORY_COST,
            'time_cost' => PASSWORD_ARGON2_DEFAULT_TIME_COST,
            'threads' => PASSWORD_ARGON2_DEFAULT_THREADS,
        ], password_get_info($hash)['options']);
        self::assertTrue($hasher->verify('Unsere Feuerwehr fährt rot', $hash));
        self::assertFalse($hasher->verify('unsere feuerwehr fährt rot', $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testSamePasswordGivesDifferentHashes(): void
    {
        $hasher = TestPasswordHasher::fast();

        self::assertNotSame($hasher->hash('Unsere Feuerwehr fährt rot'), $hasher->hash('Unsere Feuerwehr fährt rot'));
    }

    public function testNeedsRehashForOtherParametersOrAlgorithm(): void
    {
        $hasher = new PasswordHasher();
        $weak = TestPasswordHasher::fast()->hash('Unsere Feuerwehr fährt rot');
        $bcrypt = password_hash('Unsere Feuerwehr fährt rot', PASSWORD_BCRYPT);

        self::assertTrue($hasher->verify('Unsere Feuerwehr fährt rot', $weak));
        self::assertTrue($hasher->needsRehash($weak));
        self::assertTrue($hasher->needsRehash($bcrypt));
        self::assertFalse(TestPasswordHasher::fast()->needsRehash($weak));
    }

    public function testDefaultDummyHashMatchesDefaultParameters(): void
    {
        $hasher = new PasswordHasher();

        self::assertFalse($hasher->needsRehash(PasswordHasher::DEFAULT_DUMMY_HASH));
        self::assertSame(PasswordHasher::DEFAULT_DUMMY_HASH, $hasher->dummyHash());
        self::assertFalse($hasher->verify('', PasswordHasher::DEFAULT_DUMMY_HASH));
        self::assertFalse($hasher->verify('Korrekt-Pferd-Batterie-42', PasswordHasher::DEFAULT_DUMMY_HASH));
    }

    public function testDummyHashFollowsOtherParameters(): void
    {
        $hasher = new PasswordHasher(TestPasswordHasher::MEMORY_COST, TestPasswordHasher::TIME_COST, TestPasswordHasher::THREADS);
        $dummy = $hasher->dummyHash();

        self::assertNotSame(PasswordHasher::DEFAULT_DUMMY_HASH, $dummy);
        self::assertSame('argon2id', password_get_info($dummy)['algoName']);
        self::assertFalse($hasher->needsRehash($dummy));
        self::assertSame($dummy, $hasher->dummyHash());
        self::assertFalse($hasher->verify('', $dummy));
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'time_cost 0' => [1024, 0, 1];
        yield 'threads 0' => [1024, 1, 0];
        yield 'zu wenig Speicher je Thread' => [15, 1, 2];
        yield 'Speicher negativ' => [-1, 1, 1];
    }

    #[DataProvider('invalidParameters')]
    public function testRejectsInvalidParameters(int $memoryCost, int $timeCost, int $threads): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PasswordHasher($memoryCost, $timeCost, $threads);
    }
}
