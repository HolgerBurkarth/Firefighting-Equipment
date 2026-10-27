<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Unit\Tenant;

use FirefightingEquipment\Tenant\TenantSlug;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Die Regel für das Stadt-Kürzel steht in README und CLAUDE.md für das Frontend; beide müssen zu
 * TenantSlug passen (Ausdruck, Höchstlänge, reservierte Kürzel).
 */
#[CoversNothing]
final class SlugRulesDocumentationTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function documents(): iterable
    {
        yield 'README' => ['README.md'];
        yield 'CLAUDE.md' => ['CLAUDE.md'];
    }

    #[DataProvider('documents')]
    public function testDocumentStatesSlugRules(string $file): void
    {
        $text = file_get_contents(dirname(__DIR__, 3) . '/' . $file);
        self::assertIsString($text);

        self::assertStringContainsString('`' . self::documentedPattern() . '`', $text);
        self::assertMatchesRegularExpression(sprintf('/höchstens %d Zeichen/u', TenantSlug::MAX_LENGTH), $text);
        foreach (TenantSlug::RESERVED as $reserved) {
            self::assertMatchesRegularExpression(sprintf('/reserviert[^\n]*`%s`/iu', preg_quote($reserved, '/')), $text);
        }
    }

    public function testDocumentedPatternBehavesLikeTenantSlug(): void
    {
        $documented = '/' . self::documentedPattern() . '/';

        foreach (['a', 'bad-duerrheim', 'stadt-2', '123', 'service-stadt'] as $valid) {
            self::assertSame(1, preg_match($documented, $valid), $valid);
            self::assertSame($valid, TenantSlug::fromString($valid)->value);
        }
        foreach (['-a', 'a-', 'a--b', 'A', 'tübingen', 'a_b', 'a b'] as $invalid) {
            self::assertSame(0, preg_match($documented, $invalid), $invalid);
        }
    }

    /**
     * TenantSlug::PATTERN ohne Begrenzer und Modifikator (Schreibweise für das Frontend).
     */
    private static function documentedPattern(): string
    {
        self::assertSame(1, preg_match('#^/(.+)/D$#', TenantSlug::PATTERN, $m));

        return $m[1];
    }
}
