<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Auth\AuthSession;
use FirefightingEquipment\Database\LazyConnection;
use FirefightingEquipment\Mail\Mailer;
use FirefightingEquipment\Mail\Notifier;
use FirefightingEquipment\Mail\SendmailMailer;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\User\Password\PasswordHasher;

/**
 * Alles, was ein Handler über die Anfrage wissen darf.
 */
final readonly class RequestContext
{
    /**
     * @param array<string, int>   $params Werte der Platzhalter der Route, z. B. ['id' => 42]
     * @param array<string, mixed> $body   JSON-Body ändernder Anfragen (von RequestGuard geprüft)
     */
    public function __construct(
        public Request $request,
        /** Die aktive Stadt; null im Bereich service. */
        public ?Tenant $tenant,
        /** Die Verbindung wird erst beim ersten Zugriff aufgebaut. */
        public LazyConnection $db,
        public ErrorReporter $errors,
        public array $params = [],
        public array $body = [],
        /** Angemeldetes Konto; null bei öffentlichen Routen (Access::Public). */
        public ?AuthSession $auth = null,
        /** Argon2id für Anmeldung und Passwortänderung (in Produktion Standardparameter). */
        public PasswordHasher $hasher = new PasswordHasher(),
        /** Versand von Mails; Handler senden über notifier(). */
        public Mailer $mailer = new SendmailMailer(),
        /** Absolute Adressen für Mails (app.base_url). */
        public AppUrls $urls = new AppUrls(),
    ) {
    }

    /**
     * Versand ohne Fehler für den Aufrufer: Fehler landen im Protokoll (ErrorReporter).
     */
    public function notifier(): Notifier
    {
        return new Notifier($this->mailer, $this->errors);
    }

    /**
     * Die Sitzung einer Route, die eine Anmeldung verlangt (von Application geprüft).
     */
    public function auth(): AuthSession
    {
        return $this->auth ?? throw new \LogicException('Route ohne Anmeldung: keine Sitzung vorhanden.');
    }

    /**
     * Pflichtfeld des JSON-Bodys als Text.
     *
     * @throws HttpException 400 invalid_request
     */
    public function stringField(string $name): string
    {
        $value = $this->body[$name] ?? null;
        if (!is_string($value)) {
            throw HttpException::invalidRequest(sprintf('Das Feld "%s" fehlt oder ist kein Text.', $name));
        }

        return $value;
    }

    /**
     * Optionales Feld des JSON-Bodys als Text; fehlt es oder ist es null, dann null.
     *
     * @throws HttpException 400 invalid_request
     */
    public function optionalStringField(string $name): ?string
    {
        $value = $this->body[$name] ?? null;
        if ($value !== null && !is_string($value)) {
            throw HttpException::invalidRequest(sprintf('Das Feld "%s" ist kein Text.', $name));
        }

        return $value;
    }

    /**
     * Pflichtfeld des JSON-Bodys als Ganzzahl im Bereich $min bis $max. Nur echte JSON-Zahlen ohne
     * Nachkommastellen; "5", 5.0 und true werden abgelehnt.
     *
     * @throws HttpException 400 invalid_request
     */
    public function intField(string $name, int $min, int $max): int
    {
        $value = $this->body[$name] ?? null;
        if (!is_int($value) || $value < $min || $value > $max) {
            throw HttpException::invalidRequest(sprintf('Das Feld "%s" muss eine ganze Zahl von %d bis %d sein.', $name, $min, $max));
        }

        return $value;
    }

    /**
     * Optionales Feld des JSON-Bodys als Ganzzahl wie bei intField(); fehlt es oder ist es null,
     * dann null.
     *
     * @throws HttpException 400 invalid_request
     */
    public function optionalIntField(string $name, int $min, int $max): ?int
    {
        return ($this->body[$name] ?? null) === null ? null : $this->intField($name, $min, $max);
    }

    /**
     * Schalter im Query-String (z. B. ?include_inactive=1): "1" = an, "0" oder fehlend = aus.
     *
     * @throws HttpException 400 invalid_request bei jedem anderen Wert
     */
    public function queryFlag(string $name): bool
    {
        return match ($this->request->query()[$name] ?? null) {
            null, '0' => false,
            '1' => true,
            default => throw HttpException::invalidRequest(sprintf('Der Parameter "%s" muss 0 oder 1 sein.', $name)),
        };
    }

    public function param(string $name): int
    {
        return $this->params[$name] ?? throw new \LogicException(sprintf('Platzhalter fehlt: %s', $name));
    }
}
