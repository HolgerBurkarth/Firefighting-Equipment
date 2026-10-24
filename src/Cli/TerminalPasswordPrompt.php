<?php

declare(strict_types=1);

namespace FirefightingEquipment\Cli;

/**
 * Verdeckte Eingabe im Terminal per stty (Linux/macOS). stty wirkt auf die Standardeingabe des
 * Prozesses, daher muss $in STDIN sein. Unter Windows und ohne Terminal gibt es keine verdeckte
 * Eingabe; dann bleibt nur --password-stdin (für Tests und Skripte).
 */
final readonly class TerminalPasswordPrompt implements PasswordPrompt
{
    private const string HINT = 'Für Tests und Skripte gibt es --password-stdin (siehe README).';

    /**
     * @param resource $in
     * @param resource $err Ausgabe der Aufforderung
     */
    public function __construct(
        private mixed $in,
        private mixed $err,
        private string $osFamily = PHP_OS_FAMILY,
    ) {
    }

    public function ask(string $label): string
    {
        if ($this->osFamily === 'Windows') {
            throw new PasswordPromptException('Verdeckte Passworteingabe ist unter Windows nicht möglich. ' . self::HINT);
        }
        if (!stream_isatty($this->in)) {
            throw new PasswordPromptException(
                'Die Standardeingabe ist kein Terminal, verdeckte Passworteingabe nicht möglich. ' . self::HINT,
            );
        }

        $state = self::stty('-g');
        if ($state === null) {
            throw new PasswordPromptException('stty ist nicht verfügbar, verdeckte Passworteingabe nicht möglich. ' . self::HINT);
        }

        $restore = static function () use ($state): void {
            self::stty(escapeshellarg($state));
        };
        $previousHandler = self::trapInterrupt($restore);

        fwrite($this->err, $label);
        self::stty('-echo');
        try {
            $line = fgets($this->in);
        } finally {
            $restore();
            self::releaseInterrupt($previousHandler);
            // Die Eingabetaste wurde nicht angezeigt.
            fwrite($this->err, PHP_EOL);
        }

        if ($line === false) {
            throw new PasswordPromptException('Keine Eingabe erhalten.');
        }

        return (string) preg_replace('/\r?\n\z/', '', $line);
    }

    /**
     * @return string|null Ausgabe von stty, null bei Fehler
     */
    private static function stty(string $arguments): ?string
    {
        $output = [];
        $exitCode = 1;
        exec('stty ' . $arguments . ' 2>/dev/null', $output, $exitCode);

        return $exitCode === 0 ? trim(implode("\n", $output)) : null;
    }

    /**
     * Stellt bei Strg+C die Terminal-Einstellung wieder her (nur mit pcntl).
     *
     * @return callable|int|null bisheriger Signal-Handler
     */
    private static function trapInterrupt(\Closure $restore): callable|int|null
    {
        if (!function_exists('pcntl_signal')) {
            return null;
        }

        $previous = pcntl_signal_get_handler(SIGINT);
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, static function () use ($restore): never {
            $restore();
            exit(130);
        });

        return $previous;
    }

    private static function releaseInterrupt(callable|int|null $previous): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, $previous ?? SIG_DFL);
        }
    }
}
