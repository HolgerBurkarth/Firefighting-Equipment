<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Eingehende HTTP-Anfrage (nur die Teile, die die Anwendung braucht).
 */
final readonly class Request
{
    /** Höchstgröße des Bodys in Byte; größere Bodys werden nicht gelesen. */
    public const int MAX_BODY_BYTES = 16384;

    /** Methoden ohne Body; alle anderen gelten als ändernd. */
    public const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * @param array<string, string> $headers Namen kleingeschrieben, z. B. "content-type"
     * @param array<string, string> $cookies
     */
    public function __construct(
        public string $method,
        /** Wie $_SERVER['REQUEST_URI'], z. B. "/firefighting/service/api/v1/health?x=1". */
        public string $uri,
        public array $headers = [],
        public array $cookies = [],
        /** Rohdaten; null bei sicheren Methoden oder wenn der Body zu groß war. */
        public ?string $body = null,
        public bool $bodyTooLarge = false,
        /** Wie $_SERVER['REMOTE_ADDR']; "" wenn unbekannt. */
        public string $remoteAddress = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        return self::fromServer($_SERVER, $_COOKIE, static fn () => fopen('php://input', 'rb'));
    }

    /**
     * @param array<array-key, mixed>            $server  wie $_SERVER
     * @param array<array-key, mixed>            $cookies wie $_COOKIE
     * @param \Closure(): (resource|false)       $input   öffnet den Body (nur bei ändernden Methoden)
     */
    public static function fromServer(array $server, array $cookies, \Closure $input): self
    {
        $method = self::string($server, 'REQUEST_METHOD') ?? 'GET';
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[strtolower(str_replace('_', '-', $key))] = $value;
            }
        }

        $body = null;
        $tooLarge = false;
        if (!in_array($method, self::SAFE_METHODS, true)) {
            [$body, $tooLarge] = self::readBody($headers['content-length'] ?? null, $input);
        }

        return new self(
            $method,
            self::string($server, 'REQUEST_URI') ?? '/',
            $headers,
            array_filter($cookies, static fn (mixed $v, mixed $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH),
            $body,
            $tooLarge,
            self::string($server, 'REMOTE_ADDR') ?? '',
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Parameter des Query-Strings. Nur einfache Werte; Arrays wie "status[]=x" werden ignoriert.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        $position = strpos($this->uri, '?');
        if ($position === false) {
            return [];
        }
        parse_str(substr($this->uri, $position + 1), $values);

        return array_filter($values, static fn (mixed $v, mixed $k): bool => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
    }

    public function isMutating(): bool
    {
        return !in_array($this->method, self::SAFE_METHODS, true);
    }

    /**
     * @param \Closure(): (resource|false) $input
     *
     * @return array{?string, bool} Body und ob er zu groß war
     */
    private static function readBody(?string $contentLength, \Closure $input): array
    {
        if ($contentLength !== null && (!ctype_digit($contentLength) || strlen($contentLength) > 9
            || (int) $contentLength > self::MAX_BODY_BYTES)) {
            return [null, true];
        }

        $stream = $input();
        if ($stream === false) {
            return ['', false];
        }
        try {
            $body = stream_get_contents($stream, self::MAX_BODY_BYTES + 1);
        } finally {
            fclose($stream);
        }
        if ($body === false) {
            return ['', false];
        }

        return strlen($body) > self::MAX_BODY_BYTES ? [null, true] : [$body, false];
    }

    /**
     * @param array<array-key, mixed> $server
     */
    private static function string(array $server, string $key): ?string
    {
        $value = $server[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
