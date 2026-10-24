<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\User\Password;

use FirefightingEquipment\User\Password\PasswordHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    public function testHashIsArgon2idAndVerifies(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('Unsere Feuerwehr fährt rot');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify('Unsere Feuerwehr fährt rot', $hash));
        self::assertFalse($hasher->verify('unsere feuerwehr fährt rot', $hash));
        self::assertFalse($hasher->needsRehash($hash));
    }

    public function testSamePasswordGivesDifferentHashes(): void
    {
        $hasher = new PasswordHasher();

        self::assertNotSame($hasher->hash('Unsere Feuerwehr fährt rot'), $hasher->hash('Unsere Feuerwehr fährt rot'));
    }

    public function testNeedsRehashForOtherParametersOrAlgorithm(): void
    {
        $hasher = new PasswordHasher();
        $weak = password_hash('Unsere Feuerwehr fährt rot', PASSWORD_ARGON2ID, ['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);
        $bcrypt = password_hash('Unsere Feuerwehr fährt rot', PASSWORD_BCRYPT);

        self::assertTrue($hasher->verify('Unsere Feuerwehr fährt rot', $weak));
        self::assertTrue($hasher->needsRehash($weak));
        self::assertTrue($hasher->needsRehash($bcrypt));
    }
}
