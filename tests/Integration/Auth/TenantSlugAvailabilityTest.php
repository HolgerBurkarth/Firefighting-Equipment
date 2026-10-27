<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Auth;

use FirefightingEquipment\Api\V1\Tenants\TenantSlugAvailabilityHandler;
use FirefightingEquipment\Tenant\InvalidTenantValueException;
use FirefightingEquipment\Tenant\TenantSlug;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * GET tenants/slug-available (Super-Admin): gültig und frei, vergeben (auch gesperrte Stadt),
 * ungültig mit Meldung und ggf. Vorschlag. Rechte prüft SuperAdminRoutesAccessTest.
 */
#[CoversClass(TenantSlugAvailabilityHandler::class)]
#[CoversClass(TenantSlug::class)]
#[CoversClass(InvalidTenantValueException::class)]
final class TenantSlugAvailabilityTest extends AuthTestCase
{
    private string $cookie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account(null, 'chef@example.org');
        $this->cookie = $this->loginCookie(null, 'chef@example.org');
    }

    public function testFreeSlugIsAvailable(): void
    {
        self::assertSame([
            'slug' => 'geisingen',
            'valid' => true,
            'available' => true,
            'message' => 'Das Kürzel ist verfügbar.',
            'suggestion' => null,
        ], $this->check('geisingen'));
    }

    public function testExistingSlugIsTaken(): void
    {
        self::assertSame([
            'slug' => 'immendingen',
            'valid' => true,
            'available' => false,
            'message' => 'Das Kürzel ist bereits vergeben.',
            'suggestion' => null,
        ], $this->check('immendingen'));
    }

    public function testSlugOfLockedTenantStaysTaken(): void
    {
        $this->pdo()->exec("UPDATE fe_tenants SET active = 0 WHERE slug = 'tuttlingen'");

        self::assertFalse($this->check('tuttlingen')['available']);
    }

    public function testUppercaseIsInvalidWithSuggestion(): void
    {
        self::assertSame([
            'slug' => 'Bad-Duerrheim',
            'valid' => false,
            'available' => false,
            'message' => 'Das Kürzel darf keine Großbuchstaben enthalten. Gemeint ist vermutlich „bad-duerrheim“.',
            'suggestion' => 'bad-duerrheim',
        ], $this->check('Bad-Duerrheim'));
    }

    /**
     * Der Vorschlag wird nicht auf Verfügbarkeit geprüft (das Frontend fragt ihn erneut ab).
     */
    public function testSuggestionMayBeTaken(): void
    {
        $json = $this->check('Immendingen');

        self::assertSame([false, 'immendingen'], [$json['valid'], $json['suggestion']]);
        self::assertFalse($this->check($json['suggestion'])['available']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidSlugs(): iterable
    {
        yield 'leer' => ['', 'Das Kürzel darf nicht leer sein.'];
        yield 'zu lang' => [str_repeat('a', 51), 'Das Kürzel darf höchstens 50 Zeichen lang sein (angegeben: 51).'];
        yield 'reserviert' => ['service', 'Das Kürzel „service“ ist reserviert.'];
        yield 'reserviert groß' => ['SERVICE', 'Das Kürzel „service“ ist reserviert.'];
        yield 'Umlaut' => ['tübingen', 'Ungültiges Kürzel: erlaubt sind nur Kleinbuchstaben a-z, Ziffern und Bindestrich;'];
        yield 'Großbuchstaben und Umlaut' => ['Tübingen', 'Ungültiges Kürzel'];
        yield 'doppelter Bindestrich' => ['bad--duerrheim', 'Ungültiges Kürzel'];
        yield 'Leerzeichen' => ['bad duerrheim', 'Ungültiges Kürzel'];
    }

    #[DataProvider('invalidSlugs')]
    public function testInvalidSlugWithoutSuggestion(string $slug, string $message): void
    {
        $json = $this->check($slug);

        self::assertSame([$slug, false, false, null], [$json['slug'], $json['valid'], $json['available'], $json['suggestion']]);
        self::assertStringStartsWith($message, $json['message']);
    }

    public function testMissingParameterIsRejected(): void
    {
        self::assertError(400, 'invalid_request', $this->send('GET', self::url(null, 'tenants/slug-available'), cookie: $this->cookie));
        self::assertError(400, 'invalid_request', $this->send('GET', self::url(null, 'tenants/slug-available?slug[]=a'), cookie: $this->cookie));
    }

    /**
     * @return array<string, mixed>
     */
    private function check(string $slug): array
    {
        $response = $this->send('GET', self::url(null, 'tenants/slug-available?slug=' . rawurlencode($slug)), cookie: $this->cookie);
        self::assertSame(200, $response->statusCode(), $response->body());

        return self::json($response);
    }
}
