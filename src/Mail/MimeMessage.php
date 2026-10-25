<?php

declare(strict_types=1);

namespace FirefightingEquipment\Mail;

/**
 * Wandelt eine MailMessage in Kopfzeilen und Text für den Versand (RFC 5322, MIME).
 *
 * - Absender immer "Feuerwehr Geräteverwaltung" <feuerwehr-app@prodad.de>.
 * - Betreff und Namen mit Zeichen außerhalb von ASCII als RFC-2047-encoded-words (UTF-8, Base64),
 *   je Wort höchstens 75 Zeichen, nur an Zeichengrenzen geteilt, durch Leerzeichen getrennt
 *   (nicht gefaltet: mail() ersetzt Zeilenumbrüche im Betreff ohnehin).
 * - Text als text/plain; charset=UTF-8, quoted-printable (auf der Leitung nur ASCII, Zeilen
 *   höchstens 76 Zeichen), Zeilenenden CRLF.
 * - Date (UTC), Message-ID und Auto-Submitted: auto-generated (keine Abwesenheitsnotizen).
 */
final readonly class MimeMessage
{
    public const string FROM_NAME = 'Feuerwehr Geräteverwaltung';
    public const string FROM_ADDRESS = 'feuerwehr-app@prodad.de';
    public const string MESSAGE_ID_DOMAIN = 'prodad.de';

    private const string CRLF = "\r\n";

    /** 45 Byte ergeben 60 Zeichen Base64; mit "=?UTF-8?B?" und "?=" also 72 ≤ 75 Zeichen. */
    private const int ENCODED_WORD_BYTES = 45;

    /**
     * @param array<string, string> $headers alle Kopfzeilen außer To und Subject (übergibt mail() getrennt)
     */
    private function __construct(
        public string $to,
        public string $subject,
        public array $headers,
        public string $body,
    ) {
    }

    public static function build(MailMessage $message, \DateTimeImmutable $date, string $messageId): self
    {
        return new self(
            self::address($message->to->value, $message->toName),
            self::encodeText($message->subject),
            [
                'From' => self::address(self::FROM_ADDRESS, self::FROM_NAME),
                'Date' => $date->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_RFC2822),
                'Message-ID' => $messageId,
                'MIME-Version' => '1.0',
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Transfer-Encoding' => 'quoted-printable',
                'Auto-Submitted' => 'auto-generated',
            ],
            self::encodeBody($message->text),
        );
    }

    public static function newMessageId(): string
    {
        return '<' . bin2hex(random_bytes(16)) . '@' . self::MESSAGE_ID_DOMAIN . '>';
    }

    /**
     * Vollständige Nachricht (mit To und Subject), z. B. für eine .eml-Datei.
     */
    public function toString(): string
    {
        $lines = ['To: ' . $this->to, 'Subject: ' . $this->subject];
        foreach ($this->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode(self::CRLF, $lines) . self::CRLF . self::CRLF . $this->body;
    }

    /**
     * "Name" <adresse> bzw. nur die Adresse. Die Adresse ist bereits geprüft (MailMessage).
     */
    public static function address(string $address, ?string $name): string
    {
        if ($name === null || $name === '') {
            return $address;
        }

        return self::encodePhrase($name) . ' <' . $address . '>';
    }

    /**
     * Anzeigename: reines ASCII als quoted-string, sonst encoded-words.
     */
    public static function encodePhrase(string $name): string
    {
        if (preg_match('/^[\x20-\x7E]*$/D', $name) === 1) {
            return '"' . addcslashes($name, '"\\') . '"';
        }

        return self::encodedWords($name);
    }

    /**
     * Freier Text in einer Kopfzeile (Betreff): reines ASCII bleibt, sonst encoded-words. Text, der
     * selbst wie ein encoded-word aussieht ("=?"), wird ebenfalls kodiert.
     */
    public static function encodeText(string $text): string
    {
        if (preg_match('/^[\x20-\x7E]*$/D', $text) === 1 && !str_contains($text, '=?')) {
            return $text;
        }

        return self::encodedWords($text);
    }

    private static function encodedWords(string $text): string
    {
        $words = [];
        $chunk = '';
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            if ($chunk !== '' && strlen($chunk) + strlen($char) > self::ENCODED_WORD_BYTES) {
                $words[] = $chunk;
                $chunk = '';
            }
            $chunk .= $char;
        }
        if ($chunk !== '') {
            $words[] = $chunk;
        }

        return implode(' ', array_map(static fn (string $w): string => '=?UTF-8?B?' . base64_encode($w) . '?=', $words));
    }

    private static function encodeBody(string $text): string
    {
        $text = (string) preg_replace('/\r\n|\r|\n/', self::CRLF, $text);
        if (!str_ends_with($text, self::CRLF)) {
            $text .= self::CRLF;
        }

        return quoted_printable_encode($text);
    }
}
