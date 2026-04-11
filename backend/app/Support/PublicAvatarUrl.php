<?php

namespace App\Support;

/**
 * Turn User.AvatarUrl (path, /storage/..., or legacy full URL with wrong APP_URL) into a root-relative URL
 * so the browser always loads from the current host (SPA + Raspberry Pi / LAN access).
 */
class PublicAvatarUrl
{
    public static function forBrowser(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $s = trim($stored);

        if (str_starts_with($s, '/storage/')) {
            return $s;
        }

        if (str_starts_with($s, 'http://') || str_starts_with($s, 'https://')) {
            if (preg_match('~/storage/[^?\s#]+~', $s, $m)) {
                return $m[0];
            }

            return $s;
        }

        return '/storage/' . ltrim($s, '/');
    }
}
