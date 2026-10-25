<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail\Smtp;

/**
 * Antwort des SMTP-Servers: dreistelliger Code und Textzeilen (bei mehrzeiligen Antworten alle).
 */
final readonly class SmtpReply
{
    /** Länge des Antworttexts in Fehlermeldungen. */
    private const int MAX_SUMMARY_LENGTH = 200;

    /**
     * @param list<string> $lines Text je Zeile ohne Code und Trennzeichen
     */
    public function __construct(public int $code, public array $lines)
    {
    }

    /**
     * Code und Text für Fehlermeldungen (landen im error_log): Steuerzeichen entfernt, gekürzt und
     * E-Mail-Adressen maskiert, da Server oft den Empfänger zitieren ("<x@y>: Recipient rejected").
     */
    public function summary(): string
    {
        return trim($this->code . ' ' . self::sanitize(implode(' ', $this->lines)));
    }

    /**
     * Freitext (Antwort des Servers, PHP-Warnung) für Fehlermeldungen aufbereiten.
     */
    public static function sanitize(string $text): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $text);
        $text = (string) preg_replace('/[^\s<>()\[\]"\',;:]+@[^\s<>()\[\]"\',;:]+/', '<Adresse>', $text);
        $text = trim((string) preg_replace('/ {2,}/', ' ', $text));

        return strlen($text) > self::MAX_SUMMARY_LENGTH ? mb_strcut($text, 0, self::MAX_SUMMARY_LENGTH, 'UTF-8') . ' …' : $text;
    }

    /**
     * Schlüsselwörter einer EHLO-Antwort (ohne die erste Zeile), groß geschrieben, z. B.
     * ['STARTTLS' => [], 'AUTH' => ['PLAIN', 'LOGIN']]. Die alte Form "AUTH=PLAIN" zählt wie "AUTH PLAIN".
     *
     * @return array<string, list<string>>
     */
    public function extensions(): array
    {
        $extensions = [];
        foreach (array_slice($this->lines, 1) as $line) {
            $words = preg_split('/[\s=]+/', strtoupper(trim($line)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($words === []) {
                continue;
            }
            $keyword = array_shift($words);
            $extensions[$keyword] = array_values(array_unique([...$extensions[$keyword] ?? [], ...$words]));
        }

        return $extensions;
    }
}
