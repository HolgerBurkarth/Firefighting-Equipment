<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Support;

/**
 * Prüft, ob das echte Programm mysqldump im PATH verfügbar ist (Tests werden sonst übersprungen).
 */
final class Mysqldump
{
    private static ?bool $available = null;

    public static function available(): bool
    {
        if (self::$available === null) {
            $process = @proc_open(
                ['mysqldump', '--no-defaults', '--version'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if ($process === false) {
                return self::$available = false;
            }
            $output = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::$available = proc_close($process) === 0 && str_contains($output, 'mysqldump');
        }

        return self::$available;
    }
}
