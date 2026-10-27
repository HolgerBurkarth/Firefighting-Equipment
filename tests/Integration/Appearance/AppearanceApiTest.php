<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Appearance;

use FirefightingEquipment\Api\V1\Appearance\AccentColorHandler;
use FirefightingEquipment\Api\V1\Appearance\AppearanceJson;
use FirefightingEquipment\Api\V1\Appearance\ImageHandler;
use FirefightingEquipment\Api\V1\Appearance\ManifestHandler;
use FirefightingEquipment\Api\V1\Appearance\TenantLogoHandler;
use FirefightingEquipment\Api\V1\TenantHandler;
use FirefightingEquipment\Http\CacheableResponse;
use FirefightingEquipment\Http\JsonResponse;
use FirefightingEquipment\Tenant\Logo\DefaultAppIcon;
use FirefightingEquipment\Tenant\Logo\LogoSize;
use FirefightingEquipment\Tenant\TenantAppearanceRepository;
use FirefightingEquipment\Tests\Integration\Auth\AuthTestCase;
use FirefightingEquipment\Tests\Support\TestPng;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Erscheinungsbild über die ganze Anwendung: Akzentfarbe, Logo, GET tenant, Auslieferung der Bilder
 * mit Caching, Web-App-Manifest, Mandantentrennung und Body-Grenze. Rechte der Admin-Routen prüft
 * AdminRoutesAccessTest.
 */
#[CoversClass(AccentColorHandler::class)]
#[CoversClass(AppearanceJson::class)]
#[CoversClass(ImageHandler::class)]
#[CoversClass(ManifestHandler::class)]
#[CoversClass(TenantLogoHandler::class)]
#[CoversClass(TenantHandler::class)]
#[CoversClass(TenantAppearanceRepository::class)]
#[CoversClass(CacheableResponse::class)]
final class AppearanceApiTest extends AuthTestCase
{
    private const string AREA = '/firefighting/immendingen/';

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account('immendingen', 'admin@example.org', ['role' => 'admin']);
        $this->admin = $this->loginCookie('immendingen', 'admin@example.org');
    }

    public function testTenantWithoutAppearanceShowsDefaults(): void
    {
        $response = $this->send('GET', self::url('immendingen', 'tenant'));

        self::assertSame([
            'slug' => 'immendingen',
            'name' => 'Feuerwehr immendingen',
            'accent_color' => '#b71c1c',
            'accent_color_is_default' => true,
            'logo_url' => null,
            'logo_urls' => null,
        ], self::json($response));
    }

    public function testSetAndResetAccentColor(): void
    {
        $this->pdo()->exec("UPDATE fe_tenants SET updated_at = '2026-01-01 00:00:00'");

        $response = $this->setColor(' #A33 ');
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertSame('#aa3333', self::json($response)['accent_color']);
        self::assertFalse(self::json($response)['accent_color_is_default']);
        self::assertSame(self::json($response), self::json($this->send('GET', self::url('immendingen', 'tenant'))));
        self::assertSame('#aa3333', $this->column('SELECT accent_color FROM fe_tenants WHERE slug = ?', 'immendingen'));
        self::assertNotSame('2026-01-01 00:00:00', $this->column('SELECT updated_at FROM fe_tenants WHERE slug = ?', 'immendingen'));
        self::assertNull($this->column('SELECT accent_color FROM fe_tenants WHERE slug = ?', 'tuttlingen'), 'andere Stadt unberührt');

        $response = $this->setColor(null);
        self::assertSame('#b71c1c', self::json($response)['accent_color']);
        self::assertTrue(self::json($response)['accent_color_is_default']);
        self::assertNull($this->column('SELECT accent_color FROM fe_tenants WHERE slug = ?', 'immendingen'));
    }

    public function testContrastBoundary(): void
    {
        self::assertSame(200, $this->setColor('#EC1400')->statusCode(), 'Kontrast 4,50003');

        $response = $this->setColor('#ea1c05');
        self::assertError(400, 'invalid_request', $response);
        self::assertSame(
            'Weiße Schrift auf #EA1C05 erreicht nur einen Kontrast von 4,49:1; nötig sind mindestens 4,5:1. Bitte eine dunklere Farbe wählen.',
            self::json($response)['message'],
        );
        self::assertSame(['contrast' => 4.49, 'min_contrast' => 4.5], self::json($response)['details']);
        self::assertSame('#ec1400', $this->column('SELECT accent_color FROM fe_tenants WHERE slug = ?', 'immendingen'), 'unverändert');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidColorBodies(): iterable
    {
        yield 'ohne #' => [['accent_color' => 'b71c1c'], 'Die Akzentfarbe muss im Format #RRGGBB angegeben werden (z. B. #B71C1C).'];
        yield 'Farbname' => [['accent_color' => 'red'], 'Die Akzentfarbe muss im Format #RRGGBB angegeben werden (z. B. #B71C1C).'];
        yield 'Zahl' => [['accent_color' => 0xB71C1C], 'Das Feld "accent_color" ist kein Text.'];
        yield 'Feld fehlt' => [[], 'Das Feld "accent_color" fehlt (null = Standardfarbe).'];
        yield 'unbekanntes Feld' => [['accent_color' => '#000000', 'logo' => 'x'], 'Das Feld "logo" ist hier nicht vorgesehen (möglich: accent_color).'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidColorBodies')]
    public function testInvalidColorRequest(array $body, string $message): void
    {
        $response = $this->send('POST', self::url('immendingen', 'tenant/appearance'), $body, $this->admin);

        self::assertError(400, 'invalid_request', $response);
        self::assertSame($message, self::json($response)['message']);
        self::assertArrayNotHasKey('details', self::json($response));
        self::assertNull($this->column('SELECT accent_color FROM fe_tenants WHERE slug = ?', 'immendingen'));
    }

    public function testUploadLogo(): void
    {
        $this->pdo()->exec("UPDATE fe_tenants SET updated_at = '2026-01-01 00:00:00'");
        $upload = TestPng::withChunk(TestPng::create(), 'tEXt', "Comment\0geheim");

        $response = $this->uploadLogo($upload);

        self::assertSame(200, $response->statusCode(), $response->body());
        $row = $this->pdo()->query('SELECT * FROM fe_tenant_logos')->fetch();
        self::assertIsArray($row);
        $version = $row['version'];
        self::assertSame($this->tenant('immendingen')->id, $row['tenant_id']);
        self::assertSame($this->userId('admin@example.org'), $row['updated_by']);
        $json = self::json($response);
        self::assertSame(self::AREA . 'api/v1/tenant/logo/512?v=' . $version, $json['logo_url']);
        self::assertSame([
            '512' => self::AREA . 'api/v1/tenant/logo/512?v=' . $version,
            '192' => self::AREA . 'api/v1/tenant/logo/192?v=' . $version,
            '180' => self::AREA . 'api/v1/tenant/logo/180?v=' . $version,
        ], $json['logo_urls']);
        self::assertSame($json, self::json($this->send('GET', self::url('immendingen', 'tenant'))));
        self::assertNotSame('2026-01-01 00:00:00', $this->column('SELECT updated_at FROM fe_tenants WHERE slug = ?', 'immendingen'));

        foreach (LogoSize::cases() as $size) {
            $png = $row[$size->column()];
            self::assertNotSame($upload, $png, 'neu erzeugt, nicht die hochgeladene Datei');
            self::assertStringNotContainsString('geheim', $png);
            self::assertSame($size->value, getimagesizefromstring($png)[0] ?? null);
        }
    }

    public function testReplaceAndDeleteLogo(): void
    {
        $first = self::json($this->uploadLogo(TestPng::create()))['logo_url'];
        $second = self::json($this->uploadLogo(TestPng::create(red: 120)))['logo_url'];

        self::assertNotSame($first, $second, 'neue Version');
        self::assertSame(1, $this->countRows('fe_tenant_logos'));

        // Die alte Adresse liefert das aktuelle Bild, aber nicht dauerhaft zwischenspeicherbar.
        $old = $this->sendAny('GET', $first);
        self::assertInstanceOf(CacheableResponse::class, $old);
        self::assertSame('no-cache', $old->headers()['Cache-Control']);
        self::assertSame([0, 0x780000], TestPng::pixel($old->body(), 256, 256));

        $response = $this->send('DELETE', self::url('immendingen', 'tenant/logo'), [], $this->admin);
        self::assertSame(200, $response->statusCode(), $response->body());
        self::assertNull(self::json($response)['logo_url']);
        self::assertNull(self::json($response)['logo_urls']);
        self::assertSame(0, $this->countRows('fe_tenant_logos'));
        self::assertError(404, 'not_found', $this->send('GET', $second));

        $again = $this->send('DELETE', self::url('immendingen', 'tenant/logo'), [], $this->admin);
        self::assertSame(200, $again->statusCode(), 'wiederholbar');
    }

    /**
     * @return iterable<string, array{\Closure(): string, string, array<string, int>|null}>
     */
    public static function invalidLogos(): iterable
    {
        yield 'keine PNG' => [static fn (): string => base64_encode('GIF89a…'), 'Die Datei ist keine PNG-Datei. Bitte das Logo als PNG hochladen.', null];
        yield 'kein Base64' => [static fn (): string => 'kein*base64', 'Das Feld "file" muss die PNG-Datei als Base64 enthalten.', null];
        yield 'nicht quadratisch' => [static fn (): string => base64_encode(TestPng::create(512, 600)), 'Das Logo muss quadratisch sein (hochgeladen: 512 × 600 px).', ['width' => 512, 'height' => 600]];
        yield 'zu klein' => [static fn (): string => base64_encode(TestPng::create(256)), 'Das Logo muss genau 512 × 512 px groß sein (hochgeladen: 256 × 256 px).', ['width' => 256, 'height' => 256, 'min_edge' => 512, 'max_edge' => 512]];
        yield 'zu groß (Pixel)' => [static fn (): string => base64_encode(TestPng::create(1024)), 'Das Logo muss genau 512 × 512 px groß sein (hochgeladen: 1024 × 1024 px).', ['width' => 1024, 'height' => 1024, 'min_edge' => 512, 'max_edge' => 512]];
        yield 'angehängte Daten' => [static fn (): string => base64_encode(TestPng::create() . "PK\x03\x04"), 'Nach dem Ende der PNG-Datei folgen weitere Daten. Bitte die Datei mit einem Bildprogramm neu speichern.', null];
        $bytes = strlen(TestPng::create()) + 12 + 520_000;
        yield 'zu groß (Datei)' => [
            static fn (): string => base64_encode(TestPng::withChunk(TestPng::create(), 'tEXt', str_repeat('x', 520_000))),
            sprintf('Das Logo ist zu groß (%s KB); erlaubt sind höchstens 500 KB.', number_format($bytes / 1024, 0, ',', '.')),
            ['bytes' => $bytes, 'max_bytes' => 512_000],
        ];
    }

    /**
     * @param \Closure(): string      $file
     * @param array<string, int>|null $details
     */
    #[DataProvider('invalidLogos')]
    public function testInvalidLogoIsRejectedAndNothingStored(\Closure $file, string $message, ?array $details): void
    {
        $response = $this->send('POST', self::url('immendingen', 'tenant/logo'), ['file' => $file()], $this->admin);

        self::assertError(400, 'invalid_request', $response);
        self::assertSame($message, self::json($response)['message']);
        self::assertSame($details, self::json($response)['details'] ?? null);
        self::assertSame(0, $this->countRows('fe_tenant_logos'));
    }

    public function testUnknownFieldInLogoUploadIsRejected(): void
    {
        $response = $this->send('POST', self::url('immendingen', 'tenant/logo'), ['file' => base64_encode(TestPng::create()), 'size' => 512], $this->admin);

        self::assertError(400, 'invalid_request', $response);
        self::assertSame(0, $this->countRows('fe_tenant_logos'));
    }

    public function testLogoDeliveryWithCaching(): void
    {
        $url = self::json($this->uploadLogo(TestPng::create()))['logo_urls']['192'];
        $version = substr($url, -16);
        $stored = $this->column('SELECT png_192 FROM fe_tenant_logos');

        // Ein mitgesendetes Cookie ändert nichts: öffentliche Route, keine Sitzung, kein Set-Cookie.
        $response = $this->image('GET', $url, cookie: $this->admin);
        self::assertSame(200, $response->statusCode());
        self::assertSame($stored, $response->body());
        self::assertSame([
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($stored),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"' . $version . '-192"',
            'X-Content-Type-Options' => 'nosniff',
        ], $response->headers());

        $withoutVersion = $this->image('GET', self::AREA . 'api/v1/tenant/logo/192');
        self::assertSame('no-cache', $withoutVersion->headers()['Cache-Control']);
        self::assertSame($stored, $withoutVersion->body());
        self::assertSame('no-cache', $this->image('GET', self::AREA . 'api/v1/tenant/logo/192?v=0000000000000000')->headers()['Cache-Control']);

        $notModified = $this->image('GET', $url, headers: ['if-none-match' => '"' . $version . '-192"']);
        self::assertSame(304, $notModified->statusCode());
        self::assertSame('', $notModified->body());
        self::assertSame('"' . $version . '-192"', $notModified->headers()['ETag']);
        self::assertSame(200, $this->image('GET', $url, headers: ['if-none-match' => '"' . $version . '-512"'])->statusCode(), 'ETag einer anderen Größe');

        foreach (['tenant/logo/200', 'tenant/logo/1024', 'tenant/logo/0', 'tenant/logo/abc', 'app-icon/64'] as $rest) {
            $error = $this->send('GET', self::url('immendingen', $rest));
            self::assertError(404, 'not_found', $error);
            self::assertSame('no-store', $error->headers()['Cache-Control'], $rest);
        }
    }

    public function testDefaultAppIcon(): void
    {
        $version = DefaultAppIcon::version();

        $response = $this->image('GET', self::url('immendingen', 'app-icon/512?v=' . $version));

        self::assertSame(DefaultAppIcon::png(LogoSize::Large), $response->body());
        self::assertSame('public, max-age=31536000, immutable', $response->headers()['Cache-Control']);
        self::assertSame('"' . $version . '-512"', $response->headers()['ETag']);
        self::assertSame('image/png', $response->headers()['Content-Type']);
        self::assertSame('no-cache', $this->image('GET', self::url('immendingen', 'app-icon/180'))->headers()['Cache-Control']);
    }

    public function testManifestWithoutAppearance(): void
    {
        $response = $this->image('GET', self::AREA . 'manifest.webmanifest');
        $icon = DefaultAppIcon::version();

        self::assertSame(200, $response->statusCode());
        self::assertSame('application/manifest+json; charset=utf-8', $response->headers()['Content-Type']);
        self::assertSame('no-cache', $response->headers()['Cache-Control']);
        self::assertSame('nosniff', $response->headers()['X-Content-Type-Options']);
        self::assertArrayNotHasKey('Set-Cookie', $response->headers());
        self::assertSame([
            'id' => self::AREA,
            'name' => 'Feuerwehr immendingen – Geräteverwaltung',
            'short_name' => 'FF-Geräte',
            'lang' => 'de',
            'dir' => 'ltr',
            'start_url' => self::AREA,
            'scope' => self::AREA,
            'display' => 'standalone',
            'theme_color' => '#b71c1c',
            'background_color' => '#f3f4f6',
            'icons' => [
                ['src' => self::AREA . 'api/v1/app-icon/192?v=' . $icon, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => self::AREA . 'api/v1/app-icon/512?v=' . $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ],
        ], json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR));

        $etag = $response->headers()['ETag'];
        self::assertSame(304, $this->image('GET', self::AREA . 'manifest.webmanifest', headers: ['if-none-match' => $etag])->statusCode());
    }

    public function testManifestWithLogoAndColor(): void
    {
        $before = $this->image('GET', self::AREA . 'manifest.webmanifest')->headers()['ETag'];
        $this->setColor('#1A237E');
        $version = substr(self::json($this->uploadLogo(TestPng::create()))['logo_url'], -16);

        $response = $this->image('GET', self::AREA . 'manifest.webmanifest');
        $manifest = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertNotSame($before, $response->headers()['ETag'], 'neuer Inhalt, neuer ETag');
        self::assertSame('#1a237e', $manifest['theme_color']);
        self::assertSame([
            ['src' => self::AREA . 'api/v1/tenant/logo/192?v=' . $version, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => self::AREA . 'api/v1/tenant/logo/512?v=' . $version, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ], $manifest['icons']);
        // Jede Adresse im Manifest ist abrufbar und dauerhaft zwischenspeicherbar.
        foreach ($manifest['icons'] as $icon) {
            self::assertSame('public, max-age=31536000, immutable', $this->image('GET', $icon['src'])->headers()['Cache-Control']);
        }
    }

    public function testTenantsAreSeparated(): void
    {
        $this->setColor('#1A237E');
        $url = self::json($this->uploadLogo(TestPng::create()))['logo_url'];

        $other = self::json($this->send('GET', self::url('tuttlingen', 'tenant')));
        self::assertSame('#b71c1c', $other['accent_color']);
        self::assertNull($other['logo_url']);
        self::assertError(404, 'not_found', $this->send('GET', str_replace('/immendingen/', '/tuttlingen/', $url)));
        $manifest = json_decode($this->image('GET', '/firefighting/tuttlingen/manifest.webmanifest')->body(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('#b71c1c', $manifest['theme_color']);
        self::assertStringStartsWith('/firefighting/tuttlingen/api/v1/app-icon/', $manifest['icons'][0]['src']);
    }

    public function testLockedTenantAnswersWithJsonError(): void
    {
        $this->uploadLogo(TestPng::create());
        $this->pdo()->exec("UPDATE fe_tenants SET active = 0 WHERE slug = 'immendingen'");

        foreach ([self::AREA . 'manifest.webmanifest', self::url('immendingen', 'tenant/logo/512'), self::url('immendingen', 'app-icon/512')] as $url) {
            $response = $this->send('GET', $url);
            self::assertError(403, 'tenant_inactive', $response);
            self::assertSame('no-store', $response->headers()['Cache-Control']);
        }
    }

    public function testRoutesDoNotExistUnderService(): void
    {
        foreach (['/firefighting/service/manifest.webmanifest', self::url(null, 'tenant/logo/512'), self::url(null, 'tenant'), self::url(null, 'tenant/appearance')] as $url) {
            self::assertError(404, 'not_found', $this->send('GET', $url));
        }
    }

    public function testDefaultAppIconAlsoUnderService(): void
    {
        // Symbol der App-Seite des Super-Admins (index.html verweist relativ auf api/v1/app-icon/…).
        $version = DefaultAppIcon::version();

        $response = $this->image('GET', self::url(null, 'app-icon/192?v=' . $version));

        self::assertSame(200, $response->statusCode());
        self::assertSame(DefaultAppIcon::png(LogoSize::Medium), $response->body());
        self::assertSame('public, max-age=31536000, immutable', $response->headers()['Cache-Control']);
        self::assertArrayNotHasKey('Set-Cookie', $response->headers());
        self::assertSame('no-cache', $this->image('GET', self::url(null, 'app-icon/180'))->headers()['Cache-Control']);
        self::assertError(404, 'not_found', $this->send('GET', self::url(null, 'app-icon/64')));
    }

    public function testLargerBodyLimitOnlyForLogoUpload(): void
    {
        $large = json_encode(['accent_color' => '#000000', 'x' => str_repeat('a', 20_000)], JSON_THROW_ON_ERROR);
        foreach ([['POST', 'tenant/appearance'], ['DELETE', 'tenant/logo']] as [$method, $rest]) {
            $response = $this->send($method, self::url('immendingen', $rest), $large, $this->admin);
            self::assertError(400, 'invalid_request', $response);
            self::assertSame('Der Inhalt der Anfrage ist zu groß (höchstens 16384 Byte).', self::json($response)['message'], $rest);
        }

        // 500 KB als Base64 mit CRLF alle 76 Zeichen (ungünstigster Fall) passen unter die Grenze der Route.
        $file = chunk_split(base64_encode(str_repeat("\x89", 512_000)), 76, "\r\n");
        $body = json_encode(['file' => $file], JSON_THROW_ON_ERROR);
        self::assertGreaterThan(715_000, strlen($body));
        $response = $this->send('POST', self::url('immendingen', 'tenant/logo'), $body, $this->admin);
        self::assertSame('Die Datei ist keine PNG-Datei. Bitte das Logo als PNG hochladen.', self::json($response)['message'], 'vom Handler geprüft');

        $tooLarge = json_encode(['file' => str_repeat('A', TenantLogoHandler::MAX_BODY_BYTES)], JSON_THROW_ON_ERROR);
        $response = $this->send('POST', self::url('immendingen', 'tenant/logo'), $tooLarge, $this->admin);
        self::assertSame('Der Inhalt der Anfrage ist zu groß (höchstens 737280 Byte).', self::json($response)['message']);
    }

    private function setColor(?string $color): JsonResponse
    {
        return $this->send('POST', self::url('immendingen', 'tenant/appearance'), ['accent_color' => $color], $this->admin);
    }

    private function uploadLogo(string $png): JsonResponse
    {
        $response = $this->send('POST', self::url('immendingen', 'tenant/logo'), ['file' => base64_encode($png)], $this->admin);
        self::assertSame(200, $response->statusCode(), $response->body());

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    private function image(string $method, string $url, ?string $cookie = null, array $headers = []): CacheableResponse
    {
        $response = $this->sendAny($method, $url, null, $cookie, $headers);
        self::assertInstanceOf(CacheableResponse::class, $response, $response instanceof JsonResponse ? $response->body() : '');
        self::assertArrayNotHasKey('Set-Cookie', $response->headers());

        return $response;
    }

    private function column(string $sql, string ...$params): mixed
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    private function userId(string $email): int
    {
        return (int) $this->column('SELECT id FROM fe_users WHERE email = ?', $email);
    }
}
