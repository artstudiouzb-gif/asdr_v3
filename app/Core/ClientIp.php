<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Resolves the visitor IP only through explicitly trusted reverse proxies.
 *
 * Forwarded headers are attacker-controlled when the origin is reachable
 * directly, so they are ignored unless REMOTE_ADDR belongs to one of the
 * configured CIDR ranges.
 */
final class ClientIp
{
    public static function applyTrustedProxy(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        $peer = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($peer === '' || !self::isTrustedProxy($peer)) {
            return;
        }

        $forwarded = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP) === false) {
            return; // Fail closed when a trusted peer sends a malformed CF header.
        }
        if ($forwarded === '') {
            $chain = array_reverse(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
            foreach ($chain as $candidate) {
                $candidate = trim($candidate);
                if (filter_var($candidate, FILTER_VALIDATE_IP) !== false && !self::isTrustedProxy($candidate)) {
                    // The nearest untrusted hop is the visitor. Values farther
                    // left may have been supplied by the visitor and are ignored.
                    $forwarded = $candidate;
                    break;
                }
            }
        }
        if ($forwarded === '') {
            return;
        }

        $_SERVER['ASDR_PROXY_ADDR'] = $peer;
        $_SERVER['REMOTE_ADDR'] = $forwarded;
    }

    public static function isTrustedProxy(string $ip): bool
    {
        $ranges = Config::get('security.trusted_proxy_cidrs', []);
        if (is_string($ranges)) {
            $ranges = preg_split('/[\s,]+/', $ranges, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }
        if (!is_array($ranges)) {
            $ranges = [];
        }
        // Existing installations may not yet have the new config key, so the
        // environment variable remains an immediate deployment-time override.
        $environmentRanges = preg_split(
            '/[\s,]+/',
            (string) (getenv('TRUSTED_PROXY_CIDRS') ?: ''),
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [];
        $ranges = array_merge($ranges, $environmentRanges);

        foreach ($ranges as $range) {
            if (is_string($range) && self::inCidr($ip, trim($range))) {
                return true;
            }
        }

        return false;
    }

    public static function inCidr(string $ip, string $cidr): bool
    {
        if ($cidr === '') {
            return false;
        }
        if (!str_contains($cidr, '/')) {
            return hash_equals($cidr, $ip);
        }

        [$network, $prefixRaw] = array_pad(explode('/', $cidr, 2), 2, '');
        $addressBytes = @inet_pton($ip);
        $networkBytes = @inet_pton($network);
        if ($addressBytes === false || $networkBytes === false || strlen($addressBytes) !== strlen($networkBytes)) {
            return false;
        }

        $maxBits = strlen($addressBytes) * 8;
        if ($prefixRaw === '' || !ctype_digit($prefixRaw)) {
            return false;
        }
        $prefix = (int) $prefixRaw;
        if ($prefix < 0 || $prefix > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;
        if ($fullBytes > 0
            && substr($addressBytes, 0, $fullBytes) !== substr($networkBytes, 0, $fullBytes)) {
            return false;
        }
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($addressBytes[$fullBytes]) & $mask) === (ord($networkBytes[$fullBytes]) & $mask);
    }
}
