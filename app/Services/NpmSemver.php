<?php

namespace App\Services;

/**
 * Minimal semver 2.0.0 handling for npm versions. Composer's VersionParser
 * accepts and normalizes things npm rejects, so npm versions are validated
 * against the official semver grammar instead.
 */
class NpmSemver
{
    private const string PATTERN = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

    /**
     * Turn a Git tag into a semver string, or null when the tag is not a
     * version. Accepts an optional leading "v" (e.g. v1.2.3).
     */
    public static function normalize(string $tag): ?string
    {
        $version = ltrim(trim($tag), 'vV');

        return preg_match(self::PATTERN, $version) === 1 ? $version : null;
    }

    public static function isPrerelease(string $version): bool
    {
        preg_match(self::PATTERN, $version, $matches);

        return ($matches[4] ?? '') !== '';
    }

    /**
     * Compare two valid semver strings. Build metadata is ignored.
     */
    public static function compare(string $a, string $b): int
    {
        preg_match(self::PATTERN, $a, $ma);
        preg_match(self::PATTERN, $b, $mb);

        for ($i = 1; $i <= 3; $i++) {
            $diff = (int) $ma[$i] <=> (int) $mb[$i];

            if ($diff !== 0) {
                return $diff;
            }
        }

        $preA = $ma[4] ?? '';
        $preB = $mb[4] ?? '';

        if ($preA === '' && $preB === '') {
            return 0;
        }

        if ($preA === '') {
            return 1;
        }

        if ($preB === '') {
            return -1;
        }

        return self::comparePrerelease(explode('.', $preA), explode('.', $preB));
    }

    /**
     * Highest version from a list, preferring stable releases over
     * prereleases. Returns null for an empty list.
     *
     * @param  array<int, string>  $versions
     */
    public static function latest(array $versions): ?string
    {
        if ($versions === []) {
            return null;
        }

        $stable = array_values(array_filter($versions, fn (string $v) => ! self::isPrerelease($v)));
        $candidates = $stable !== [] ? $stable : $versions;

        usort($candidates, [self::class, 'compare']);

        return end($candidates);
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private static function comparePrerelease(array $a, array $b): int
    {
        $count = max(count($a), count($b));

        for ($i = 0; $i < $count; $i++) {
            if (! isset($a[$i])) {
                return -1;
            }

            if (! isset($b[$i])) {
                return 1;
            }

            $numericA = ctype_digit($a[$i]);
            $numericB = ctype_digit($b[$i]);

            if ($numericA && $numericB) {
                $diff = (int) $a[$i] <=> (int) $b[$i];
            } elseif ($numericA !== $numericB) {
                $diff = $numericA ? -1 : 1;
            } else {
                $diff = strcmp($a[$i], $b[$i]) <=> 0;
            }

            if ($diff !== 0) {
                return $diff;
            }
        }

        return 0;
    }
}
