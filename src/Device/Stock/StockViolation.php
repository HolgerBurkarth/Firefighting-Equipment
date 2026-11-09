<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Stock;

/**
 * Eine Regel des Sollbestands, die eine Buchung unterschreiten würde ($have = Bestand NACH der Buchung).
 */
final readonly class StockViolation
{
    /**
     * @param list<array{id: int, name: string}> $types
     */
    public function __construct(
        public int $locationId,
        public string $locationName,
        public string $locationPath,
        public int $ruleId,
        public int $minCount,
        public array $types,
        public int $have,
    ) {
    }

    public function missing(): int
    {
        return max(0, $this->minCount - $this->have);
    }

    public function message(): string
    {
        $types = implode(' oder ', array_column($this->types, 'name'));

        return sprintf(
            'Am Standort „%s“ blieben nach dieser Buchung nur %d von mindestens %d (%s) einsatzbereit. Bitte tauschen Sie die Ausrüstung gegen einen Ersatz.',
            $this->locationPath,
            $this->have,
            $this->minCount,
            $types,
        );
    }

    /** Kurzform für den Hinweis: „HLF 20: 1 von mindestens 2 Atemschutzmaske oder Funkgerät einsatzbereit“. */
    public function detail(): string
    {
        return sprintf(
            '%s: %d von mindestens %d %s einsatzbereit',
            $this->locationPath,
            $this->have,
            $this->minCount,
            implode(' oder ', array_column($this->types, 'name')),
        );
    }

    /**
     * @return array<string, mixed> Angaben für details der Fehlerantwort (stock)
     */
    public function toArray(): array
    {
        return [
            'location' => ['id' => $this->locationId, 'name' => $this->locationName, 'path' => $this->locationPath],
            'rule' => ['id' => $this->ruleId, 'min_count' => $this->minCount, 'types' => $this->types],
            'have' => $this->have,
            'missing' => $this->missing(),
        ];
    }
}
