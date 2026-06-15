<?php

namespace Debug\AiHealth\Tests;

use Debug\AiHealth\Data\HealthReport;
use Debug\AiHealth\Data\PackageData;
use Debug\AiHealth\Services\HealthScoreService;
use PHPUnit\Framework\TestCase;

class HealthScoreServiceTest extends TestCase
{
    protected function scorer(): HealthScoreService
    {
        return new HealthScoreService(require __DIR__ . '/../config/ai-health.php');
    }

    public function test_a_strong_package_scores_safe(): void
    {
        $package = new PackageData(
            name: 'spatie/laravel-permission',
            totalDownloads: 200_000_000,
            monthlyDownloads: 9_000_000,
            favers: 12_000,
            latestVersion: '6.10.1',
            hasStableRelease: true,
            versionCount: 40,
            stars: 12_000,
            forks: 1_200,
            openIssues: 20,
            lastPushedAt: date('c', strtotime('-5 days')),
            commitsLast12Weeks: 40,
            license: 'MIT',
        );

        $report = $this->scorer()->analyze($package);

        $this->assertGreaterThanOrEqual(75, $report->score);
        $this->assertSame(HealthReport::STATUS_SAFE, $report->status);
    }

    public function test_an_abandoned_package_is_capped_and_flagged(): void
    {
        $package = new PackageData(
            name: 'old/abandoned',
            totalDownloads: 50_000_000,
            monthlyDownloads: 1_000_000,
            favers: 5_000,
            abandoned: true,
            replacement: 'new/replacement',
            latestVersion: '3.0.0',
            hasStableRelease: true,
            versionCount: 30,
            stars: 8_000,
            forks: 900,
            openIssues: 10,
            lastPushedAt: date('c', strtotime('-10 days')),
            commitsLast12Weeks: 20,
        );

        $report = $this->scorer()->analyze($package);

        $this->assertLessThanOrEqual(25, $report->score);
        $this->assertSame(HealthReport::STATUS_ABANDONED, $report->status);
        $this->assertStringContainsString('new/replacement', $report->recommendation);
    }

    public function test_a_stale_unstable_package_scores_low(): void
    {
        $package = new PackageData(
            name: 'tiny/experiment',
            totalDownloads: 120,
            monthlyDownloads: 3,
            favers: 1,
            latestVersion: 'dev-main',
            hasStableRelease: false,
            versionCount: 1,
            stars: 4,
            forks: 0,
            openIssues: 6,
            lastPushedAt: date('c', strtotime('-900 days')),
            commitsLast12Weeks: 0,
        );

        $report = $this->scorer()->analyze($package);

        $this->assertLessThan(50, $report->score);
        $this->assertSame(HealthReport::STATUS_RISKY, $report->status);
    }

    public function test_subscores_are_all_present(): void
    {
        $report = $this->scorer()->analyze(new PackageData(name: 'a/b'));

        $this->assertSame(
            ['maintenance', 'community', 'usage', 'stability', 'security'],
            array_keys($report->subScores)
        );
    }

    public function test_every_subscore_publishes_its_factors(): void
    {
        $package = new PackageData(
            name: 'spatie/laravel-permission',
            totalDownloads: 99_000_000,
            monthlyDownloads: 4_000_000,
            favers: 13_000,
            latestVersion: '8.0.0',
            hasStableRelease: true,
            versionCount: 200,
            stars: 12_900,
            forks: 1_800,
            openIssues: 0,
            lastPushedAt: date('c', strtotime('-7 days')),
            commitsLast12Weeks: 23,
            license: 'MIT',
        );

        $report = $this->scorer()->analyze($package);

        // Each dimension must come with at least one human-readable factor.
        foreach (['maintenance', 'community', 'usage', 'stability', 'security'] as $dimension) {
            $this->assertNotEmpty($report->subScoreNotes[$dimension] ?? []);
        }

        // The factors must reference the real inputs they were computed from.
        $this->assertStringContainsString('pushed 7d ago', implode(' ', $report->subScoreNotes['maintenance']));
        $this->assertStringContainsString('MIT license', implode(' ', $report->subScoreNotes['security']));
    }
}
