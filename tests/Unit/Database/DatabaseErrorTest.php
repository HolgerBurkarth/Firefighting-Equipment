<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Database;

use FirefightingEquipment\Database\DatabaseError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseError::class)]
final class DatabaseErrorTest extends TestCase
{
    public function testUserAndHostAreMasked(): void
    {
        self::assertSame(
            "Access denied for user '***'@'***' to database 'x'",
            DatabaseError::mask("Access denied for user 'geheim'@'db.example' to database 'x'"),
        );
    }

    public function testOtherMessagesAreUnchanged(): void
    {
        self::assertSame("Duplicate entry '1' for key 'PRIMARY'", DatabaseError::mask("Duplicate entry '1' for key 'PRIMARY'"));
    }

    public function testDescribeUsesErrorInfo(): void
    {
        $e = new \PDOException('SQLSTATE[42000]: roh');
        $e->errorInfo = ['42000', 1044, "Access denied for user 'geheim'@'localhost'"];

        self::assertSame(
            "SQLSTATE 42000, Fehler 1044: Access denied for user '***'@'***'",
            DatabaseError::describe($e),
        );
    }

    public function testDescribeWithoutErrorInfoMasksMessage(): void
    {
        $e = new \PDOException("Access denied for user 'geheim'@'localhost'");

        self::assertSame("Access denied for user '***'@'***'", DatabaseError::describe($e));
    }
}
