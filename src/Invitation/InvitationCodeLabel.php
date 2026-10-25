<?php

declare(strict_types=1);

namespace FirefightingEquipment\Invitation;

/**
 * Bezeichnung eines Einladungscodes, z. B. „Dienstabend März“. Leerraum am Rand wird entfernt.
 */
final readonly class InvitationCodeLabel
{
    public const int MAX_LENGTH = 100;

    private function __construct(public string $value)
    {
    }

    /**
     * @throws InvalidInvitationValueException
     */
    public static function fromString(string $value): self
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidInvitationValueException('Die Bezeichnung ist kein gültiger UTF-8-Text.');
        }

        $value = trim($value);
        if ($value === '') {
            throw new InvalidInvitationValueException('Die Bezeichnung darf nicht leer sein.');
        }

        $length = mb_strlen($value);
        if ($length > self::MAX_LENGTH) {
            throw new InvalidInvitationValueException(sprintf(
                'Die Bezeichnung darf höchstens %d Zeichen lang sein (angegeben: %d).',
                self::MAX_LENGTH,
                $length,
            ));
        }

        if (preg_match('/\p{Cc}/u', $value) === 1) {
            throw new InvalidInvitationValueException('Die Bezeichnung darf keine Steuerzeichen (z. B. Zeilenumbrüche) enthalten.');
        }

        return new self($value);
    }
}
