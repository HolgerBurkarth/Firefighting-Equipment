<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Import;

/**
 * Vorschläge bei Tippfehlern in der Vorschau des Imports („Meinten Sie ‚HLF 20‘?“): zu einem
 * unbekannten Gerätetyp bzw. Standort die ähnlichsten gültigen Werte.
 *
 * Verglichen wird eine Vergleichsform ohne Leerraum, Groß- und Kleinschreibung, mit ä/ö/ü/ß als
 * ae/oe/ue/ss („HLF20“ = „HLF 20“, „Waermebildkamera“ = „Wärmebildkamera“), dann der Abstand nach
 * Levenshtein über Unicode-Zeichen. Vorgeschlagen werden die Werte mit dem kleinsten Abstand, sofern
 * er höchstens ein Viertel der Länge beträgt (mindestens 1, höchstens 3), nach Name sortiert,
 * höchstens MAX_SUGGESTIONS. Nur Hinweise: Verbindlich bleibt der Vergleich der Datenbank.
 */
final class ImportSuggestions
{
    public const int MAX_SUGGESTIONS = 3;

    /** Höchstens so viele verschiedene unbekannte Werte je Art erhalten Vorschläge (Laufzeit). */
    public const int MAX_VALUES = 100;

    private const int MAX_DISTANCE = 3;

    /** @var array<string, list<list<string>>> Wert => Vergleichsformen (als Zeichen) */
    private array $candidates = [];

    private int $searched = 0;

    /**
     * @param list<array{string, list<string>}> $candidates gültiger Wert und weitere Schreibweisen,
     *                                                       unter denen er gefunden werden soll
     *                                                       (z. B. „G1“ für „HLF 20 / G1“)
     */
    public function __construct(array $candidates)
    {
        foreach ($candidates as [$value, $aliases]) {
            foreach ([$value, ...$aliases] as $alias) {
                $this->candidates[$value][] = mb_str_split(self::key($alias));
            }
        }
    }

    /**
     * Gerätetypen über ihren Namen.
     *
     * @param list<string> $names
     */
    public static function forNames(array $names): self
    {
        return new self(array_map(static fn (string $name): array => [$name, []], $names));
    }

    /**
     * Standorte über ihren Pfad; Unterstandorte auch über ihren eigenen Namen (wer nur „G1“
     * schreibt, bekommt „HLF 20 / G1“ vorgeschlagen).
     *
     * @param list<array{string, ?string}> $paths Pfad und bei Unterstandorten der eigene Name
     */
    public static function forLocations(array $paths): self
    {
        return new self(array_map(static fn (array $p): array => [$p[0], $p[1] === null ? [] : [$p[1]]], $paths));
    }

    /**
     * Ähnlichste gültige Werte (leer: keiner ähnlich genug, oder schon MAX_VALUES Werte gesucht).
     *
     * @return list<string>
     */
    public function suggest(string $value): array
    {
        $key = mb_str_split(self::key($value));
        if ($key === [] || $this->searched >= self::MAX_VALUES) {
            return [];
        }
        $this->searched++;

        $max = min(self::MAX_DISTANCE, max(1, intdiv(count($key), 4)));
        $best = $max + 1;
        $found = [];
        foreach ($this->candidates as $candidate => $aliases) {
            $distance = $max + 1;
            foreach ($aliases as $alias) {
                $distance = min($distance, self::distance($key, $alias, $max));
            }
            if ($distance < $best) {
                $best = $distance;
                $found = [];
            }
            if ($distance === $best && $distance <= $max) {
                $found[] = (string) $candidate;
            }
        }
        usort($found, strnatcasecmp(...));

        return array_slice($found, 0, self::MAX_SUGGESTIONS);
    }

    /**
     * Satz für die Meldung der Zeile, z. B. „Meinten Sie „HLF 20“?“; leer ohne Vorschlag.
     *
     * @param list<string> $suggestions
     */
    public static function sentence(array $suggestions): string
    {
        if ($suggestions === []) {
            return '';
        }
        $quoted = array_map(static fn (string $s): string => '„' . $s . '“', $suggestions);
        $last = array_pop($quoted);

        return sprintf('Meinten Sie %s?', $quoted === [] ? $last : implode(', ', $quoted) . ' oder ' . $last);
    }

    /**
     * Vergleichsform: klein, ohne Leerraum, ä/ö/ü/ß als ae/oe/ue/ss (ohne intl nur diese).
     */
    public static function key(string $value): string
    {
        $value = mb_strtolower((string) preg_replace('/\s+/u', '', $value));

        return strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'ẞ' => 'ss']);
    }

    /**
     * Levenshtein-Abstand zweier Zeichenlisten; über $max hinaus nur „$max + 1“.
     *
     * @param list<string> $a
     * @param list<string> $b
     */
    public static function distance(array $a, array $b, int $max): int
    {
        if (abs(count($a) - count($b)) > $max) {
            return $max + 1;
        }
        $previous = range(0, count($b));
        foreach ($a as $i => $charA) {
            $current = [$i + 1];
            $rowMin = $i + 1;
            foreach ($b as $j => $charB) {
                $current[$j + 1] = min(
                    $previous[$j + 1] + 1,
                    $current[$j] + 1,
                    $previous[$j] + ($charA === $charB ? 0 : 1),
                );
                $rowMin = min($rowMin, $current[$j + 1]);
            }
            if ($rowMin > $max) {
                return $max + 1;
            }
            $previous = $current;
        }

        return min($previous[count($b)], $max + 1);
    }
}
