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
    public const string VERIFY_EMAIL_PAGE = 'email-bestaetigen';
    public const string ACCEPT_INVITATION_PAGE = 'einladung-annehmen';
    /** Geräteliste der Admin-Konsole; Gerätedetail unter geraete/<id>/details */
    public const string DEVICES_PAGE = 'geraete';
    /** Hinweise der Admin-Konsole; einzelner Hinweis unter protokoll/<id> */
    public const string NOTICES_PAGE = 'protokoll';
    public const string USERS_PAGE = 'benutzer';

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

    /**
     * Link zum Bestätigen der E-Mail-Adresse (nur unter einer Stadt); Token hinter # wie oben.
     */
    public function verifyEmail(Tenant $tenant, OneTimeToken $token): string
    {
        return $this->area($tenant) . self::VERIFY_EMAIL_PAGE . '#token=' . $token->value();
    }

    /**
     * Link zum Annehmen einer Einladung als Admin (nur unter einer Stadt); Token hinter # wie oben.
     */
    public function acceptInvitation(Tenant $tenant, OneTimeToken $token): string
    {
        return $this->area($tenant) . self::ACCEPT_INVITATION_PAGE . '#token=' . $token->value();
    }

    /**
     * Geräteliste der Admin-Konsole (Log-Mail eines CSV-Imports).
     */
    public function devices(Tenant $tenant): string
    {
        return $this->area($tenant) . self::DEVICES_PAGE;
    }

    /**
     * Gerätedetail der Admin-Konsole (Log-Mails an Admins der Stadt).
     */
    public function deviceDetails(Tenant $tenant, int $deviceId): string
    {
        return $this->area($tenant) . self::DEVICES_PAGE . '/' . self::id($deviceId) . '/details';
    }

    /**
     * Ein Hinweis zu einer Offline-Buchung in der Admin-Konsole.
     */
    public function notice(Tenant $tenant, int $noticeId): string
    {
        return $this->area($tenant) . self::NOTICES_PAGE . '/' . self::id($noticeId);
    }

    /**
     * Benutzerverwaltung der Admin-Konsole (Registrierung wartet auf Freischaltung).
     */
    public function users(Tenant $tenant): string
    {
        return $this->area($tenant) . self::USERS_PAGE;
    }

    /**
     * IDs wie die Platzhalter des Routers: nur positive Ganzzahlen.
     */
    private static function id(int $id): string
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Ungültige ID.');
        }

        return (string) $id;
    }
}
