<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Gekürzte IP-Adresse für fe_auth_attempts (binär): IPv4 /24 (4 Byte, letztes 0),
 * IPv6 /48 (16 Byte, letzte 10 Byte 0). IPv4 in IPv6 (::ffff:a.b.c.d) zählt als IPv4.
 */
final class IpPrefix
{
    /** Für fehlende oder ungültige Adressen (z. B. Kommandozeile). */
    public const string UNKNOWN = "\0\0\0\0";

    private const string MAPPED_IPV4 = "\0\0\0\0\0\0\0\0\0\0\xFF\xFF";

    public static function fromAddress(string $address): string
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP) === false ? false : inet_pton($address);
        if ($packed === false) {
            return self::UNKNOWN;
        }

        if (strlen($packed) === 16 && str_starts_with($packed, self::MAPPED_IPV4)) {
            $packed = substr($packed, 12);
        }

        return strlen($packed) === 4
            ? substr($packed, 0, 3) . "\0"
            : substr($packed, 0, 6) . str_repeat("\0", 10);
    }
}
