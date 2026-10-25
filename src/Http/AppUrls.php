<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Auth\OneTimeToken;
use FirefightingEquipment\Config\AppConfig;
use FirefightingEquipment\Config\ConfigLoader;
use FirefightingEquipment\Tenant\Tenant;

/**
 * Absolute Adressen für Mails: app.base_url + /firefighting/<kürzel>/… bzw. /firefighting/service/….
 * Die Konfiguration wird erst bei der ersten Adresse geladen.
 */
final class AppUrls
{
    public const string PASSWORD_RESET_PAGE = 'passwort-zuruecksetzen';

    private ?string $baseUrl = null;

    /**
     * @param (\Closure(): string)|null $loadBaseUrl null = AppConfig::DEFAULT_BASE_URL
     */
    public function __construct(private readonly ?\Closure $loadBaseUrl = null)
    {
    }

    public static function fromConfigFile(string $configPath): self
    {
        return new self(static fn (): string => new ConfigLoader()->loadAppConfig($configPath)->baseUrl);
    }

    public static function fixed(string $baseUrl): self
    {
        return new self(static fn (): string => $baseUrl);
    }

    /**
     * Startseite des Bereichs (mit / am Ende).
     *
     * @param Tenant|null $tenant null = Bereich service
     */
    public function area(?Tenant $tenant): string
    {
        $this->baseUrl ??= $this->loadBaseUrl === null ? AppConfig::DEFAULT_BASE_URL : ($this->loadBaseUrl)();

        return $this->baseUrl . AppPath::PREFIX . '/' . ($tenant?->slug->value ?? AppPath::SERVICE_SCOPE) . '/';
    }

    /**
     * Link zum Zurücksetzen. Das Token steht hinter #: Der Browser sendet es nicht mit dem Aufruf der
     * Seite (kein Server-Log, kein Referer); die Seite schickt es per POST an die API.
     *
     * @param Tenant|null $tenant null = Bereich service
     */
    public function passwordReset(?Tenant $tenant, OneTimeToken $token): string
    {
        return $this->area($tenant) . self::PASSWORD_RESET_PAGE . '#token=' . $token->value();
    }
}
