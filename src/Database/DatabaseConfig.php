<?php

declare(strict_types=1);

namespace FirefightingEquipment\Database;

/**
 * Zugangsdaten der Datenbank (Gruppe db der Konfiguration).
 */
final readonly class DatabaseConfig
{
    public function __construct(
        public string $host,
        public string $name,
        public string $user,
        #[\SensitiveParameter]
        public string $pass,
    ) {
    }

    /**
     * Verhindert, dass var_dump() oder print_r() das Passwort ausgeben.
     *
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'host' => $this->host,
            'name' => $this->name,
            'user' => $this->user,
            'pass' => '***',
        ];
    }
}
