<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * JSON-Antwort der REST-API.
 */
final readonly class JsonResponse
{
    public const string CONTENT_TYPE = 'application/json; charset=utf-8';

    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(
        private array $data,
        private int $statusCode = 200,
    ) {
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
        header('Content-Type: ' . self::CONTENT_TYPE);
        header('Cache-Control: no-store');

        echo $body;
    }
}
