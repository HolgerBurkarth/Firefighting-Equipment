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
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode,
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param string $message deutscher Hinweis, was an der Anfrage falsch ist
     */
    public static function invalidRequest(string $message = 'Die Anfrage ist ungültig.'): self
    {
        return new self('invalid_request', $message, 400);
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
     * @param string $message Meldung der Regel (deutsch, für Benutzer)
     */
    public static function ruleViolation(string $message): self
    {
        return new self('rule_violation', $message, 409);
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
        return JsonResponse::error($this->errorCode, $this->getMessage(), $this->statusCode, $this->headers);
    }
}
