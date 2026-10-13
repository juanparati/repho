<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Repack a Git archive (zip) into the tarball layout npm expects: a gzipped
 * ustar archive whose entries live under "package/", filtered the way
 * `npm pack` filters them (package.json "files", .npmignore/.gitignore, and
 * npm's always-included/always-excluded lists). No lifecycle scripts run.
 *
 * Output is deterministic: entries are sorted, timestamps are fixed to the
 * same date npm uses, and the gzip header carries no mtime.
 */
class NpmTarballBuilder
{
    /** The fixed mtime npm uses for reproducible tarballs (1985-10-26T08:15:00Z). */
    private const int FIXED_MTIME = 499162500;

    private const array ALWAYS_INCLUDED = [
        'package.json',
        'readme',
        'readme.*',
        'license',
        'license.*',
        'licence',
        'licence.*',
        'changelog',
        'changelog.*',
        'changes',
        'changes.*',
        'history',
        'history.*',
        'notice',
        'notice.*',
    ];

    private const array ALWAYS_EXCLUDED = [
        '.git',
        '.svn',
        '.hg',
        'CVS',
        'node_modules',
        '.DS_Store',
        '._*',
        '*.orig',
        '.npmrc',
        '.npmignore',
        '.gitignore',
        '.lock-wscript',
        '.wafpickle-*',
        'npm-debug.log',
        '.*.swp',
        '/package-lock.json',
        '/yarn.lock',
        '/pnpm-lock.yaml',
    ];

    /**
     * @return array{contents: string, shasum: string, integrity: string, manifest: array<string, mixed>}
     */
    public function build(string $zipArchive, string $packageName, string $version): array
    {
        $entries = $this->readZip($zipArchive);
        $prefix = $this->detectPrefix($entries);

        $files = [];
        foreach ($entries as $name => $entry) {
            if ($prefix !== '' && ! str_starts_with($name, $prefix)) {
                continue;
            }

            $relative = substr($name, strlen($prefix));

            if ($relative === '' || str_ends_with($relative, '/')) {
                continue;
            }

            $files[$relative] = $entry;
        }

        if (! isset($files['package.json'])) {
            throw new RuntimeException('package.json not found at the root of the archive.');
        }

        $manifest = json_decode($files['package.json']['contents'], true);

        if (! is_array($manifest)) {
            throw new RuntimeException('package.json is not valid JSON.');
        }

        $manifestName = $manifest['name'] ?? null;

        if ($manifestName !== $packageName) {
            throw new RuntimeException(sprintf(
                'package.json name "%s" does not match the package name "%s".',
                is_string($manifestName) ? $manifestName : '(missing)',
                $packageName,
            ));
        }

        $manifest['version'] = $version;

        $files['package.json']['contents'] = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        $selected = $this->selectFiles($files, $manifest);
        ksort($selected, SORT_STRING);

        $tar = '';
        foreach ($selected as $path => $entry) {
            $tar .= $this->tarEntry('package/'.$path, $entry['contents'], $entry['mode']);
        }
        $tar .= str_repeat("\0", 1024);

        $contents = $this->gzip($tar);

        return [
            'contents' => $contents,
            'shasum' => sha1($contents),
            'integrity' => 'sha512-'.base64_encode(hash('sha512', $contents, true)),
            'manifest' => $manifest,
        ];
    }

    /**
     * @return array<string, array{contents: string, mode: int}>
     */
    private function readZip(string $zipArchive): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'repho-npm-');

        if ($tmp === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }

        try {
            file_put_contents($tmp, $zipArchive);

            $zip = new ZipArchive;

            if ($zip->open($tmp) !== true) {
                throw new RuntimeException('The Git archive is not a valid zip file.');
            }

            $entries = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if ($name === false || str_ends_with($name, '/')) {
                    continue;
                }

                $mode = 0644;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attr) && $opsys === ZipArchive::OPSYS_UNIX) {
                    $unixMode = ($attr >> 16) & 0777;
                    if ($unixMode & 0111) {
                        $mode = 0755;
                    }
                }

                $contents = $zip->getFromIndex($i);

                $entries[$name] = [
                    'contents' => $contents === false ? '' : $contents,
                    'mode' => $mode,
                ];
            }

            $zip->close();

            return $entries;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * GitHub and GitLab archives wrap the tree in a single top-level folder,
     * `git archive` does not. Return the folder to strip, if any.
     *
     * @param  array<string, array{contents: string, mode: int}>  $entries
     */
    private function detectPrefix(array $entries): string
    {
        if (isset($entries['package.json'])) {
            return '';
        }

        $topLevel = [];
        foreach (array_keys($entries) as $name) {
            $topLevel[explode('/', $name, 2)[0]] = true;
        }

        if (count($topLevel) === 1) {
            $prefix = array_key_first($topLevel).'/';

            if (isset($entries[$prefix.'package.json'])) {
                return $prefix;
            }
        }

        throw new RuntimeException('package.json not found at the root of the archive.');
    }

    /**
     * @param  array<string, array{contents: string, mode: int}>  $files
     * @param  array<string, mixed>  $manifest
     * @return array<string, array{contents: string, mode: int}>
     */
    private function selectFiles(array $files, array $manifest): array
    {
        $alwaysIncluded = self::ALWAYS_INCLUDED;

        if (is_string($manifest['main'] ?? null)) {
            $alwaysIncluded[] = '/'.ltrim($manifest['main'], './');
        }

        if (is_string($manifest['bin'] ?? null)) {
            $alwaysIncluded[] = '/'.ltrim($manifest['bin'], './');
        } elseif (is_array($manifest['bin'] ?? null)) {
            foreach ($manifest['bin'] as $bin) {
                if (is_string($bin)) {
                    $alwaysIncluded[] = '/'.ltrim($bin, './');
                }
            }
        }

        $includeRules = null;
        if (is_array($manifest['files'] ?? null)) {
            $includeRules = array_values(array_filter(
                array_map(fn ($p) => is_string($p) ? $this->normalizeFilesPattern($p) : null, $manifest['files']),
            ));
        }

        $ignoreRules = [];
        if ($includeRules === null) {
            $ignoreSource = $files['.npmignore']['contents'] ?? $files['.gitignore']['contents'] ?? '';
            $ignoreRules = $this->parseIgnoreFile($ignoreSource);
        }

        $selected = [];

        foreach ($files as $path => $entry) {
            if ($this->matchesAny($path, self::ALWAYS_EXCLUDED)) {
                continue;
            }

            if ($this->matchesAny($path, $alwaysIncluded, rootOnly: true)) {
                $selected[$path] = $entry;

                continue;
            }

            if ($includeRules !== null) {
                if ($this->isIncludedByRules($path, $includeRules)) {
                    $selected[$path] = $entry;
                }

                continue;
            }

            if (! $this->isIgnoredByRules($path, $ignoreRules)) {
                $selected[$path] = $entry;
            }
        }

        return $selected;
    }

    /**
     * "files" entries are relative to the package root. A bare name like
     * "lib" includes the directory recursively.
     */
    private function normalizeFilesPattern(string $pattern): ?string
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return null;
        }

        $negated = str_starts_with($pattern, '!');
        if ($negated) {
            $pattern = substr($pattern, 1);
        }

        $pattern = preg_replace('#^(\./)+#', '', $pattern);
        $pattern = rtrim($pattern, '/');

        if (! str_starts_with($pattern, '**')) {
            $pattern = '/'.ltrim($pattern, '/');
        }

        return ($negated ? '!' : '').$pattern;
    }

    /**
     * @return array<int, string>
     */
    private function parseIgnoreFile(string $contents): array
    {
        $rules = [];

        foreach (preg_split('/\r?\n/', $contents) as $line) {
            $line = rtrim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, '\\#') || str_starts_with($line, '\\!')) {
                $line = substr($line, 1);
            }

            $rules[] = $line;
        }

        return $rules;
    }

    /**
     * Gitignore semantics: the last matching rule wins, "!" re-includes.
     *
     * @param  array<int, string>  $rules
     */
    private function isIgnoredByRules(string $path, array $rules): bool
    {
        $ignored = false;

        foreach ($rules as $rule) {
            $negated = str_starts_with($rule, '!');
            $pattern = $negated ? substr($rule, 1) : $rule;

            if ($this->matches($path, $pattern)) {
                $ignored = ! $negated;
            }
        }

        return $ignored;
    }

    /**
     * @param  array<int, string>  $rules
     */
    private function isIncludedByRules(string $path, array $rules): bool
    {
        $included = false;

        foreach ($rules as $rule) {
            $negated = str_starts_with($rule, '!');
            $pattern = $negated ? substr($rule, 1) : $rule;

            if ($this->matches($path, $pattern)) {
                $included = ! $negated;
            }
        }

        return $included;
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function matchesAny(string $path, array $patterns, bool $rootOnly = false): bool
    {
        foreach ($patterns as $pattern) {
            if ($rootOnly && ! str_starts_with($pattern, '/')) {
                $pattern = '/'.$pattern;
            }

            if ($this->matches($path, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match one gitignore-style pattern against a file path. A pattern that
     * matches a parent directory matches everything inside it.
     */
    private function matches(string $path, string $pattern): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return false;
        }

        $directoryOnly = str_ends_with($pattern, '/');
        $pattern = rtrim($pattern, '/');

        $anchored = str_contains($pattern, '/');
        $pattern = ltrim($pattern, '/');

        $regex = $this->globToRegex($pattern);
        $regex = $anchored ? '#^'.$regex.'$#i' : '#(^|/)'.$regex.'$#i';

        $candidates = [];
        $segments = explode('/', $path);

        for ($i = 1; $i <= count($segments); $i++) {
            $candidate = implode('/', array_slice($segments, 0, $i));
            $isDirectory = $i < count($segments);

            if ($directoryOnly && ! $isDirectory) {
                continue;
            }

            $candidates[] = $candidate;
        }

        foreach ($candidates as $candidate) {
            if (preg_match($regex, $candidate) === 1) {
                return true;
            }
        }

        return false;
    }

    private function globToRegex(string $glob): string
    {
        $regex = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];

            if ($char === '*') {
                if (($glob[$i + 1] ?? '') === '*') {
                    $i++;
                    if (($glob[$i + 1] ?? '') === '/') {
                        $i++;
                        $regex .= '(?:.*/)?';
                    } else {
                        $regex .= '.*';
                    }
                } else {
                    $regex .= '[^/]*';
                }
            } elseif ($char === '?') {
                $regex .= '[^/]';
            } elseif ($char === '[') {
                $end = strpos($glob, ']', $i);
                if ($end === false) {
                    $regex .= '\\[';
                } else {
                    $class = substr($glob, $i + 1, $end - $i - 1);
                    $class = str_starts_with($class, '!') ? '^'.substr($class, 1) : $class;
                    $regex .= '['.str_replace('\\', '\\\\', $class).']';
                    $i = $end;
                }
            } else {
                $regex .= preg_quote($char, '#');
            }
        }

        return $regex;
    }

    private function tarEntry(string $name, string $contents, int $mode): string
    {
        [$name, $prefix] = $this->splitTarName($name);

        $header = str_pad($name, 100, "\0")
            .sprintf("%07o\0", $mode)
            .sprintf("%07o\0", 0)
            .sprintf("%07o\0", 0)
            .sprintf("%011o\0", strlen($contents))
            .sprintf("%011o\0", self::FIXED_MTIME)
            .'        ' // checksum placeholder
            .'0'
            .str_repeat("\0", 100) // linkname
            ."ustar\0"
            .'00'
            .str_pad('', 32, "\0") // uname
            .str_pad('', 32, "\0") // gname
            .sprintf("%07o\0", 0)
            .sprintf("%07o\0", 0)
            .str_pad($prefix, 155, "\0");

        $header = str_pad($header, 512, "\0");

        $checksum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);

        $padding = (512 - (strlen($contents) % 512)) % 512;

        return $header.$contents.str_repeat("\0", $padding);
    }

    /**
     * ustar stores up to 100 bytes in "name" and up to 155 in "prefix".
     *
     * @return array{0: string, 1: string}
     */
    private function splitTarName(string $path): array
    {
        if (strlen($path) <= 100) {
            return [$path, ''];
        }

        $position = strlen($path) - 101;

        while ($position >= 0) {
            $slash = strpos($path, '/', $position);

            if ($slash === false || $slash > strlen($path) - 1) {
                break;
            }

            $prefix = substr($path, 0, $slash);
            $name = substr($path, $slash + 1);

            if (strlen($prefix) <= 155 && strlen($name) <= 100 && $name !== '') {
                return [$name, $prefix];
            }

            $position--;
        }

        throw new RuntimeException("Path too long for a tarball entry: {$path}");
    }

    /**
     * Gzip with a fixed header so identical input always yields identical
     * bytes (PHP's gzencode leaves the OS byte platform dependent).
     */
    private function gzip(string $data): string
    {
        $deflated = gzdeflate($data, 9);

        if ($deflated === false) {
            throw new RuntimeException('Could not compress the tarball.');
        }

        return "\x1f\x8b\x08\x00\x00\x00\x00\x00\x00\x03"
            .$deflated
            .pack('V', crc32($data))
            .pack('V', strlen($data) % 4294967296);
    }
}
