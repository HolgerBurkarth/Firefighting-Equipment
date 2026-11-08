<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device;

/**
 * Standort einer Stadt, Ebene 1 (ohne übergeordneten Standort) oder 2. Gesperrte Standorte
 * (active = false) sind kein Ziel für Umbuchungen; die Historie bleibt.
 */
final readonly class Location
{
    /**
     * @param list<Location> $children Unterstandorte (nur Ebene 1), nach Name
     */
    public function __construct(
        public int $id,
        public LocationName $name,
        public LocationKind $kind,
        /** null = Ebene 1 */
        public ?int $parentId,
        public bool $active,
        /** nicht ausgesonderte (und sichtbare) Geräte direkt an diesem Standort */
        public int $deviceCount,
        /** wie $deviceCount, bei Ebene 1 einschließlich aller Unterstandorte (auch nicht aufgeführter) */
        public int $deviceCountTotal,
        public array $children,
        /** UTC */
        public \DateTimeImmutable $createdAt,
        /** UTC */
        public \DateTimeImmutable $updatedAt,
        /** false: Standort der Ebene 1 außerhalb des Datenfilters, von dem nur Fächer sichtbar sind */
        public bool $selectable = true,
        /** Aufgabe (nur Ebene 1), null = keine */
        public ?LocationTask $task = null,
        /** Standard-Lager der Stadt (nur bei task = store) */
        public bool $isDefaultStore = false,
        /** nur bei task = collection: used, defective oder both */
        public ?string $collectionAccepts = null,
    ) {
    }

    public function level(): int
    {
        return $this->parentId === null ? 1 : 2;
    }

    /**
     * Derselbe Standort nur mit den aktiven Unterstandorten ($deviceCountTotal bleibt).
     */
    public function withActiveChildrenOnly(): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->kind,
            $this->parentId,
            $this->active,
            $this->deviceCount,
            $this->deviceCountTotal,
            array_values(array_filter($this->children, static fn (Location $c): bool => $c->active)),
            $this->createdAt,
            $this->updatedAt,
            $this->selectable,
            $this->task,
            $this->isDefaultStore,
            $this->collectionAccepts,
        );
    }

    /**
     * Derselbe Standort mit anderen Unterstandorten (Datenfilter; $deviceCountTotal bleibt, er zählt
     * schon nur sichtbare Geräte).
     *
     * @param list<Location> $children
     */
    public function withChildren(array $children, bool $selectable): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->kind,
            $this->parentId,
            $this->active,
            $this->deviceCount,
            $this->deviceCountTotal,
            $children,
            $this->createdAt,
            $this->updatedAt,
            $selectable,
            $this->task,
            $this->isDefaultStore,
            $this->collectionAccepts,
        );
    }
}
