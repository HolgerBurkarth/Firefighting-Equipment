<?php

declare(strict_types=1);

namespace FirefightingEquipment\Tenant;

use FirefightingEquipment\Tenant\Logo\LogoImages;
use FirefightingEquipment\Tenant\Logo\LogoSize;

/**
 * Erscheinungsbild einer Stadt: fe_tenants.accent_color und fe_tenant_logos. Getrennt von
 * TenantRepository, damit keine andere Abfrage die Bilddaten mitlädt. Jede Abfrage filtert nach
 * der Stadt; ändernde Methoden setzen fe_tenants.updated_at.
 */
final readonly class TenantAppearanceRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function find(Tenant $tenant): TenantAppearance
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.accent_color, l.version FROM fe_tenants t'
            . ' LEFT JOIN fe_tenant_logos l ON l.tenant_id = t.id WHERE t.id = ?',
        );
        $stmt->execute([$tenant->id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw TenantNotFoundException::for($tenant->slug);
        }

        // Gespeicherte Farben haben das Format (CHECK); bestand eine Farbe früher die Kontrastregel
        // und heute nicht mehr, gilt sie trotzdem weiter, bis ein Admin sie ändert.
        $color = is_string($row['accent_color']) ? AccentColor::stored($row['accent_color']) : null;

        return new TenantAppearance($color, is_string($row['version']) ? $row['version'] : null);
    }

    /**
     * @param AccentColor|null $color null = Standardfarbe
     */
    public function setAccentColor(Tenant $tenant, ?AccentColor $color): void
    {
        $this->pdo->prepare(
            'UPDATE fe_tenants SET accent_color = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND NOT (accent_color <=> ?)',
        )->execute([$color?->value, $tenant->id, $color?->value]);
    }

    /**
     * Speichert ein Logo (ersetzt ein vorhandenes). $userId muss ein Konto dieser Stadt sein
     * (Fremdschlüssel). Eigene Transaktion; eine bereits offene ist ein Fehler.
     */
    public function saveLogo(Tenant $tenant, LogoImages $images, int $userId): void
    {
        $this->transaction(function () use ($tenant, $images, $userId): void {
            $stmt = $this->pdo->prepare(
                'INSERT INTO fe_tenant_logos (tenant_id, version, png_512, png_192, png_180, updated_by, updated_at)'
                . ' VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())'
                . ' ON DUPLICATE KEY UPDATE version = VALUES(version), png_512 = VALUES(png_512),'
                . ' png_192 = VALUES(png_192), png_180 = VALUES(png_180), updated_by = VALUES(updated_by),'
                . ' updated_at = VALUES(updated_at)',
            );
            $stmt->bindValue(1, $tenant->id, \PDO::PARAM_INT);
            $stmt->bindValue(2, $images->version());
            $stmt->bindValue(3, $images->png(LogoSize::Large), \PDO::PARAM_LOB);
            $stmt->bindValue(4, $images->png(LogoSize::Medium), \PDO::PARAM_LOB);
            $stmt->bindValue(5, $images->png(LogoSize::Apple), \PDO::PARAM_LOB);
            $stmt->bindValue(6, $userId, \PDO::PARAM_INT);
            $stmt->execute();
            $this->touch($tenant);
        });
    }

    /**
     * Entfernt das Logo (zurück zum Standard-Symbol). Wiederholbar.
     *
     * @return bool ob ein Logo vorhanden war
     */
    public function deleteLogo(Tenant $tenant): bool
    {
        return $this->transaction(function () use ($tenant): bool {
            $stmt = $this->pdo->prepare('DELETE FROM fe_tenant_logos WHERE tenant_id = ?');
            $stmt->execute([$tenant->id]);
            if ($stmt->rowCount() === 0) {
                return false;
            }
            $this->touch($tenant);

            return true;
        });
    }

    /**
     * Eine Größe des Logos; liest nur deren Spalte.
     *
     * @return array{version: string, png: string}|null null = kein Logo
     */
    public function logo(Tenant $tenant, LogoSize $size): ?array
    {
        $stmt = $this->pdo->prepare(sprintf('SELECT version, %s AS png FROM fe_tenant_logos WHERE tenant_id = ?', $size->column()));
        $stmt->execute([$tenant->id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return ['version' => (string) $row['version'], 'png' => (string) $row['png']];
    }

    private function touch(Tenant $tenant): void
    {
        $this->pdo->prepare('UPDATE fe_tenants SET updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$tenant->id]);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function transaction(\Closure $work): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('TenantAppearanceRepository startet eine eigene Transaktion; keine offene Transaktion erlaubt.');
        }
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
