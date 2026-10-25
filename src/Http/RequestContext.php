<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

use FirefightingEquipment\Auth\AuthSession;
use FirefightingEquipment\Database\LazyConnection;
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
    ) {
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

    public function param(string $name): int
    {
        return $this->params[$name] ?? throw new \LogicException(sprintf('Platzhalter fehlt: %s', $name));
    }
}
