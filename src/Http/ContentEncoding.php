<?php

declare(strict_types=1);

namespace FirefightingEquipment\Http;

/**
 * Vorkomprimierte Fassungen der Dateien des Frontends (STRATO komprimiert die Ausgabe nicht). Der
 * Build legt sie neben die Datei (frontend/build/compress.ts, gleiche Endungen per Test); PHP
 * dekomprimiert nie, es wählt nur die Fassung (FrontendAssets). Reihenfolge der Fälle = Vorrang bei
 * gleicher Gewichtung.
 */
enum ContentEncoding: string
{
    case Brotli = 'br';
    case Gzip = 'gzip';

    /** Endung der Fassung im Build, z. B. "assets/index-abc.js.br". */
    public function fileSuffix(): string
    {
        return match ($this) {
            self::Brotli => '.br',
            self::Gzip => '.gz',
        };
    }

    /** Anhang am ETag: jede Fassung hat ihren eigenen (FrontendFile::etag). */
    public function etagSuffix(): string
    {
        return match ($this) {
            self::Brotli => 'br',
            self::Gzip => 'gz',
        };
    }

    /**
     * Beste vorhandene Fassung laut Accept-Encoding (RFC 9110, 12.5.3): Gewicht aus dem eigenen
     * Eintrag, sonst aus "*", sonst nicht erlaubt; q=0 schließt aus. Höchstes Gewicht gewinnt, bei
     * Gleichstand die Reihenfolge der Fälle. "x-gzip" gilt als "gzip". Einträge mit ungültigem
     * Gewicht werden übergangen. Eine Vorliebe für "identity" wird nicht ausgewertet: Ist eine
     * Fassung erlaubt, wird sie gesendet.
     *
     * @param list<self> $available vorhandene Fassungen
     *
     * @return self|null null = unkomprimiert senden
     */
    public static function negotiate(?string $acceptEncoding, array $available): ?self
    {
        if ($acceptEncoding === null || $available === []) {
            return null;
        }

        $weights = [];
        foreach (explode(',', $acceptEncoding) as $entry) {
            $parts = explode(';', $entry);
            $coding = strtolower(trim($parts[0]));
            if ($coding === '') {
                continue;
            }
            $coding = $coding === 'x-gzip' ? self::Gzip->value : $coding;
            $weight = 1.0;
            foreach (array_slice($parts, 1) as $parameter) {
                $pair = explode('=', $parameter, 2);
                if (strtolower(trim($pair[0])) === 'q') {
                    $value = trim($pair[1] ?? '');
                    if (preg_match('/^(0(\.[0-9]{0,3})?|1(\.0{0,3})?)\z/', $value) !== 1) {
                        continue 2;
                    }
                    $weight = (float) $value;
                }
            }
            $weights[$coding] ??= $weight;
        }

        $best = null;
        $bestWeight = 0.0;
        foreach (self::cases() as $encoding) {
            if (!in_array($encoding, $available, true)) {
                continue;
            }
            $weight = $weights[$encoding->value] ?? $weights['*'] ?? 0.0;
            if ($weight > $bestWeight) {
                $best = $encoding;
                $bestWeight = $weight;
            }
        }

        return $best;
    }
}
