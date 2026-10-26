<?php

declare(strict_types=1);

namespace FirefightingEquipment\User;

/**
 * Ein Benutzerkonto. Interner Schlüssel ist die ID. Der Passwort-Hash ist bewusst nicht enthalten.
 */
final readonly class User
{
    public function __construct(
        public int $id,
        /** null = Super-Admin */
        public ?int $tenantId,
        public UserRole $role,
        public UserStatus $status,
        /** null nur bei anonymisierten Konten */
        public ?EmailAddress $email,
        public ?PersonName $name,
        public ?PersonName $displayName,
        /** UTC */
        public ?\DateTimeImmutable $emailVerifiedAt,
        /** UTC; null = nicht gesperrt */
        public ?\DateTimeImmutable $lockedAt,
        /** UTC */
        public ?\DateTimeImmutable $deletedAt,
        /** UTC */
        public \DateTimeImmutable $createdAt,
        /** UTC */
        public \DateTimeImmutable $updatedAt,
        /** Geschützt (nur aktive, nicht gesperrte Super-Admins; setzbar nur per Kommandozeile) */
        public bool $protected = false,
        /** Benachrichtigung „Log“ bei Vorgängen der Geräte-Historie (nur Admins und Super-Admins) */
        public bool $notifyDeviceLog = false,
    ) {
    }

    public function isLocked(): bool
    {
        return $this->lockedAt !== null;
    }

    public function isDeleted(): bool
    {
        return $this->status === UserStatus::Deleted;
    }

    /**
     * Ob das Konto als „aktiver, nicht gesperrter Admin“ zählt (bzw. Super-Admin unter den Super-Admins).
     */
    public function isActiveAdministrator(): bool
    {
        return $this->status === UserStatus::Active
            && !$this->isLocked()
            && ($this->role === UserRole::Admin || $this->role === UserRole::SuperAdmin);
    }

    /**
     * Ob das Konto die Benachrichtigung „Log“ einschalten darf.
     */
    public function mayReceiveDeviceLog(): bool
    {
        return !$this->isDeleted() && ($this->role === UserRole::Admin || $this->role === UserRole::SuperAdmin);
    }

    /**
     * Bezeichnung für Listen und Historie: Anzeigename, sonst Name, sonst E-Mail;
     * anonymisierte Konten erscheinen als „Gelöschter Benutzer #ID“.
     */
    public function label(): string
    {
        if ($this->isDeleted()) {
            return sprintf('Gelöschter Benutzer #%d', $this->id);
        }

        return $this->displayName?->value ?? $this->name?->value ?? $this->email?->value ?? sprintf('#%d', $this->id);
    }
}
