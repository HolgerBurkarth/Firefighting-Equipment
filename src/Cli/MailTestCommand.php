<?php

declare(strict_types=1);

namespace FirefightingEquipment\Cli;

use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Mail\MailConfig;
use FirefightingEquipment\Mail\MailException;
use FirefightingEquipment\Mail\Mailer;
use FirefightingEquipment\Mail\MailTemplates;
use FirefightingEquipment\Mail\MailTransport;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\UserException;

/**
 * Kommandozeile bin/mail-test.php: schickt eine Testmail über den konfigurierten Transport (zum
 * Prüfen von Zustellung, SPF und DKIM). Anders als die Web-API zeigt sie Fehler an.
 */
final readonly class MailTestCommand
{
    public const int EXIT_OK = 0;
    public const int EXIT_ERROR = 1;
    public const int EXIT_USAGE = 2;

    private const string USAGE = <<<'TXT'
        Aufruf:
          php bin/mail-test.php --to=ADRESSE   Testmail über den konfigurierten Transport senden

        Weitere Optionen:
          --config=PFAD   andere Konfiguration (Standard: config/config.local.php)

        Transport laut Gruppe 'mail' der Konfiguration: sendmail (Standard), smtp (Postfach, z. B.
        smtp.strato.de) oder file (.eml-Datei). Angezeigt werden Transport, Server, Message-ID und
        Dauer, bei Fehlern die Meldung des Servers (ohne Zugangsdaten).
        Auf dem Server immer php85 statt php verwenden.

        TXT;

    /**
     * @param resource                            $out
     * @param resource                            $err
     * @param (\Closure(MailConfig): Mailer)|null $mailerFactory nur für Tests; Standard laut Konfiguration
     */
    public function __construct(
        private string $defaultConfigPath,
        private string $projectDir,
        private mixed $out,
        private mixed $err,
        private ?\Closure $mailerFactory = null,
    ) {
    }

    /**
     * @param list<string> $args Argumente ohne Skriptnamen
     */
    public function run(array $args): int
    {
        try {
            $options = self::parseArguments($args);
        } catch (\InvalidArgumentException $e) {
            fwrite($this->err, 'FEHLER: ' . $e->getMessage() . PHP_EOL . PHP_EOL . self::USAGE);

            return self::EXIT_USAGE;
        }

        if ($options === null) {
            fwrite($this->out, self::USAGE);

            return self::EXIT_OK;
        }

        try {
            $to = EmailAddress::fromString($options['to']);
            $config = new ConfigLoader()->loadMailConfig($options['config'] ?? $this->defaultConfigPath);
            $mailer = $this->mailerFactory === null ? $config->createMailer($this->projectDir) : ($this->mailerFactory)($config);
        } catch (ConfigException | MailException | UserException $e) {
            fwrite($this->err, 'FEHLER: ' . $e->getMessage() . PHP_EOL);

            return self::EXIT_ERROR;
        }

        $started = hrtime(true);
        try {
            $messageId = $mailer->send(MailTemplates::testMail($to, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))));
        } catch (MailException $e) {
            // Meldungen von MailException enthalten nie Zugangsdaten (bei SMTP: Schritt und Antwort des Servers).
            fwrite($this->err, 'FEHLER: ' . $e->getMessage() . PHP_EOL);
            fwrite($this->err, sprintf('(%s, Dauer: %.2f s)', self::describeTransport($config), (hrtime(true) - $started) / 1e9) . PHP_EOL);

            return self::EXIT_ERROR;
        }
        $seconds = (hrtime(true) - $started) / 1e9;

        $this->line(sprintf('Testmail an %s übergeben (%s, Dauer: %.2f s).', $to->value, self::describeTransport($config), $seconds));
        $this->line('Message-ID: ' . $messageId);
        $this->line(match ($config->transport) {
            MailTransport::Sendmail => 'sendmail hat die Mail angenommen; ob sie ankommt, zeigt erst das Postfach'
                . ' (dort Kopfzeilen anzeigen: SPF, DKIM, Return-Path).',
            MailTransport::Smtp => 'Der SMTP-Server hat die Mail angenommen; ob sie ankommt, zeigt erst das Postfach'
                . ' (dort Kopfzeilen anzeigen: SPF, DKIM, DMARC).',
            MailTransport::File => 'Abgelegt in: ' . $config->fileDirectory($this->projectDir),
        });

        return self::EXIT_OK;
    }

    /**
     * @param list<string> $args
     *
     * @return array{to: string, config: ?string}|null null = Hilfe
     *
     * @throws \InvalidArgumentException bei falschem Aufruf
     */
    public static function parseArguments(array $args): ?array
    {
        $values = ['to' => null, 'config' => null];
        foreach ($args as $arg) {
            if ($arg === '--help' || $arg === '-h' || $arg === 'help') {
                return null;
            }
            if (preg_match('/^--(to|config)=(.*)$/sD', $arg, $match) !== 1) {
                throw new \InvalidArgumentException(sprintf(
                    str_starts_with($arg, '-') ? 'Unbekannte Option: %s' : 'Unerwartetes Argument: %s',
                    explode('=', $arg, 2)[0],
                ));
            }
            if ($values[$match[1]] !== null) {
                throw new \InvalidArgumentException(sprintf('--%s ist doppelt angegeben.', $match[1]));
            }
            if ($match[2] === '') {
                throw new \InvalidArgumentException(sprintf('--%s= braucht einen Wert.', $match[1]));
            }
            $values[$match[1]] = $match[2];
        }

        if ($values['to'] === null) {
            throw new \InvalidArgumentException('Empfänger fehlt: --to=ADRESSE angeben.');
        }

        return ['to' => $values['to'], 'config' => $values['config']];
    }

    /**
     * z. B. "Transport: smtp, Server: smtp.strato.de:465 (ssl)"; nie mit Benutzer oder Passwort.
     */
    private static function describeTransport(MailConfig $config): string
    {
        $text = 'Transport: ' . $config->transport->value;

        return $config->transport === MailTransport::Smtp && $config->smtp !== null
            ? $text . ', Server: ' . $config->smtp->describe()
            : $text;
    }

    private function line(string $text): void
    {
        fwrite($this->out, $text . PHP_EOL);
    }
}
