<?php

declare(strict_types=1);

namespace FirefightingEquipment\Auth;

/**
 * Grobe Gerätebezeichnung aus dem User-Agent, z. B. "Chrome auf Android". Das Ergebnis besteht nur
 * aus festen Wörtern dieser Klasse; Teile des User-Agents werden nie übernommen.
 */
final class DeviceLabel
{
    public const string UNKNOWN = 'Unbekanntes Gerät';

    /** Reihenfolge wichtig: Edge, Opera und Samsung nennen auch "Chrome", Chrome nennt auch "Safari". */
    private const array BROWSERS = [
        'Edge' => '/\b(Edg|EdgA|EdgiOS)\//',
        'Opera' => '/\b(OPR|Opera)\b/',
        'Samsung Internet' => '/\bSamsungBrowser\//',
        'Firefox' => '/\b(Firefox|FxiOS)\//',
        'Chrome' => '/\b(Chrome|CriOS|Chromium)\//',
        'Safari' => '/\bSafari\//',
        'curl' => '/^curl\//',
    ];

    /** Reihenfolge wichtig: Android und ChromeOS nennen auch "Linux", iOS nennt "like Mac OS X". */
    private const array SYSTEMS = [
        'Android' => '/\bAndroid\b/',
        'iOS' => '/\b(iPhone|iPad|iPod)\b/',
        'Windows' => '/\bWindows\b/',
        'ChromeOS' => '/\bCrOS\b/',
        'macOS' => '/\b(Macintosh|Mac OS X)\b/',
        'Linux' => '/\bLinux\b/',
    ];

    public static function fromUserAgent(?string $userAgent): string
    {
        if ($userAgent === null) {
            return self::UNKNOWN;
        }

        $browser = self::first(self::BROWSERS, $userAgent);
        $system = self::first(self::SYSTEMS, $userAgent);

        return match (true) {
            $browser !== null && $system !== null => $browser . ' auf ' . $system,
            $browser !== null => $browser,
            $system !== null => 'Browser auf ' . $system,
            default => self::UNKNOWN,
        };
    }

    /**
     * @param array<string, string> $patterns
     */
    private static function first(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return null;
    }
}
