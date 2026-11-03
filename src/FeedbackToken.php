<?php

declare(strict_types=1);

/**
 * Link tokens: "<nonce>.<mac>", both base64url, 22 characters each.
 *
 * The nonce is 16 random bytes and the only thing the database is searched
 * by; the ticket ID never appears in the URL. The MAC is HMAC-SHA256 over
 * ticket ID, expiry and nonce, truncated to 128 bits, with a key derived
 * from osTicket's SECRET_SALT under a subkey of its own. A database dump
 * alone (nonces, ticket IDs) is therefore not enough to build a link.
 *
 * Pure apart from random_bytes(); the key is passed in.
 */
final class FeedbackToken
{
    /** D: "$" must not accept a trailing newline. */
    public const PATTERN = '/^[A-Za-z0-9_-]{22}\.[A-Za-z0-9_-]{22}$/D';

    private const SUBKEY_TOKEN = 'studio-prisma:feedback:token:v1';
    private const SUBKEY_IP = 'studio-prisma:feedback:ip:v1';

    /**
     * Key material from SECRET_SALT, or null when the salt is missing,
     * short or still the sample value: then the plugin asks nobody.
     */
    public static function key(?string $salt): ?string
    {
        if ($salt === null || strlen($salt) < 16 || strpos($salt, '%CONFIG') === 0) {
            return null;
        }
        return $salt;
    }

    public static function nonce(): string
    {
        return self::b64(random_bytes(16));
    }

    public static function mac(string $key, int $ticketId, int $expires, string $nonce): string
    {
        $sub = hash_hmac('sha256', self::SUBKEY_TOKEN, $key, true);
        $mac = hash_hmac('sha256', 'v1|' . $ticketId . '|' . $expires . '|' . $nonce, $sub, true);
        return self::b64(substr($mac, 0, 16));
    }

    public static function make(string $key, int $ticketId, int $expires, string $nonce): string
    {
        return $nonce . '.' . self::mac($key, $ticketId, $expires, $nonce);
    }

    /** @return array{0:string,1:string}|null nonce and mac, or null for anything malformed */
    public static function split($raw): ?array
    {
        if (!is_string($raw) || preg_match(self::PATTERN, $raw) !== 1) {
            return null;
        }
        return explode('.', $raw, 2);
    }

    /** Constant-time check of a MAC against the stored ticket ID and expiry. */
    public static function verify(string $key, int $ticketId, int $expires, string $nonce, string $mac): bool
    {
        return hash_equals(self::mac($key, $ticketId, $expires, $nonce), $mac);
    }

    /** The IP is kept only as this keyed hash: rate limit and data minimisation. */
    public static function ipHash(string $key, string $ip): string
    {
        return hash_hmac('sha256', $ip, hash_hmac('sha256', self::SUBKEY_IP, $key, true));
    }

    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
