<?php

declare(strict_types=1);

namespace FirefightingEquipment\Invitation;

use FirefightingEquipment\Tenant\Tenant;

/**
 * Zugriff auf fe_invitation_codes. Jede Abfrage filtert nach der Stadt; ein Code einer anderen
 * Stadt verhält sich wie ein unbekannter. Gespeichert wird nur der SHA-256 des Codes.
 *
 * Die Nutzung zählt UserRepository::register in seiner Transaktion (lockUsableId, countUse).
 */
final readonly class InvitationCodeRepository
{
    public const int MAX_VALID_DAYS = 90;
    public const int MAX_USES = 500;

    private const int ER_DUP_ENTRY = 1062;
    private const int MAX_INSERT_ATTEMPTS = 3;
    private const string COLUMNS = 'id, tenant_id, label, max_uses, use_count, expires_at, revoked_at, created_by,'
        . ' created_at, expires_at <= UTC_TIMESTAMP() AS expired';
    /** Bedingung für einen nutzbaren Code (Zeitvergleich in der Datenbank). */
    private const string USABLE = 'revoked_at IS NULL AND use_count < max_uses AND expires_at > UTC_TIMESTAMP()';

    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Legt einen Code an. Der Klartext steht nur im Ergebnis und wird nirgends gespeichert.
     *
     * @param int $createdBy Admin derselben Stadt (prüft auch der Fremdschlüssel)
     *
     * @return array{InvitationCode, InvitationCodeValue}
     *
     * @throws InvalidInvitationValueException Gültigkeit oder Nutzungen außerhalb der Grenzen
     */
    public function create(Tenant $tenant, int $createdBy, ?InvitationCodeLabel $label, int $validDays, int $maxUses): array
    {
        if ($validDays < 1 || $validDays > self::MAX_VALID_DAYS) {
            throw new InvalidInvitationValueException(sprintf('Die Gültigkeit muss 1 bis %d Tage betragen.', self::MAX_VALID_DAYS));
        }
        if ($maxUses < 1 || $maxUses > self::MAX_USES) {
            throw new InvalidInvitationValueException(sprintf('Die Zahl der Nutzungen muss 1 bis %d betragen.', self::MAX_USES));
        }

        // Eine Kollision ist bei 31^12 Möglichkeiten praktisch ausgeschlossen; dann neu erzeugen.
        for ($attempt = 1; ; ++$attempt) {
            $value = InvitationCodeValue::generate();
            try {
                // Beide UTC_TIMESTAMP() einer Anweisung liefern denselben Wert (CHECK: höchstens 90 Tage).
                $this->pdo->prepare(
                    'INSERT INTO fe_invitation_codes (tenant_id, code_hash, label, max_uses, use_count, expires_at,'
                    . ' created_by, created_at) VALUES (?, ?, ?, ?, 0, UTC_TIMESTAMP() + INTERVAL ? DAY, ?, UTC_TIMESTAMP())',
                )->execute([$tenant->id, $value->hash(), $label?->value, $maxUses, $validDays, $createdBy]);
                break;
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? null) !== self::ER_DUP_ENTRY || $attempt >= self::MAX_INSERT_ATTEMPTS) {
                    throw $e;
                }
            }
        }

        $code = $this->findByTenantAndId($tenant, (int) $this->pdo->lastInsertId())
            ?? throw new \LogicException('Angelegter Einladungscode nicht gefunden.');

        return [$code, $value];
    }

    /**
     * @return list<InvitationCode> neueste zuerst
     */
    public function listByTenant(Tenant $tenant): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM fe_invitation_codes WHERE tenant_id = ? ORDER BY id DESC');
        $stmt->execute([$tenant->id]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    public function findByTenantAndId(Tenant $tenant, int $id): ?InvitationCode
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM fe_invitation_codes WHERE id = ? AND tenant_id = ?');
        $stmt->execute([$id, $tenant->id]);
        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * Widerruft einen Code; ein bereits widerrufener bleibt unverändert (wiederholbar).
     *
     * @return InvitationCode|null null, wenn der Code in dieser Stadt nicht existiert
     */
    public function revoke(Tenant $tenant, int $id): ?InvitationCode
    {
        $this->pdo->prepare('UPDATE fe_invitation_codes SET revoked_at = UTC_TIMESTAMP() WHERE id = ? AND tenant_id = ? AND revoked_at IS NULL')
            ->execute([$id, $tenant->id]);

        return $this->findByTenantAndId($tenant, $id);
    }

    /**
     * @return int|null ID des Codes, wenn er in dieser Stadt existiert und nutzbar ist (ohne Sperre)
     */
    public function findUsableId(Tenant $tenant, InvitationCodeValue $code): ?int
    {
        return $this->usableId($tenant, $code, '');
    }

    /**
     * Wie findUsableId, sperrt den Code aber bis zum Ende der Transaktion (FOR UPDATE), damit die
     * Nutzung nicht gleichzeitig über max_uses hinaus gezählt wird.
     */
    public function lockUsableId(Tenant $tenant, InvitationCodeValue $code): ?int
    {
        if (!$this->pdo->inTransaction()) {
            throw new \LogicException('lockUsableId braucht eine offene Transaktion.');
        }

        return $this->usableId($tenant, $code, ' FOR UPDATE');
    }

    /**
     * Zählt eine Nutzung (nur nach lockUsableId in derselben Transaktion).
     */
    public function countUse(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE fe_invitation_codes SET use_count = use_count + 1 WHERE id = ? AND ' . self::USABLE);
        $stmt->execute([$id]);
        if ($stmt->rowCount() !== 1) {
            throw new \LogicException(sprintf('Einladungscode #%d ist nicht (mehr) nutzbar.', $id));
        }
    }

    private function usableId(Tenant $tenant, InvitationCodeValue $code, string $lock): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM fe_invitation_codes WHERE code_hash = ? AND tenant_id = ? AND ' . self::USABLE . $lock);
        $stmt->execute([$code->hash(), $tenant->id]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): InvitationCode
    {
        $useCount = (int) $row['use_count'];
        $maxUses = (int) $row['max_uses'];

        return new InvitationCode(
            (int) $row['id'],
            (int) $row['tenant_id'],
            $row['label'] === null ? null : InvitationCodeLabel::fromString((string) $row['label']),
            $maxUses,
            $useCount,
            self::utc((string) $row['expires_at']),
            $row['revoked_at'] === null ? null : self::utc((string) $row['revoked_at']),
            (int) $row['created_by'],
            self::utc((string) $row['created_at']),
            InvitationCodeStatus::of($row['revoked_at'] !== null, $useCount, $maxUses, (bool) $row['expired']),
        );
    }

    private static function utc(string $value): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'))
            ?: throw new \UnexpectedValueException(sprintf('Ungültiger Zeitstempel: %s', $value));
    }
}
