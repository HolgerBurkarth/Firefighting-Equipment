<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * JSON-Antwort der REST-API.
 */
final readonly class JsonResponse
{
    public const string CONTENT_TYPE = 'application/json; charset=utf-8';

    /** Header, die jede Antwort der API trägt. */
    public const array DEFAULT_HEADERS = [
        'Content-Type' => self::CONTENT_TYPE,
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ];

    /**
     * @param array<array-key, mixed> $data
     * @param array<string, string> $headers zusätzliche Header (z. B. Allow)
     */
    public function __construct(
        private array $data,
        private int $statusCode = 200,
        private array $headers = [],
    ) {
    }

    /**
     * Antwort im einheitlichen Fehlerformat {"error":"code","message":"deutscher Text"}, optional
     * mit "details" (Objekt mit maschinenlesbaren Angaben, z. B. {"devices":3,"sublocations":1}).
     *
     * @param array<string, string>     $headers
     * @param array<string, mixed>|null $details null = ohne Feld "details"
     */
    public static function error(string $code, string $message, int $statusCode, array $headers = [], ?array $details = null): self
    {
        $data = ['error' => $code, 'message' => $message];
        if ($details !== null) {
            // Immer ein JSON-Objekt, auch wenn leer.
            $data['details'] = (object) $details;
        }

        return new self($data, $statusCode, $headers);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function contentType(): string
    {
        return self::CONTENT_TYPE;
    }

    /**
     * @return array<string, string> alle Header einschließlich der Standard-Header
     */
    public function headers(): array
    {
        return [...$this->headers, ...self::DEFAULT_HEADERS];
    }

    /**
     * @throws \JsonException
     */
    public function body(): string
    {
        return json_encode(
            $this->data,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    public function send(): void
    {
        $body = $this->body();

        http_response_code($this->statusCode);
        foreach ($this->headers() as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $body;
    }
}
