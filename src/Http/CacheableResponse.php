<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Öffentliche Antwort, die zwischengespeichert werden darf: PNG (Logo, Standard-Symbol) oder
 * Web-App-Manifest. Einzige Ausnahme von "Cache-Control: no-store" (siehe CachePolicy); nur diese
 * Content-Types sind möglich. Immer mit ETag; passt If-None-Match, antwortet sie mit 304 ohne Body.
 * Fehler dieser Routen bleiben JSON mit no-store.
 */
final readonly class CacheableResponse implements Response
{
    public const string PNG = 'image/png';
    public const string MANIFEST = 'application/manifest+json; charset=utf-8';

    private bool $notModified;

    /**
     * @param string $etag starker ETag mit Anführungszeichen, z. B. "\"3f2a…-512\""
     */
    public function __construct(
        Request $request,
        private string $contentType,
        private string $body,
        private string $etag,
        private CachePolicy $policy,
    ) {
        if (!in_array($contentType, [self::PNG, self::MANIFEST], true)) {
            throw new \InvalidArgumentException(sprintf('Content-Type nicht zwischenspeicherbar: %s', $contentType));
        }
        if (preg_match('/^"[0-9a-z-]{1,64}"\z/', $etag) !== 1) {
            throw new \InvalidArgumentException('Ungültiger ETag.');
        }
        $this->notModified = self::matches($request->header('If-None-Match'), $etag);
    }

    public function statusCode(): int
    {
        return $this->notModified ? 304 : 200;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        $headers = [
            'Cache-Control' => $this->policy->header(),
            'ETag' => $this->etag,
            'X-Content-Type-Options' => JsonResponse::DEFAULT_HEADERS['X-Content-Type-Options'],
        ];
        if (!$this->notModified) {
            $headers = ['Content-Type' => $this->contentType, 'Content-Length' => (string) strlen($this->body), ...$headers];
        }

        return $headers;
    }

    /** Body; bei 304 leer. */
    public function body(): string
    {
        return $this->notModified ? '' : $this->body;
    }

    public function send(): void
    {
        http_response_code($this->statusCode());
        foreach ($this->headers() as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body();
    }

    /**
     * If-None-Match nach RFC 9110 (schwacher Vergleich): "*" oder einer der ETags der Liste, auch mit W/.
     */
    public static function matches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null) {
            return false;
        }
        foreach (explode(',', $ifNoneMatch) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate === '*' || $candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
