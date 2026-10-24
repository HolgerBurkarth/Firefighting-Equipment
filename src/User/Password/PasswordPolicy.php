<?php

declare(strict_types=1);

namespace FirefightingEquipment\User\Password;

/**
 * Regeln für neue Passwörter: 10 bis 128 Zeichen, keine Komplexitätspflicht, nicht auf der
 * Sperrliste, kein Teil von E-Mail, Name oder Stadt. Das Passwort wird nicht getrimmt.
 *
 * Verglichen wird in einer Vergleichsform (fold()): zerlegte Umlaute werden zusammengesetzt,
 * danach Case-Folding (Groß-/Kleinschreibung egal, ß = ss). Ohne intl gibt es keine vollständige
 * Unicode-Normalisierung; andere zerlegte Zeichen bleiben zerlegt.
 */
final readonly class PasswordPolicy
{
    public const int MIN_LENGTH = 10;
    public const int MAX_LENGTH = 128;

    /** Kürzere Teile persönlicher Daten werden nicht geprüft, damit z. B. „Li“ oder „Bad“ keinen Fehlalarm auslösen. */
    public const int MIN_FRAGMENT_LENGTH = 4;

    /**
     * Allgemeine Wörter in Stadtnamen und Kürzeln, die nicht als persönliche Daten gelten
     * (in der Vergleichsform). Ortsname und vollständiges Kürzel bleiben gesperrt.
     */
    public const array TENANT_STOPWORDS = [
        'feuerwehr', 'feuerwehren', 'freiwillige', 'freiwilligen', 'berufsfeuerwehr', 'werkfeuerwehr',
        'jugendfeuerwehr', 'ortsfeuerwehr', 'ortswehr', 'stadt', 'gemeinde', 'markt', 'abteilung',
        'löschzug', 'loeschzug', 'löschgruppe', 'loeschgruppe', 'löschbezirk', 'loeschbezirk',
        'feuerwache', 'wache', 'ff', 'ffw',
    ];

    private const array DECOMPOSED_UMLAUTS = [
        "a\u{0308}" => 'ä', "o\u{0308}" => 'ö', "u\u{0308}" => 'ü',
        "A\u{0308}" => 'Ä', "O\u{0308}" => 'Ö', "U\u{0308}" => 'Ü',
    ];

    public function __construct(private PasswordBlocklist $blocklist)
    {
    }

    public static function default(): self
    {
        return new self(PasswordBlocklist::fromFile(PasswordBlocklist::DEFAULT_PATH));
    }

    /**
     * @throws WeakPasswordException mit deutscher Meldung, die das Passwort nie enthält
     */
    public function check(#[\SensitiveParameter] string $password, PasswordContext $context): void
    {
        if (!mb_check_encoding($password, 'UTF-8')) {
            throw new WeakPasswordException('Das Passwort ist kein gültiger UTF-8-Text.');
        }

        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            throw new WeakPasswordException(sprintf('Das Passwort muss mindestens %d Zeichen lang sein.', self::MIN_LENGTH));
        }
        if ($length > self::MAX_LENGTH) {
            throw new WeakPasswordException(sprintf('Das Passwort darf höchstens %d Zeichen lang sein.', self::MAX_LENGTH));
        }

        if (preg_match('/\p{Cc}/u', $password) === 1) {
            throw new WeakPasswordException(
                'Das Passwort darf keine Steuerzeichen (z. B. Zeilenumbrüche oder Tabulatoren) enthalten.',
            );
        }

        $folded = self::fold($password);
        // Grundwort mit angehängten Ziffern oder Sonderzeichen, z. B. „Feuerwehr2025!“.
        $base = (string) preg_replace('/[^\p{L}]+\z/u', '', $folded);
        if ($this->blocklist->contains($folded) || ($base !== '' && $this->blocklist->contains($base))) {
            throw new WeakPasswordException('Dieses Passwort ist zu verbreitet und leicht zu erraten. Bitte ein anderes wählen.');
        }

        foreach (self::personalFragments($context) as $fragment) {
            if (str_contains($folded, $fragment)) {
                throw new WeakPasswordException(
                    'Das Passwort darf keine Teile der E-Mail-Adresse, des Namens oder des Stadtnamens enthalten.',
                );
            }
        }
    }

    /**
     * Vergleichsform: zerlegte Umlaute zusammensetzen, dann Case-Folding (ß wird zu ss).
     */
    public static function fold(#[\SensitiveParameter] string $text): string
    {
        return mb_convert_case(strtr($text, self::DECOMPOSED_UMLAUTS), MB_CASE_FOLD);
    }

    /**
     * Teile persönlicher Daten in der Vergleichsform, die nicht im Passwort vorkommen dürfen:
     * lokaler Teil der E-Mail (ganz und in Wörtern), Wörter der Namen, Kürzel (ganz und in Wörtern)
     * und Wörter des Stadtnamens ohne allgemeine Wörter. Nur Teile mit mindestens 4 Zeichen.
     *
     * @return list<string>
     */
    public static function personalFragments(PasswordContext $context): array
    {
        $fragments = [];

        if ($context->email !== null) {
            $localPart = self::fold($context->email->localPart());
            $fragments = [$localPart, ...self::words($localPart)];
        }

        foreach ($context->names as $name) {
            array_push($fragments, ...self::words(self::fold($name->value)));
        }

        if ($context->tenant !== null) {
            $slug = $context->tenant->slug->value;
            $fragments[] = $slug;
            $tenantWords = [...self::words(self::fold($context->tenant->name->value)), ...self::words($slug)];
            array_push($fragments, ...array_diff($tenantWords, self::TENANT_STOPWORDS));
        }

        $fragments = array_filter($fragments, static fn (string $f): bool => mb_strlen($f) >= self::MIN_FRAGMENT_LENGTH);

        return array_values(array_unique($fragments));
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        return preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
