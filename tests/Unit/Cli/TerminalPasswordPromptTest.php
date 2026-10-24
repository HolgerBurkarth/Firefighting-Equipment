<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Cli;

use FirefightingEquipment\Cli\PasswordPromptException;
use FirefightingEquipment\Cli\TerminalPasswordPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Die eigentliche verdeckte Eingabe (stty) braucht ein echtes Terminal und lässt sich hier nicht
 * automatisch testen; geprüft werden die Fälle, in denen sie verweigert wird.
 */
#[CoversClass(TerminalPasswordPrompt::class)]
#[UsesClass(PasswordPromptException::class)]
final class TerminalPasswordPromptTest extends TestCase
{
    /** @var resource */
    private $in;

    /** @var resource */
    private $err;

    protected function setUp(): void
    {
        $this->in = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');
        $this->err = fopen('php://memory', 'w+') ?: throw new \RuntimeException('php://memory');
        fwrite($this->in, "Unsere Feuerwehr fährt rot\n");
        rewind($this->in);
    }

    protected function tearDown(): void
    {
        fclose($this->in);
        fclose($this->err);
    }

    public function testWindowsIsRefused(): void
    {
        $this->expectException(PasswordPromptException::class);
        $this->expectExceptionMessageIsOrContains(
            'Verdeckte Passworteingabe ist unter Windows nicht möglich. Für Tests und Skripte gibt es --password-stdin',
        );

        new TerminalPasswordPrompt($this->in, $this->err, 'Windows')->ask('Passwort: ');
    }

    public function testNonTerminalInputIsRefusedAndNotRead(): void
    {
        try {
            new TerminalPasswordPrompt($this->in, $this->err, 'Linux')->ask('Passwort: ');
            self::fail('PasswordPromptException erwartet.');
        } catch (PasswordPromptException $e) {
            self::assertStringContainsString('kein Terminal', $e->getMessage());
            self::assertStringContainsString('--password-stdin', $e->getMessage());
        }

        self::assertSame(0, ftell($this->in), 'Nichts gelesen.');
        rewind($this->err);
        self::assertSame('', stream_get_contents($this->err), 'Keine Aufforderung ausgegeben.');
    }
}
