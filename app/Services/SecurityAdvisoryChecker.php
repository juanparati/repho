<?php

namespace App\Services;

use App\Enums\Ecosystem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class SecurityAdvisoryChecker
{
    /**
     * Check a package for known security advisories: the Packagist API for
     * Composer packages, the npm registry's bulk advisories endpoint for npm
     * packages. The npm endpoint needs concrete versions, so an npm package
     * without versions (e.g. before its first sync) yields no advisories.
     *
     * A failed response throws instead of returning an empty list, so callers
     * never mistake an API outage for "no advisories".
     *
     * @param  array<int, string>  $versions
     * @return array<int, array{advisoryId: string, title: string, link: string, cve: string|null, affectedVersions: string, reportedAt: string|null, severity: string|null}>
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function check(string $packageName, Ecosystem $ecosystem = Ecosystem::Composer, array $versions = []): array
    {
        return match ($ecosystem) {
            Ecosystem::Composer => $this->checkPackagist($packageName),
            Ecosystem::Npm => $this->checkNpm($packageName, $versions),
        };
    }

    /**
     * @return array<int, array{advisoryId: string, title: string, link: string, cve: string|null, affectedVersions: string, reportedAt: string|null, severity: string|null}>
     */
    private function checkPackagist(string $packageName): array
    {
        $response = Http::get('https://packagist.org/api/security-advisories/', [
            'packages' => [$packageName],
        ]);

        $response->throw();

        /** @var array<string, array<int, array{advisoryId: string, title: string, link: string, cve: string|null, affectedVersions: string, reportedAt: string, severity: string|null}>> $advisories */
        $advisories = $response->json('advisories', []);

        return $advisories[$packageName] ?? [];
    }

    /**
     * @param  array<int, string>  $versions
     * @return array<int, array{advisoryId: string, title: string, link: string, cve: string|null, affectedVersions: string, reportedAt: string|null, severity: string|null}>
     */
    private function checkNpm(string $packageName, array $versions): array
    {
        if ($versions === []) {
            return [];
        }

        $response = Http::asJson()->post('https://registry.npmjs.org/-/npm/v1/security/advisories/bulk', [
            $packageName => array_values($versions),
        ]);

        $response->throw();

        $body = $response->json();
        $advisories = is_array($body) ? ($body[$packageName] ?? []) : [];

        $mapped = [];

        foreach ($advisories as $advisory) {
            if (! is_array($advisory)) {
                continue;
            }

            $link = (string) ($advisory['url'] ?? '');
            $ghsa = basename($link);
            $cve = $advisory['cve'] ?? null;

            if (is_array($cve)) {
                $cve = $cve[0] ?? null;
            }

            $mapped[] = [
                'advisoryId' => str_starts_with($ghsa, 'GHSA-') ? $ghsa : 'npm-'.($advisory['id'] ?? $ghsa),
                'title' => (string) ($advisory['title'] ?? 'Untitled advisory'),
                'link' => $link,
                'cve' => is_string($cve) && $cve !== '' ? $cve : null,
                'affectedVersions' => (string) ($advisory['vulnerable_versions'] ?? '*'),
                'reportedAt' => null,
                'severity' => isset($advisory['severity']) ? (string) $advisory['severity'] : null,
            ];
        }

        return $mapped;
    }
}
