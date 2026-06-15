<?php

namespace Debug\AiHealth\Tests;

use Debug\AiHealth\Services\VersionService;

class VersionServiceTest extends TestCase
{
    private function service(): VersionService
    {
        return new VersionService();
    }

    public function test_resolves_constraint_to_newest_stable_match(): void
    {
        $versions = ['1.29.1', '1.29.0', '1.27.0', '1.0.0', 'dev-main'];

        $this->assertSame('1.29.1', $this->service()->resolveConstraint($versions, '^1.27'));
        $this->assertSame(3, $this->service()->countMatching($versions, '^1.27'));
    }

    public function test_returns_null_when_no_version_satisfies(): void
    {
        $versions = ['1.0.0', '1.29.1'];

        $this->assertNull($this->service()->resolveConstraint($versions, '^99.0'));
    }

    public function test_prefers_stable_over_dev_branch_alias(): void
    {
        $versions = ['13.x-dev', 'v13.15.0', 'v13.8.0'];

        $this->assertSame('13.15.0', ltrim($this->service()->resolveConstraint($versions, '^13.8'), 'v'));
    }

    public function test_finds_best_version_for_a_laravel_version(): void
    {
        $versionConstraints = [
            '8.0.0' => ['illuminate/support' => '^12.0|^13.0'],
            '6.25.0' => ['illuminate/support' => '^8.0|^9.0|^10.0'],
            '5.11.0' => ['illuminate/support' => '^6.0|^7.0'],
        ];

        $this->assertSame('6.25.0', $this->service()->bestVersionForLaravel($versionConstraints, '10'));
        $this->assertSame('8.0.0', $this->service()->bestVersionForLaravel($versionConstraints, '13'));
        $this->assertNull($this->service()->bestVersionForLaravel($versionConstraints, '4'));
    }

    public function test_no_laravel_constraint_is_treated_as_compatible(): void
    {
        $this->assertTrue($this->service()->constraintsAllow([], '11.0.0'));
    }

    public function test_normalizes_partial_versions(): void
    {
        $this->assertSame('11.0.0', $this->service()->normalize('11'));
        $this->assertSame('10.4.0', $this->service()->normalize('10.4'));
        $this->assertSame('12.0.0', $this->service()->normalize('v12'));
    }
}
