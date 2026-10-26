<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

use FirefightingEquipment\Config\ConfigException;
use FirefightingEquipment\Config\ConfigLoader;

/**
 * Mailer nach der Konfiguration (Gruppe 'mail'), die erst beim ersten Versand geladen wird.
 */
final class ConfiguredMailer implements Mailer
{
    private ?Mailer $mailer = null;

    /**
     * @param \Closure(): Mailer $factory
     */
    public function __construct(private readonly \Closure $factory)
    {
    }

    public static function fromConfigFile(string $configPath, string $projectDir): self
    {
        return new self(static function () use ($configPath, $projectDir): Mailer {
            try {
                return new ConfigLoader()->loadMailConfig($configPath)->createMailer($projectDir);
            } catch (ConfigException $e) {
                throw new MailException('Mail-Konfiguration nicht nutzbar.', 0, $e);
            }
        });
    }

    public function send(MailMessage $message): string
    {
        return ($this->mailer ??= ($this->factory)())->send($message);
    }

    public function sendMany(array $messages, float $deadline = INF): array
    {
        if ($messages === []) {
            return [];
        }
        try {
            $this->mailer ??= ($this->factory)();
        } catch (MailException $e) {
            return array_fill(0, count($messages), $e);
        }

        return $this->mailer->sendMany($messages, $deadline);
    }
}
