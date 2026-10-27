<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Bekannter Fehler einer Anfrage, der als JSON im einheitlichen Fehlerformat beantwortet wird.
 * Die Meldung ist für Benutzer bestimmt; technische Details stehen nur in $previous.
 */
final class HttpException extends \RuntimeException
{
    /**
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $details Feld "details" der Antwort (null = ohne)
     */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode,
        public readonly array $headers = [],
        ?\Throwable $previous = null,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param string                    $message deutscher Hinweis, was an der Anfrage falsch ist
     * @param array<string, mixed>|null $details z. B. {"line": 4} bei einer fehlerhaften CSV-Datei
     */
    public static function invalidRequest(string $message = 'Die Anfrage ist ungültig.', ?array $details = null): self
    {
        return new self('invalid_request', $message, 400, details: $details);
    }

    /**
     * @param array<string, string> $headers z. B. Set-Cookie zum Löschen eines ungültigen Cookies
     */
    public static function notAuthenticated(array $headers = []): self
    {
        return new self('not_authenticated', 'Bitte zuerst anmelden.', 401, $headers);
    }

    /**
     * Einmal-Link unbekannt, abgelaufen, schon benutzt oder für ein anderes Konto bzw. einen anderen
     * Bereich (bewusst nicht unterschieden).
     */
    public static function invalidToken(): self
    {
        return new self('invalid_token', 'Der Link ist ungültig oder abgelaufen. Bitte fordern Sie einen neuen an.', 400);
    }

    /**
     * Einladungscode unbekannt, abgelaufen, aufgebraucht oder widerrufen (bewusst eine gemeinsame
     * Meldung; sie verrät nichts über Konten).
     */
    public static function invalidInvitationCode(): self
    {
        return new self(
            'invalid_invitation_code',
            'Der Einladungscode ist ungültig, abgelaufen, aufgebraucht oder widerrufen. Bitte wenden Sie sich an Ihre Feuerwehr.',
            400,
        );
    }

    public static function invalidCredentials(): self
    {
        return new self('invalid_credentials', 'E-Mail-Adresse oder Passwort ist falsch.', 401);
    }

    public static function forbidden(): self
    {
        return new self('forbidden', 'Für diese Aktion fehlt die Berechtigung.', 403);
    }

    public static function originNotAllowed(): self
    {
        return new self('origin_not_allowed', 'Anfragen von dieser Herkunft sind nicht erlaubt.', 403);
    }

    public static function accountLocked(): self
    {
        return new self('account_locked', 'Dieses Konto ist gesperrt.', 403);
    }

    public static function emailNotVerified(): self
    {
        return new self('email_not_verified', 'Die E-Mail-Adresse ist noch nicht bestätigt.', 403);
    }

    public static function pendingApproval(): self
    {
        return new self('pending_approval', 'Das Konto wartet noch auf die Freischaltung durch einen Admin.', 403);
    }

    public static function invalidCurrentPassword(): self
    {
        return new self('invalid_current_password', 'Das aktuelle Passwort ist falsch.', 403);
    }

    public static function notFound(): self
    {
        return new self('not_found', 'Die Adresse wurde nicht gefunden.', 404);
    }

    public static function tenantNotFound(): self
    {
        return new self('tenant_not_found', 'Diese Stadt ist nicht bekannt.', 404);
    }

    public static function tenantInactive(): self
    {
        return new self('tenant_inactive', 'Diese Stadt ist gesperrt.', 403);
    }

    /**
     * @param list<string> $allowedMethods
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(
            'method_not_allowed',
            'Diese Methode ist für die Adresse nicht erlaubt.',
            405,
            ['Allow' => implode(', ', $allowedMethods)],
        );
    }

    /**
     * Eine Änderung verletzt eine Geschäftsregel (z. B. letzter Admin, sich selbst sperren).
     *
     * @param string                    $message Meldung der Regel (deutsch, für Benutzer)
     * @param array<string, mixed>|null $details z. B. {"devices":3,"sublocations":1} beim Sperren
     *                                           eines Standorts, der nicht leer ist
     */
    public static function ruleViolation(string $message, ?array $details = null): self
    {
        return new self('rule_violation', $message, 409, details: $details);
    }

    /**
     * Der Name eines Gerätetyps bzw. Standorts ist schon vergeben (ohne Groß- und Kleinschreibung).
     *
     * @param string $message Meldung mit dem Namen
     */
    public static function nameTaken(string $message): self
    {
        return new self('name_taken', $message, 409);
    }

    /**
     * Die Kennung eines Geräts ist unter den nicht ausgesonderten Geräten der Stadt schon vergeben
     * (ohne Groß- und Kleinschreibung).
     *
     * @param string $message Meldung mit der Kennung
     */
    public static function identifierTaken(string $message): self
    {
        return new self('identifier_taken', $message, 409);
    }

    /**
     * Der Scan-Code eines Geräts ist unter den nicht ausgesonderten Geräten der Stadt schon vergeben
     * (exakter Vergleich).
     *
     * @param string $message Meldung mit dem Scan-Code
     */
    public static function scanCodeTaken(string $message): self
    {
        return new self('scan_code_taken', $message, 409);
    }

    /**
     * Ein Datensatz wurde seit dem Laden geändert (erwartete Version veraltet); nichts geschrieben.
     *
     * @param array<string, mixed> $details aktueller Stand, z. B. {"device": {…}}
     */
    public static function versionConflict(string $message, array $details): self
    {
        return new self('version_conflict', $message, 409, details: $details);
    }

    /**
     * CSV-Import: Seit der Vorschau hat sich etwas geändert (betroffenes Gerät, Kennung inzwischen
     * vergeben, Standort gesperrt, andere Datei …); nichts geschrieben, Vorschau neu erstellen.
     *
     * @param array<string, mixed> $details z. B. Anzahlen der neuen Prüfung
     */
    public static function importOutdated(string $message, array $details = []): self
    {
        return new self('import_outdated', $message, 409, details: $details === [] ? null : $details);
    }

    /**
     * Buchung: Dieselbe client_ref wurde bereits für eine andere Buchung verwendet (anderes Konto,
     * Gerät oder anderer Inhalt); nichts geschrieben.
     */
    public static function clientRefConflict(string $message): self
    {
        return new self('client_ref_conflict', $message, 409);
    }

    /**
     * Offline-Buchung älter als offline.queue_max_age_days ohne confirmed_stale: Das Handy fragt
     * nach und sendet sie ggf. mit confirmed_stale erneut; nichts geschrieben.
     *
     * @param array<string, mixed> $details z. B. {"occurred_at": …, "queue_max_age_days": 7}
     */
    public static function staleAction(string $message, array $details): self
    {
        return new self('stale_action', $message, 409, details: $details);
    }

    /**
     * Offline-Buchung mit einer Aktionszeit zu weit in der Zukunft (Uhr des Handys falsch);
     * nichts geschrieben.
     *
     * @param array<string, mixed> $details z. B. {"server_time": …, "occurred_at": …, "max_skew_seconds": 300}
     */
    public static function clockImplausible(string $message, array $details): self
    {
        return new self('clock_implausible', $message, 422, details: $details);
    }

    /**
     * Das Kürzel einer neuen Stadt ist bereits vergeben (nur Super-Admin).
     *
     * @param string $message Meldung mit dem Kürzel
     */
    public static function slugTaken(string $message): self
    {
        return new self('slug_taken', $message, 409);
    }

    /**
     * Für die E-Mail-Adresse gibt es in der Stadt schon ein Konto. Nur, wo der Aufrufer das wissen
     * darf (Einladung durch den Super-Admin); öffentliche Abläufe verraten es nie.
     *
     * @param string $message Meldung mit der Adresse
     */
    public static function emailTaken(string $message): self
    {
        return new self('email_taken', $message, 409);
    }

    public static function unsupportedMediaType(): self
    {
        return new self('unsupported_media_type', 'Erwartet wird Content-Type: application/json.', 415);
    }

    /**
     * @param string $message Meldung der Passwortregeln (enthält nie das Passwort)
     */
    public static function weakPassword(string $message): self
    {
        return new self('weak_password', $message, 422);
    }

    public static function tooManyAttempts(int $retryAfterSeconds): self
    {
        return new self(
            'too_many_attempts',
            'Zu viele Fehlversuche. Bitte später erneut versuchen.',
            429,
            ['Retry-After' => (string) max(1, $retryAfterSeconds)],
        );
    }

    public static function databaseUnavailable(\Throwable $previous): self
    {
        return new self(
            'database_unavailable',
            'Die Datenbank ist zurzeit nicht erreichbar.',
            503,
            previous: $previous,
        );
    }

    public static function internalError(): self
    {
        return new self('internal_error', 'Interner Fehler.', 500);
    }

    public function toResponse(): JsonResponse
    {
        return JsonResponse::error($this->errorCode, $this->getMessage(), $this->statusCode, $this->headers, $this->details);
    }
}
