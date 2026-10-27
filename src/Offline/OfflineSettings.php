<?php

declare(strict_types=1);

namespace FirefightingEquipment\Offline;

use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Config\OfflineConfig;

/**
 * Zeitlimits der Offline-Buchungen (Gruppe 'offline'). Die Konfiguration wird erst beim ersten
 * Zugriff geladen (nur bei client-config und Buchungen mit occurred_at).
 */
final class OfflineSettings
{
    /** Größte erlaubte Abweichung der Uhr des Handys in die Zukunft (Sekunden). */
    public const int MAX_CLOCK_SKEW_SECONDS = 300;

    private ?OfflineConfig $config = null;

    /**
     * @param (\Closure(): OfflineConfig)|null $load null = Standardwerte
     */
    public function __construct(private readonly ?\Closure $load = null)
    {
    }

    public static function fromConfigFile(string $configPath): self
    {
        return new self(static fn (): OfflineConfig => new ConfigLoader()->loadOfflineConfig($configPath));
    }

    public static function fixed(OfflineConfig $config): self
    {
        return new self(static fn (): OfflineConfig => $config);
    }

    public function config(): OfflineConfig
    {
        return $this->config ??= $this->load === null ? new OfflineConfig() : ($this->load)();
    }
}
