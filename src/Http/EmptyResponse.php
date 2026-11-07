<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Antwort ohne Body, bisher nur 202 an mcp für Benachrichtigungen und Antworten des Clients (MCP,
 * Streamable HTTP: "202 Accepted with no body"). Header wie JsonResponse ohne Content-Type.
 */
final readonly class EmptyResponse implements Response
{
    public function __construct(private int $statusCode = 202)
    {
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return array_diff_key(JsonResponse::DEFAULT_HEADERS, ['Content-Type' => true]);
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers() as $name => $value) {
            header($name . ': ' . $value);
        }
    }
}
