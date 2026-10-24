<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tests\Integration\Tenant;

use FirefightingEquipment\Tenant\DuplicateTenantSlugException;
use FirefightingEquipment\Tenant\Tenant;
use FirefightingEquipment\Tenant\TenantException;
use FirefightingEquipment\Tenant\TenantName;
use FirefightingEquipment\Tenant\TenantNotFoundException;
use FirefightingEquipment\Tenant\TenantRepository;
use FirefightingEquipment\Tenant\TenantSlug;
use FirefightingEquipment\Tests\Integration\DomainTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(TenantRepository::class)]
#[UsesClass(DuplicateTenantSlugException::class)]
#[UsesClass(Tenant::class)]
#[UsesClass(TenantException::class)]
#[UsesClass(TenantName::class)]
#[UsesClass(TenantNotFoundException::class)]
#[UsesClass(TenantSlug::class)]
final class TenantRepositoryTest extends DomainTestCase
{
    private TenantRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new TenantRepository($this->pdo());
    }

    public function testCreateReturnsActiveTenantWithUtcTimestamps(): void
    {
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $tenant = $this->create('immendingen', 'Feuerwehr Immendingen');

        self::assertGreaterThan(0, $tenant->id);
        self::assertSame('immendingen', $tenant->slug->value);
        self::assertSame('Feuerwehr Immendingen', $tenant->name->value);
        self::assertTrue($tenant->active);
        self::assertSame('UTC', $tenant->createdAt->getTimezone()->getName());
        self::assertEquals($tenant->createdAt, $tenant->updatedAt);
        self::assertLessThan(60, abs($tenant->createdAt->getTimestamp() - $before->getTimestamp()));
    }

    public function testFindBySlug(): void
    {
        $created = $this->create('immendingen', 'Feuerwehr Immendingen');

        self::assertEquals($created, $this->repository->findBySlug(TenantSlug::fromString('immendingen')));
        self::assertNull($this->repository->findBySlug(TenantSlug::fromString('tuttlingen')));
    }

    public function testUmlautsInNameSurvive(): void
    {
        $this->create('tuebingen', 'Freiwillige Feuerwehr Tübingen – Süd 🚒');

        self::assertSame(
            'Freiwillige Feuerwehr Tübingen – Süd 🚒',
            $this->repository->findBySlug(TenantSlug::fromString('tuebingen'))?->name->value,
        );
    }

    public function testDuplicateSlugIsRejected(): void
    {
        $this->create('immendingen', 'Feuerwehr Immendingen');

        try {
            $this->create('immendingen', 'Andere Feuerwehr');
            self::fail('DuplicateTenantSlugException erwartet.');
        } catch (DuplicateTenantSlugException $e) {
            self::assertSame('Das Kürzel „immendingen“ ist bereits vergeben.', $e->getMessage());
        }

        $all = $this->repository->all();
        self::assertCount(1, $all);
        self::assertSame('Feuerwehr Immendingen', $all[0]->name->value);
    }

    public function testDeactivateAndActivate(): void
    {
        $slug = TenantSlug::fromString('immendingen');
        $this->create('immendingen', 'Feuerwehr Immendingen');

        self::assertTrue($this->repository->setActive($slug, false));
        self::assertFalse($this->repository->findBySlug($slug)?->active);

        self::assertTrue($this->repository->setActive($slug, true));
        self::assertTrue($this->repository->findBySlug($slug)?->active);
    }

    public function testSettingSameStateChangesNothing(): void
    {
        $slug = TenantSlug::fromString('immendingen');
        $this->create('immendingen', 'Feuerwehr Immendingen');
        $this->pdo()->exec("UPDATE fe_tenants SET updated_at = '2020-01-01 00:00:00'");

        self::assertFalse($this->repository->setActive($slug, true));
        self::assertSame(
            '2020-01-01 00:00:00',
            $this->repository->findBySlug($slug)?->updatedAt->format('Y-m-d H:i:s'),
        );
    }

    public function testStateChangeUpdatesOnlyUpdatedAt(): void
    {
        $slug = TenantSlug::fromString('immendingen');
        $this->create('immendingen', 'Feuerwehr Immendingen');
        $this->pdo()->exec("UPDATE fe_tenants SET created_at = '2020-01-01 00:00:00', updated_at = '2020-01-01 00:00:00'");

        $this->repository->setActive($slug, false);

        $tenant = $this->repository->findBySlug($slug);
        self::assertSame('2020-01-01 00:00:00', $tenant?->createdAt->format('Y-m-d H:i:s'));
        self::assertGreaterThan($tenant->createdAt, $tenant->updatedAt);
    }

    public function testSetActiveForUnknownSlugThrows(): void
    {
        $this->expectException(TenantNotFoundException::class);
        $this->expectExceptionMessage('Keine Stadt mit dem Kürzel „tuttlingen“ gefunden.');

        $this->repository->setActive(TenantSlug::fromString('tuttlingen'), false);
    }

    public function testAllIsSortedBySlug(): void
    {
        $this->create('tuttlingen', 'Feuerwehr Tuttlingen');
        $this->create('immendingen', 'Feuerwehr Immendingen');
        $this->create('geisingen', 'Feuerwehr Geisingen');

        self::assertSame(
            ['geisingen', 'immendingen', 'tuttlingen'],
            array_map(static fn (Tenant $t): string => $t->slug->value, $this->repository->all()),
        );
    }

    public function testAllIsEmptyWithoutTenants(): void
    {
        self::assertSame([], $this->repository->all());
    }

    private function create(string $slug, string $name): Tenant
    {
        return $this->repository->create(TenantSlug::fromString($slug), TenantName::fromString($name));
    }
}
