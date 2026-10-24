<?php

declare(strict_types=1);

namespace FirefightingEquipment\Cli;

/**
 * Einfache Texttabelle für die Kommandozeile: Spalten mit zwei Leerzeichen Abstand, Breite nach
 * Zeichen (nicht Bytes) berechnet. Die letzte Spalte wird nicht aufgefüllt.
 */
final class TextTable
{
    /**
     * @param list<list<string>> $rows erste Zeile = Überschrift
     *
     * @return list<string>
     */
    public static function render(array $rows): array
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($cell));
            }
        }

        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $i => $cell) {
                $cells[] = $i === count($row) - 1 ? $cell : mb_str_pad($cell, $widths[$i]);
            }
            $lines[] = implode('  ', $cells);
        }

        return $lines;
    }
}
