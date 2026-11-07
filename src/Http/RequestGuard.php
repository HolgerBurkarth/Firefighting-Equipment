<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Prüft ändernde Anfragen (alles außer GET, HEAD, OPTIONS) als Schutz gegen CSRF und liest den
 * JSON-Body: Content-Type muss application/json sein (Formulare anderer Seiten können das nicht
 * senden), ein vorhandener Origin muss erlaubt sein, der Body muss ein JSON-Objekt sein (auch {}).
 * Einzige Ausnahme: öffentliche Routen mit BodyFormat::Form (oauth/token, oauth/revoke) nehmen
 * genau application/x-www-form-urlencoded; Origin und Größe werden ebenso geprüft.
 */
final readonly class RequestGuard
{
    private const int MAX_JSON_DEPTH = 16;

    private AllowedOrigins $origins;

    /**
     * @param AllowedOrigins|null $origins Standard: nur https://www.prodad.de
     */
    public function __construct(?AllowedOrigins $origins = null)
    {
        $this->origins = $origins ?? AllowedOrigins::onlyDefault();
    }

    /**
     * @param int $maxBodyBytes Grenze der Route (Standard Request::MAX_BODY_BYTES)
     *
     * @return array<string, mixed> der Body als Objekt; [] bei sicheren Methoden
     *
     * @throws HttpException 415 unsupported_media_type, 403 origin_not_allowed, 400 invalid_request
     */
    public function check(Request $request, int $maxBodyBytes = Request::MAX_BODY_BYTES, BodyFormat $format = BodyFormat::Json): array
    {
        if (!$request->isMutating()) {
            return [];
        }

        $contentType = $request->header('Content-Type');
        if (!($format === BodyFormat::Form ? self::isForm($contentType) : self::isJson($contentType))) {
            throw HttpException::unsupportedMediaType($format === BodyFormat::Form ? 'application/x-www-form-urlencoded' : 'application/json');
        }

        $origin = $request->header('Origin');
        if ($origin !== null && !$this->origins->allows($origin)) {
            throw HttpException::originNotAllowed();
        }

        $body = $request->body ?? '';
        if ($request->bodyTooLarge || strlen($body) > $maxBodyBytes) {
            throw HttpException::invalidRequest(sprintf(
                'Der Inhalt der Anfrage ist zu groß (höchstens %d Byte).',
                $maxBodyBytes,
            ));
        }

        if ($format === BodyFormat::Form) {
            return self::parseForm($body);
        }

        if (!str_starts_with(ltrim($body, " \t\r\n"), '{')) {
            throw HttpException::invalidRequest('Erwartet wird ein JSON-Objekt, z. B. {}.');
        }
        try {
            $data = json_decode($body, true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw HttpException::invalidRequest('Der Inhalt der Anfrage ist kein gültiges JSON.');
        }
        if (!is_array($data)) {
            throw HttpException::invalidRequest('Erwartet wird ein JSON-Objekt, z. B. {}.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Formular (BodyFormat::Form): Paare name=wert, getrennt durch &, kodiert wie
     * application/x-www-form-urlencoded (+ = Leerzeichen). Jeder Name höchstens einmal (OAuth 2.1,
     * Abschnitt 3.2), Namen und Werte gültiges UTF-8 ohne Klammern im Namen (keine Listen).
     *
     * @return array<string, string>
     *
     * @throws HttpException 400 invalid_request
     */
    private static function parseForm(string $body): array
    {
        $values = [];
        foreach (explode('&', $body) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            $value = urldecode($value);
            if ($name === '' || str_contains($name, '[') || !mb_check_encoding($name, 'UTF-8') || !mb_check_encoding($value, 'UTF-8')) {
                throw HttpException::invalidRequest('Das Formular ist ungültig.');
            }
            if (array_key_exists($name, $values)) {
                throw HttpException::invalidRequest(sprintf('Der Parameter "%s" ist doppelt.', $name));
            }
            $values[$name] = $value;
        }

        return $values;
    }

    /**
     * application/json, optional mit charset=utf-8; Groß-/Kleinschreibung egal.
     */
    private static function isJson(?string $contentType): bool
    {
        return self::hasType($contentType, 'application/json');
    }

    /**
     * application/x-www-form-urlencoded, optional mit charset=utf-8 (nie multipart/form-data).
     */
    private static function isForm(?string $contentType): bool
    {
        return self::hasType($contentType, 'application/x-www-form-urlencoded');
    }

    private static function hasType(?string $contentType, string $type): bool
    {
        if ($contentType === null) {
            return false;
        }

        $parts = array_map(static fn (string $p): string => strtolower(trim($p)), explode(';', $contentType));
        if (array_shift($parts) !== $type) {
            return false;
        }
        foreach ($parts as $param) {
            if (preg_replace('/\s*=\s*/', '=', $param) !== 'charset=utf-8') {
                return false;
            }
        }

        return true;
    }
}
