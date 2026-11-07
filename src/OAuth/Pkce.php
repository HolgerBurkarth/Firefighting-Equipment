<?php

declare(strict_types=1);

namespace FirefightingEquipment\OAuth;

/**
 * PKCE nur mit S256 (RFC 7636): code_challenge = base64url(SHA-256(code_verifier)), 43 Zeichen;
 * code_verifier 43-128 Zeichen aus A-Z a-z 0-9 - . _ ~.
 */
final class Pkce
{
    public const string METHOD = 'S256';

    private const string CHALLENGE_PATTERN = '/^[A-Za-z0-9_-]{43}\z/';
    private const string VERIFIER_PATTERN = '/^[A-Za-z0-9._~-]{43,128}\z/';

    public static function isValidChallenge(string $challenge): bool
    {
        return preg_match(self::CHALLENGE_PATTERN, $challenge) === 1;
    }

    public static function verify(#[\SensitiveParameter] string $verifier, string $challenge): bool
    {
        if (preg_match(self::VERIFIER_PATTERN, $verifier) !== 1) {
            return false;
        }
        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals($challenge, $expected);
    }
}
