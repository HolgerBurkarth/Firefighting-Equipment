<?php

declare(strict_types=1);

namespace FirefightingEquipment\Api\V1\OAuth;

/**
 * VORÜBERGEHEND (Live-Test Runde 5, 07.11.2026): Welche Variablen mit "AUTH" im Namen kommen bei PHP
 * an? Nur Namen und ob der Wert leer ist, nie Werte (Zugriffstoken). Wird mit der Prüf-Route
 * wieder entfernt.
 */
final class AuthProbe
{
    /**
     * @param array<array-key, mixed> $server wie $_SERVER
     * @param array<array-key, mixed> $env    wie getenv()
     *
     * @return array{sapi: string, gateway_interface: string|null, server: array<string, bool>, env: array<string, bool>}
     */
    public static function describe(string $sapi, array $server, array $env): array
    {
        $gateway = $server['GATEWAY_INTERFACE'] ?? null;

        return [
            'sapi' => $sapi,
            // Nur das Format "CGI/1.1", sonst null: kein beliebiger Wert in der Antwort.
            'gateway_interface' => is_string($gateway) && preg_match('/^CGI\/\d\.\d\z/', $gateway) === 1 ? $gateway : null,
            'server' => self::names($server),
            'env' => self::names($env),
        ];
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<string, bool> Name → Wert nicht leer
     */
    private static function names(array $values): array
    {
        $names = [];
        foreach ($values as $name => $value) {
            if (is_string($name) && stripos($name, 'AUTH') !== false && preg_match('/^[A-Za-z0-9_]{1,100}\z/', $name) === 1) {
                $names[$name] = is_string($value) ? $value !== '' : $value !== null;
            }
        }
        ksort($names);

        return $names;
    }
}
