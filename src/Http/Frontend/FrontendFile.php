<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http\Frontend;

use FirefightingEquipment\Http\ContentEncoding;

/** Eine Datei aus dem Build mit festem Content-Type, ggf. in einer vorkomprimierten Fassung. */
final readonly class FrontendFile
{
    /**
     * @param ContentEncoding|null $encoding Fassung des Bodys (null = unkomprimiert)
     * @param bool                 $varies   es gibt vorkomprimierte Fassungen (Vary: Accept-Encoding)
     */
    public function __construct(
        public string $contentType,
        public string $body,
        public ?ContentEncoding $encoding = null,
        public bool $varies = false,
    ) {
        if ($encoding !== null && !$varies) {
            throw new \InvalidArgumentException('Eine komprimierte Fassung braucht Vary: Accept-Encoding.');
        }
    }

    /**
     * Starker ETag über den gesendeten Inhalt, z. B. "\"3f2a…\"" (32 Hex-Zeichen), bei einer
     * komprimierten Fassung mit Anhang ("\"3f2a…-br\"").
     */
    public function etag(): string
    {
        $suffix = $this->encoding === null ? '' : '-' . $this->encoding->etagSuffix();

        return '"' . substr(hash('sha256', $this->body), 0, 32) . $suffix . '"';
    }
}
