<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Prüft ändernde Anfragen (alles außer GET, HEAD, OPTIONS) als Schutz gegen CSRF und liest den
 * JSON-Body: Content-Type muss application/json sein (Formulare anderer Seiten können das nicht
 * senden), ein vorhandener Origin muss erlaubt sein, der Body muss ein JSON-Objekt sein (auch {}).
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
     * @return array<string, mixed> der Body als Objekt; [] bei sicheren Methoden
     *
     * @throws HttpException 415 unsupported_media_type, 403 origin_not_allowed, 400 invalid_request
     */
    public function check(Request $request): array
    {
        if (!$request->isMutating()) {
            return [];
        }

        if (!self::isJson($request->header('Content-Type'))) {
            throw HttpException::unsupportedMediaType();
        }

        $origin = $request->header('Origin');
        if ($origin !== null && !$this->origins->allows($origin)) {
            throw HttpException::originNotAllowed();
        }

        if ($request->bodyTooLarge) {
            throw HttpException::invalidRequest(sprintf(
                'Der Inhalt der Anfrage ist zu groß (höchstens %d Byte).',
                Request::MAX_BODY_BYTES,
            ));
        }

        $body = $request->body ?? '';
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
     * application/json, optional mit charset=utf-8; Groß-/Kleinschreibung egal.
     */
    private static function isJson(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        $parts = array_map(static fn (string $p): string => strtolower(trim($p)), explode(';', $contentType));
        if (array_shift($parts) !== 'application/json') {
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
