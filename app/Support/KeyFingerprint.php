<?php

namespace App\Support;

/**
 * Deterministic keyed hash of a game-key code.
 *
 * Codes are stored with Laravel's encrypted cast, whose ciphertext is randomised, so the
 * database cannot enforce uniqueness on it. This HMAC lets us put a UNIQUE index on the
 * fingerprint (duplicate detection on import) without storing or comparing plaintext.
 */
final class KeyFingerprint
{
    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $code));
    }

    public static function of(string $code): string
    {
        return hash_hmac('sha256', self::normalize($code), 'gamevault:key-fingerprint:'.config('app.key'));
    }
}
