<?php

namespace Tests\Unit;

use App\Enums\Ecosystem;
use App\Services\SecurityAdvisoryChecker;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecurityAdvisoryCheckerTest extends TestCase
{
    public function test_returns_advisories_for_vulnerable_package(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response([
                'advisories' => [
                    'vendor/package' => [
                        [
                            'advisoryId' => 'PKSA-1234',
                            'title' => 'SQL Injection',
                            'link' => 'https://example.com/advisory',
                            'cve' => 'CVE-2024-1234',
                            'affectedVersions' => '>=1.0,<1.5',
                            'reportedAt' => '2024-01-15',
                            'severity' => 'high',
                        ],
                    ],
                ],
            ]),
        ]);

        $checker = new SecurityAdvisoryChecker;
        $advisories = $checker->check('vendor/package');

        $this->assertCount(1, $advisories);
        $this->assertSame('PKSA-1234', $advisories[0]['advisoryId']);
        $this->assertSame('SQL Injection', $advisories[0]['title']);
        $this->assertSame('CVE-2024-1234', $advisories[0]['cve']);
    }

    public function test_returns_empty_array_for_safe_package(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response([
                'advisories' => [],
            ]),
        ]);

        $checker = new SecurityAdvisoryChecker;
        $advisories = $checker->check('vendor/safe-package');

        $this->assertEmpty($advisories);
    }

    public function test_throws_when_api_fails(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response(null, 500),
        ]);

        $checker = new SecurityAdvisoryChecker;

        $this->expectException(RequestException::class);

        $checker->check('vendor/package');
    }

    public function test_returns_empty_array_when_package_not_in_response(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response([
                'advisories' => [
                    'other/package' => [
                        [
                            'advisoryId' => 'PKSA-9999',
                            'title' => 'Some issue',
                            'link' => 'https://example.com',
                            'cve' => null,
                            'affectedVersions' => '>=1.0',
                            'reportedAt' => '2024-01-01',
                            'severity' => 'low',
                        ],
                    ],
                ],
            ]),
        ]);

        $checker = new SecurityAdvisoryChecker;
        $advisories = $checker->check('vendor/package');

        $this->assertEmpty($advisories);
    }

    public function test_returns_multiple_advisories(): void
    {
        Http::fake([
            'packagist.org/api/security-advisories/*' => Http::response([
                'advisories' => [
                    'vendor/package' => [
                        [
                            'advisoryId' => 'PKSA-1',
                            'title' => 'First issue',
                            'link' => 'https://example.com/1',
                            'cve' => 'CVE-2024-0001',
                            'affectedVersions' => '>=1.0,<1.5',
                            'reportedAt' => '2024-01-01',
                            'severity' => 'high',
                        ],
                        [
                            'advisoryId' => 'PKSA-2',
                            'title' => 'Second issue',
                            'link' => 'https://example.com/2',
                            'cve' => null,
                            'affectedVersions' => '>=2.0,<2.3',
                            'reportedAt' => '2024-02-01',
                            'severity' => 'medium',
                        ],
                    ],
                ],
            ]),
        ]);

        $checker = new SecurityAdvisoryChecker;
        $advisories = $checker->check('vendor/package');

        $this->assertCount(2, $advisories);
        $this->assertSame('PKSA-1', $advisories[0]['advisoryId']);
        $this->assertSame('PKSA-2', $advisories[1]['advisoryId']);
    }

    public function test_npm_check_without_versions_returns_empty_without_calling_registry(): void
    {
        Http::fake();

        $advisories = (new SecurityAdvisoryChecker)->check('left-pad', Ecosystem::Npm);

        $this->assertSame([], $advisories);
        Http::assertNothingSent();
    }

    public function test_npm_check_maps_bulk_advisories(): void
    {
        Http::fake([
            'registry.npmjs.org/-/npm/v1/security/advisories/bulk' => Http::response([
                'left-pad' => [
                    [
                        'id' => 1091,
                        'url' => 'https://github.com/advisories/GHSA-abcd-efgh-ijkl',
                        'title' => 'Regular Expression Denial of Service',
                        'severity' => 'moderate',
                        'vulnerable_versions' => '<1.3.0',
                        'cwe' => ['CWE-400'],
                        'cve' => ['CVE-2020-1234'],
                    ],
                    [
                        'id' => 2000,
                        'url' => 'https://example.com/advisory/2000',
                        'title' => 'Something else',
                        'severity' => 'low',
                        'vulnerable_versions' => '*',
                    ],
                ],
            ]),
        ]);

        $advisories = (new SecurityAdvisoryChecker)->check('left-pad', Ecosystem::Npm, ['1.0.0', '1.3.0']);

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['left-pad'] === ['1.0.0', '1.3.0']);

        $this->assertCount(2, $advisories);
        $this->assertSame('GHSA-abcd-efgh-ijkl', $advisories[0]['advisoryId']);
        $this->assertSame('CVE-2020-1234', $advisories[0]['cve']);
        $this->assertSame('<1.3.0', $advisories[0]['affectedVersions']);
        $this->assertSame('moderate', $advisories[0]['severity']);
        $this->assertNull($advisories[0]['reportedAt']);
        $this->assertSame('npm-2000', $advisories[1]['advisoryId']);
        $this->assertNull($advisories[1]['cve']);
    }

    public function test_npm_check_returns_empty_for_unknown_package(): void
    {
        Http::fake(['registry.npmjs.org/*' => Http::response([])]);

        $this->assertSame([], (new SecurityAdvisoryChecker)->check('private-pkg', Ecosystem::Npm, ['1.0.0']));
    }

    public function test_npm_check_throws_when_registry_fails(): void
    {
        Http::fake(['registry.npmjs.org/*' => Http::response(null, 500)]);

        $this->expectException(RequestException::class);

        (new SecurityAdvisoryChecker)->check('left-pad', Ecosystem::Npm, ['1.0.0']);
    }
}
