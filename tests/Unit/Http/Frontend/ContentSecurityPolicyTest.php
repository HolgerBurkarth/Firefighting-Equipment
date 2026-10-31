<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Http\Frontend;

use FirefightingEquipment\Http\Frontend\ContentSecurityPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ContentSecurityPolicy::class)]
final class ContentSecurityPolicyTest extends TestCase
{
    public function testHeader(): void
    {
        self::assertSame(
            "default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self'; img-src 'self' blob:; font-src 'self'; "
            . "connect-src 'self'; manifest-src 'self'; worker-src 'self'; object-src 'none'; "
            . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
            ContentSecurityPolicy::header(),
        );
    }

    public function testNeverAllowsInlineOrEval(): void
    {
        foreach (ContentSecurityPolicy::DIRECTIVES as $directive => $sources) {
            self::assertNotSame([], $sources, $directive);
            foreach ($sources as $source) {
                self::assertNotContains($source, ["'unsafe-inline'", "'unsafe-eval'", "'unsafe-hashes'", '*', 'http:', 'https:'], $directive);
            }
        }
    }

    /** Nur das Übersetzen von WebAssembly (Kamera-Scanner), kein eval von JavaScript. */
    public function testScriptsOnlyFromSelfPlusWebAssembly(): void
    {
        self::assertSame(["'self'", "'wasm-unsafe-eval'"], ContentSecurityPolicy::DIRECTIVES['script-src']);
        self::assertSame(["'self'"], ContentSecurityPolicy::DIRECTIVES['worker-src']);
        self::assertSame(["'self'"], ContentSecurityPolicy::DIRECTIVES['connect-src']);
    }

    /** Bilder nur aus gleicher Herkunft und selbst gewählten Dateien (Logo), nie data: oder fremd. */
    public function testImagesOnlyFromSelfAndBlob(): void
    {
        self::assertSame(["'self'", 'blob:'], ContentSecurityPolicy::DIRECTIVES['img-src']);
        self::assertSame(["'self'"], ContentSecurityPolicy::DIRECTIVES['default-src']);
    }

    public function testNoFramingAndNoForeignBase(): void
    {
        self::assertSame(["'none'"], ContentSecurityPolicy::DIRECTIVES['frame-ancestors']);
        self::assertSame(["'none'"], ContentSecurityPolicy::DIRECTIVES['object-src']);
        self::assertSame(["'self'"], ContentSecurityPolicy::DIRECTIVES['base-uri']);
    }
}
