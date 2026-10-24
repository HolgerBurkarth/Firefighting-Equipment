<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\User\Password;

use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\User\EmailAddress;
use FirefightingEquipment\User\Password\PasswordBlocklist;
use FirefightingEquipment\User\Password\PasswordContext;
use FirefightingEquipment\User\Password\PasswordPolicy;
use FirefightingEquipment\User\Password\WeakPasswordException;
use FirefightingEquipment\User\PersonName;
use FirefightingEquipment\User\UserException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordPolicy::class)]
#[CoversClass(PasswordContext::class)]
#[UsesClass(PasswordBlocklist::class)]
#[UsesClass(WeakPasswordException::class)]
#[UsesClass(UserException::class)]
#[UsesClass(EmailAddress::class)]
#[UsesClass(PersonName::class)]
#[UsesClass(Tenant::class)]
#[UsesClass(TenantName::class)]
#[UsesClass(TenantSlug::class)]
final class PasswordPolicyTest extends TestCase
{
    private const string TOO_SHORT = 'Das Passwort muss mindestens 10 Zeichen lang sein.';
    private const string TOO_LONG = 'Das Passwort darf höchstens 128 Zeichen lang sein.';
    private const string COMMON = 'Dieses Passwort ist zu verbreitet und leicht zu erraten. Bitte ein anderes wählen.';
    private const string PERSONAL = 'Das Passwort darf keine Teile der E-Mail-Adresse, des Namens oder des Stadtnamens enthalten.';

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedLengths(): iterable
    {
        yield '10 Zeichen' => ['korrektpfe'];
        yield '10 Umlaute' => [str_repeat('ä', 10)];
        yield '10 Emojis' => [str_repeat('🚒', 10)];
        yield '128 Zeichen' => [str_repeat('ö', 128)];
        yield 'Leerzeichen am Rand zählen mit' => ['  korrekt  '];
    }

    #[DataProvider('acceptedLengths')]
    public function testAcceptsLength(string $password): void
    {
        self::policy()->check($password, new PasswordContext());

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedFormats(): iterable
    {
        yield 'leer' => ['', self::TOO_SHORT];
        yield '9 Zeichen' => ['korrektpf', self::TOO_SHORT];
        yield '9 Umlaute (18 Byte)' => [str_repeat('ä', 9), self::TOO_SHORT];
        yield '129 Zeichen' => [str_repeat('ö', 129), self::TOO_LONG];
        yield 'kein UTF-8' => ["korrektes \xFC Pferd", 'Das Passwort ist kein gültiger UTF-8-Text.'];
        yield 'Tabulator' => ["korrektes\tPferd", 'Das Passwort darf keine Steuerzeichen'];
        yield 'Zeilenumbruch am Ende' => ["korrektesPferd\n", 'Das Passwort darf keine Steuerzeichen'];
    }

    #[DataProvider('rejectedFormats')]
    public function testRejectsFormat(string $password, string $message): void
    {
        $this->expectException(WeakPasswordException::class);
        $this->expectExceptionMessageIsOrContains($message);

        self::policy()->check($password, new PasswordContext());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function commonPasswords(): iterable
    {
        yield 'genau ein Eintrag' => ['feuerwehr112'];
        yield 'Groß-/Kleinschreibung' => ['FeuerWehr112'];
        yield 'nur Ziffern' => ['1234567890'];
        yield 'Tastaturfolge' => ['QwErTzUiOp'];
        yield 'Grundwort mit Jahreszahl und Zeichen' => ['Feuerwehr2025!'];
        yield 'Grundwort mit Ausrufezeichen' => ['Passwort!!!!'];
        yield 'Grundwort mit Leerzeichen und Ziffern' => ['feuerwehr 112 112'];
        yield 'ß als ss' => ['FUSSBALL2024'];
        yield 'ß im Passwort' => ['Fußball2024'];
        yield 'zerlegter Umlaut' => ["Mu\u{0308}nchen1860"];
        yield 'Wahlspruch mit Leerzeichen' => ['Gott zur Ehr dem Nächsten zur Wehr'];
        yield 'englisch' => ['Password123!'];
    }

    #[DataProvider('commonPasswords')]
    public function testRejectsCommonPassword(string $password): void
    {
        $this->expectException(WeakPasswordException::class);
        $this->expectExceptionMessageIsOrContains(self::COMMON);

        PasswordPolicy::default()->check($password, new PasswordContext());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uncommonPasswords(): iterable
    {
        yield 'Satz' => ['Unsere Feuerwehr fährt rot'];
        yield 'Grundwort mit weiteren Buchstaben' => ['Feuerwehrschlauch2025'];
        yield 'Ziffern vor dem Grundwort' => ['2025Feuerwehr'];
        yield 'zufällig' => ['k7#Rq!x2Lm9z'];
    }

    #[DataProvider('uncommonPasswords')]
    public function testAcceptsUncommonPassword(string $password): void
    {
        PasswordPolicy::default()->check($password, new PasswordContext());

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function personalPasswords(): iterable
    {
        yield 'lokaler Teil der E-Mail' => ['anna.schmidt!'];
        yield 'Wort aus der E-Mail' => ['Schmidtchen-Schleicher'];
        yield 'Wort aus dem Namen' => ['Hallo Annabell 7'];
        yield 'Anzeigename' => ['Brandmaus und Löwe'];
        yield 'Ortsname mit Jahreszahl' => ['Immendingen2025'];
        yield 'Ortsname mitten im Satz' => ['wir in IMMENDINGEN rocken'];
        yield 'Kürzel als Wort' => ['Ein Tag in Duerrheim'];
        yield 'Kürzel ganz' => ['xx-bad-duerrheim-xx'];
    }

    #[DataProvider('personalPasswords')]
    public function testRejectsPersonalData(string $password): void
    {
        $context = str_contains(PasswordPolicy::fold($password), 'duerrheim')
            ? self::context(self::tenant('bad-duerrheim', 'Freiwillige Feuerwehr Bad Dürrheim'))
            : self::context(self::tenant('immendingen', 'Feuerwehr Immendingen'));

        $this->expectException(WeakPasswordException::class);
        $this->expectExceptionMessageIsOrContains(self::PERSONAL);

        self::policy()->check($password, $context);
    }

    public function testGeneralWordsInTenantNameAreNotPersonalData(): void
    {
        $policy = PasswordPolicy::default();

        $policy->check('Unsere Feuerwehr fährt rot', self::context(self::tenant('immendingen', 'Feuerwehr Immendingen')));
        $policy->check(
            'Freiwillige Stadt-Gemeinde',
            self::context(self::tenant('ff-tuttlingen', 'Freiwillige Feuerwehr Stadt Tuttlingen, Abteilung Möhringen')),
        );

        $this->expectException(WeakPasswordException::class);
        $this->expectExceptionMessageIsOrContains(self::PERSONAL);
        $policy->check('Immendingen2025', self::context(self::tenant('immendingen', 'Feuerwehr Immendingen')));
    }

    public function testShortFragmentsAreIgnored(): void
    {
        $context = new PasswordContext(
            EmailAddress::fromString('li@example.org'),
            [PersonName::fromString('Li Wu')],
            self::tenant('bad-au', 'Feuerwehr Bad Au'),
        );

        self::assertSame(['bad-au'], PasswordPolicy::personalFragments($context));
        self::policy()->check('Ein Bad in Au mit Li und Wu', $context);
    }

    public function testPersonalFragments(): void
    {
        self::assertSame(
            ['anna.schmidt', 'anna', 'schmidt', 'annabell', 'krüger', 'brandmaus', 'immendingen'],
            PasswordPolicy::personalFragments(self::context(self::tenant('immendingen', 'Feuerwehr Immendingen'))),
        );
        self::assertSame(
            ['ff-moehringen-sued', 'stadtteil', 'möhringen', 'moehringen', 'sued'],
            PasswordPolicy::personalFragments(new PasswordContext(
                tenant: self::tenant('ff-moehringen-sued', 'Feuerwehr STADT Stadtteil Möhringen'),
            )),
        );
    }

    public function testFold(): void
    {
        self::assertSame('strasse münchen', PasswordPolicy::fold("STRAẞE Mu\u{0308}NCHEN"));
        self::assertSame(PasswordPolicy::fold('Löwe'), PasswordPolicy::fold("Lo\u{0308}we"));
    }

    /**
     * @return iterable<string, array{string, PasswordContext}>
     */
    public static function rejectedPasswords(): iterable
    {
        yield 'zu kurz' => ['geheim42', new PasswordContext()];
        yield 'zu lang' => [str_repeat('Brandmaus', 15), new PasswordContext()];
        yield 'Steuerzeichen' => ["Brand\tmaus12345", new PasswordContext()];
        yield 'Sperrliste' => ['Feuerwehr2025!', new PasswordContext()];
        yield 'persönlich' => ['Annabell1234567', self::context(null)];
    }

    #[DataProvider('rejectedPasswords')]
    public function testMessageAndTraceNeverContainPassword(string $password, PasswordContext $context): void
    {
        try {
            PasswordPolicy::default()->check($password, $context);
            self::fail('WeakPasswordException erwartet.');
        } catch (WeakPasswordException $e) {
            self::assertStringNotContainsString($password, $e->getMessage());
            self::assertStringNotContainsString($password, print_r($e->getTrace(), true));
            self::assertStringNotContainsString($password, $e->getTraceAsString());
        }
    }

    private static function policy(): PasswordPolicy
    {
        return new PasswordPolicy(PasswordBlocklist::fromEntries([]));
    }

    private static function context(?Tenant $tenant): PasswordContext
    {
        return new PasswordContext(
            EmailAddress::fromString('Anna.Schmidt@example.org'),
            [PersonName::fromString('Annabell Krüger'), PersonName::fromString('Brandmaus')],
            $tenant,
        );
    }

    private static function tenant(string $slug, string $name): Tenant
    {
        $now = new \DateTimeImmutable('2026-01-01 12:00:00', new \DateTimeZone('UTC'));

        return new Tenant(1, TenantSlug::fromString($slug), TenantName::fromString($name), true, $now, $now);
    }
}
