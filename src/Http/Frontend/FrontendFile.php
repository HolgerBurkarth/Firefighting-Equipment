<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http\Frontend;

/** Eine Datei aus dem Build mit festem Content-Type. */
final readonly class FrontendFile
{
    public function __construct(
        public string $contentType,
        public string $body,
    ) {
    }

    /** Starker ETag über den Inhalt, z. B. "\"3f2a…\"" (32 Hex-Zeichen). */
    public function etag(): string
    {
        return '"' . substr(hash('sha256', $this->body), 0, 32) . '"';
    }
}
