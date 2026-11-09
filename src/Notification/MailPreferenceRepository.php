<?php

declare(strict_types=1);

namespace FirefightingEquipment\Notification;

/**
 * Mail-Auswahl je Person (fe_user_mail_prefs, Phase 2, P2.10): angehakte Themen der Benachrichtigung „Log“. Ohne
 * Zeilen gelten alle Themen.
 */
final readonly class MailPreferenceRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Auswahl eines Kontos; null = keine Auswahl, also alle Themen.
     *
     * @return list<MailTopic>|null
     */
    public function topicsFor(int $userId): ?array
    {
        return $this->topicsForUsers([$userId])[$userId] ?? null;
    }

    /**
     * Auswahl mehrerer Konten; Konten ohne Auswahl fehlen im Ergebnis.
     *
     * @param list<int> $userIds
     *
     * @return array<int, list<MailTopic>>
     */
    public function topicsForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT user_id, topic FROM fe_user_mail_prefs WHERE user_id IN (%s) ORDER BY user_id, topic',
            implode(', ', array_fill(0, count($userIds), '?')),
        ));
        $stmt->execute($userIds);
        $result = [];
        foreach ($stmt->fetchAll() as $row) {
            $topic = MailTopic::tryFrom((string) $row['topic']);
            if ($topic !== null) {
                $result[(int) $row['user_id']][] = $topic;
            }
        }

        return $result;
    }

    /**
     * Ersetzt die Auswahl; null löscht sie (alle Themen).
     *
     * @param list<MailTopic>|null $topics
     */
    public function save(int $userId, ?array $topics): void
    {
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('DELETE FROM fe_user_mail_prefs WHERE user_id = ?')->execute([$userId]);
            $insert = $this->pdo->prepare('INSERT INTO fe_user_mail_prefs (user_id, topic) VALUES (?, ?)');
            foreach (array_unique($topics ?? [], SORT_REGULAR) as $topic) {
                $insert->execute([$userId, $topic->value]);
            }
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
