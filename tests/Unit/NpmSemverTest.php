<?php

namespace Tests\Unit;

use App\Services\NpmSemver;
use PHPUnit\Framework\TestCase;

class NpmSemverTest extends TestCase
{
    public function test_normalize_strips_leading_v(): void
    {
        $this->assertSame('1.2.3', NpmSemver::normalize('v1.2.3'));
        $this->assertSame('1.2.3', NpmSemver::normalize('V1.2.3'));
        $this->assertSame('1.2.3', NpmSemver::normalize('1.2.3'));
    }

    public function test_normalize_accepts_prerelease_and_build_metadata(): void
    {
        $this->assertSame('1.0.0-beta.1', NpmSemver::normalize('v1.0.0-beta.1'));
        $this->assertSame('1.0.0+build.5', NpmSemver::normalize('1.0.0+build.5'));
        $this->assertSame('1.0.0-rc.1+sha.abc', NpmSemver::normalize('1.0.0-rc.1+sha.abc'));
    }

    public function test_normalize_rejects_non_semver_tags(): void
    {
        $this->assertNull(NpmSemver::normalize('1.0'));
        $this->assertNull(NpmSemver::normalize('release-2024'));
        $this->assertNull(NpmSemver::normalize('01.0.0'));
        $this->assertNull(NpmSemver::normalize('1.0.0.0'));
        $this->assertNull(NpmSemver::normalize(''));
    }

    public function test_compare_orders_numerically(): void
    {
        $this->assertLessThan(0, NpmSemver::compare('1.9.0', '1.10.0'));
        $this->assertGreaterThan(0, NpmSemver::compare('2.0.0', '1.99.99'));
        $this->assertSame(0, NpmSemver::compare('1.0.0', '1.0.0'));
        $this->assertSame(0, NpmSemver::compare('1.0.0+a', '1.0.0+b'));
    }

    public function test_compare_ranks_prereleases_below_releases(): void
    {
        $this->assertLessThan(0, NpmSemver::compare('1.0.0-alpha', '1.0.0'));
        $this->assertLessThan(0, NpmSemver::compare('1.0.0-alpha', '1.0.0-alpha.1'));
        $this->assertLessThan(0, NpmSemver::compare('1.0.0-alpha.1', '1.0.0-beta'));
        $this->assertLessThan(0, NpmSemver::compare('1.0.0-beta.2', '1.0.0-beta.11'));
        $this->assertLessThan(0, NpmSemver::compare('1.0.0-rc.1', '1.0.0'));
    }

    public function test_latest_prefers_highest_stable_version(): void
    {
        $this->assertSame('2.0.0', NpmSemver::latest(['1.0.0', '2.0.0', '2.1.0-beta.1', '0.9.0']));
    }

    public function test_latest_falls_back_to_prerelease_when_nothing_stable(): void
    {
        $this->assertSame('1.0.0-beta.2', NpmSemver::latest(['1.0.0-beta.1', '1.0.0-beta.2']));
    }

    public function test_latest_returns_null_for_empty_list(): void
    {
        $this->assertNull(NpmSemver::latest([]));
    }

    public function test_is_prerelease(): void
    {
        $this->assertTrue(NpmSemver::isPrerelease('1.0.0-beta'));
        $this->assertFalse(NpmSemver::isPrerelease('1.0.0'));
        $this->assertFalse(NpmSemver::isPrerelease('1.0.0+build'));
    }
}
