<?php

namespace Debug\AiHealth\Services;

use Illuminate\Support\Arr;
use Debug\AiHealth\Data\HealthReport;
use Debug\AiHealth\Data\PackageData;

/**
 * Turns raw PackageData into a 0-100 health score using a weighted blend of
 * five sub-scores: maintenance, community, usage, stability and security.
 *
 * Each sub-score is independently 0-100; the final score is the weighted
 * average. The weights and status thresholds come from config.
 */
class HealthScoreService
{
    public function __construct(
        protected array $config = [],
    ) {
    }

    public function analyze(PackageData $package): HealthReport
    {
        [$maintenance, $maintenanceNotes] = $this->maintenanceScore($package);
        [$community, $communityNotes]     = $this->communityScore($package);
        [$usage, $usageNotes]             = $this->usageScore($package);
        [$stability, $stabilityNotes]     = $this->stabilityScore($package);
        [$security, $securityNotes]       = $this->securityScore($package);

        $subScores = [
            'maintenance' => $maintenance,
            'community'   => $community,
            'usage'       => $usage,
            'stability'   => $stability,
            'security'    => $security,
        ];

        $subScoreNotes = [
            'maintenance' => $maintenanceNotes,
            'community'   => $communityNotes,
            'usage'       => $usageNotes,
            'stability'   => $stabilityNotes,
            'security'    => $securityNotes,
        ];

        $score = $this->weightedScore($subScores);

        // A flagged-abandoned or archived package is capped hard regardless of
        // its other signals — it is not safe to depend on.
        if ($package->abandoned || $package->archived) {
            $score = min($score, (int) $this->scoring('abandoned_cap', 25));
        }

        $status = $this->resolveStatus($score, $package);

        return new HealthReport(
            package: $package,
            score: $score,
            status: $status,
            subScores: $subScores,
            compatibilityHint: $this->compatibilityHint($package),
            recommendation: $this->recommendation($score, $status, $package),
            subScoreNotes: $subScoreNotes,
        );
    }

    protected function weightedScore(array $subScores): int
    {
        $weights = Arr::get($this->config, 'weights', []);
        $totalWeight = array_sum($weights) ?: 1;

        $weighted = 0;

        foreach ($subScores as $key => $value) {
            $weighted += $value * ($weights[$key] ?? 0);
        }

        return (int) round($weighted / $totalWeight);
    }

    /**
     * Read a value from the `scoring` config, falling back to the built-in
     * default below so the scorer keeps working even with a partial config.
     */
    protected function scoring(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->config, "scoring.{$path}", Arr::get($this->defaultScoring(), $path, $default));
    }

    /**
     * The shipped defaults — identical to config/ai-health.php. Kept here so the
     * service is self-contained and never crashes on a missing key.
     */
    protected function defaultScoring(): array
    {
        return [
            'abandoned_cap' => 25,
            'maintenance' => [
                'no_data' => 50,
                'blend'   => ['recency' => 0.65, 'activity' => 0.35],
                'recency' => [
                    ['max_days' => 30, 'score' => 100], ['max_days' => 90, 'score' => 85],
                    ['max_days' => 180, 'score' => 70], ['max_days' => 365, 'score' => 50],
                    ['max_days' => 730, 'score' => 30], ['max_days' => null, 'score' => 10],
                ],
                'activity' => [
                    ['min_commits' => 30, 'score' => 100], ['min_commits' => 10, 'score' => 80],
                    ['min_commits' => 3, 'score' => 60], ['min_commits' => 1, 'score' => 40],
                    ['min_commits' => 0, 'score' => 15],
                ],
            ],
            'community' => [
                'weights'  => ['stars' => 0.55, 'forks' => 0.25, 'favers' => 0.20],
                'ceilings' => ['stars' => 5000, 'forks' => 1000, 'favers' => 2000],
            ],
            'usage' => [
                'weights'  => ['monthly' => 0.70, 'total' => 0.30],
                'ceilings' => ['monthly' => 500_000, 'total' => 50_000_000],
            ],
            'stability' => [
                'stable_release' => 50,
                'at_least_1_0'   => 25,
                'version_history' => [
                    ['min' => 20, 'bonus' => 25], ['min' => 8, 'bonus' => 18],
                    ['min' => 3, 'bonus' => 10], ['min' => 1, 'bonus' => 5], ['min' => 0, 'bonus' => 0],
                ],
            ],
            'security' => [
                'abandoned' => 10, 'no_signal' => 65, 'license_bonus' => 5,
                'issue_ratio' => [
                    ['max_ratio' => 0.02, 'score' => 100], ['max_ratio' => 0.05, 'score' => 85],
                    ['max_ratio' => 0.10, 'score' => 70], ['max_ratio' => 0.20, 'score' => 55],
                    ['max_ratio' => 0.40, 'score' => 40], ['max_ratio' => null, 'score' => 25],
                ],
            ],
        ];
    }

    /**
     * First band whose `$maxKey` is null or >= $value wins (for "<=" tables).
     *
     * @param  array<int, array<string, mixed>>  $bands
     */
    protected function bandByMax(float $value, array $bands, string $maxKey): int
    {
        foreach ($bands as $band) {
            if ($band[$maxKey] === null || $value <= $band[$maxKey]) {
                return (int) $band['score'];
            }
        }

        return (int) ($bands[array_key_last($bands)]['score'] ?? 0);
    }

    /**
     * First band whose `$minKey` is <= $value wins (for ">=" tables).
     *
     * @param  array<int, array<string, mixed>>  $bands
     */
    protected function bandByMin(int $value, array $bands, string $minKey, string $scoreKey): int
    {
        foreach ($bands as $band) {
            if ($value >= $band[$minKey]) {
                return (int) $band[$scoreKey];
            }
        }

        return (int) ($bands[array_key_last($bands)][$scoreKey] ?? 0);
    }

    /**
     * Maintenance (recency of last push + recent commit frequency).
     *
     * @return array{0: int, 1: array<int, string>}
     */
    protected function maintenanceScore(PackageData $package): array
    {
        $days = $package->daysSinceLastPush();

        if ($days === null) {
            // No GitHub signal — stay neutral rather than punishing.
            $neutral = (int) $this->scoring('maintenance.no_data', 50);

            return [$neutral, ["no GitHub data available → neutral {$neutral}"]];
        }

        $recency = $this->bandByMax($days, $this->scoring('maintenance.recency'), 'max_days');

        $notes = ["pushed {$days}d ago → {$recency}"];

        if ($package->commitsLast12Weeks === null) {
            return [$recency, $notes];
        }

        $activity = $this->bandByMin($package->commitsLast12Weeks, $this->scoring('maintenance.activity'), 'min_commits', 'score');

        $notes[] = "~{$package->commitsLast12Weeks} commits/12wk → {$activity}";

        // Recency matters more than raw commit count (config-driven blend).
        $blend = $this->scoring('maintenance.blend', ['recency' => 0.65, 'activity' => 0.35]);
        $score = (int) round(($recency * $blend['recency']) + ($activity * $blend['activity']));

        return [$score, $notes];
    }

    /**
     * Community (stars, forks, favers) on a logarithmic curve.
     *
     * @return array{0: int, 1: array<int, string>}
     */
    protected function communityScore(PackageData $package): array
    {
        $stars  = $package->stars ?? 0;
        $forks  = $package->forks ?? 0;
        $favers = $package->favers;

        $ceilings = $this->scoring('community.ceilings');
        $weights  = $this->scoring('community.weights');

        $starScore  = $this->logScore($stars, (int) $ceilings['stars']);
        $forkScore  = $this->logScore($forks, (int) $ceilings['forks']);
        $faverScore = $this->logScore($favers, (int) $ceilings['favers']);

        $score = (int) round(($starScore * $weights['stars']) + ($forkScore * $weights['forks']) + ($faverScore * $weights['favers']));

        $notes = [
            $this->abbr($stars) . '★ → ' . $starScore,
            $this->abbr($forks) . ' forks → ' . $forkScore,
            $this->abbr($favers) . ' favers → ' . $faverScore,
        ];

        return [$score, $notes];
    }

    /**
     * Usage (monthly downloads, with total downloads as a tiebreaker).
     *
     * @return array{0: int, 1: array<int, string>}
     */
    protected function usageScore(PackageData $package): array
    {
        $ceilings = $this->scoring('usage.ceilings');
        $weights  = $this->scoring('usage.weights');

        $monthly = $this->logScore($package->monthlyDownloads, (int) $ceilings['monthly']);
        $total   = $this->logScore($package->totalDownloads, (int) $ceilings['total']);

        $score = (int) round(($monthly * $weights['monthly']) + ($total * $weights['total']));

        $notes = [
            $this->abbr($package->monthlyDownloads) . '/mo → ' . $monthly,
            $this->abbr($package->totalDownloads) . ' total → ' . $total,
        ];

        return [$score, $notes];
    }

    /**
     * Stability (has a tagged stable release, version history, >= 1.0).
     *
     * @return array{0: int, 1: array<int, string>}
     */
    protected function stabilityScore(PackageData $package): array
    {
        $score = 0;
        $notes = [];

        $stableBonus = (int) $this->scoring('stability.stable_release', 50);
        if ($package->hasStableRelease) {
            $score += $stableBonus;
            $notes[] = "stable release +{$stableBonus}";
        } else {
            $notes[] = 'no stable release +0';
        }

        $oneOhBonus = (int) $this->scoring('stability.at_least_1_0', 25);
        if ($package->latestVersion && $this->isAtLeastOneOh($package->latestVersion)) {
            $score += $oneOhBonus;
            $notes[] = "≥ 1.0 +{$oneOhBonus}";
        }

        $versionBonus = $this->bandByMin($package->versionCount, $this->scoring('stability.version_history'), 'min', 'bonus');

        $score += $versionBonus;
        $notes[] = "{$package->versionCount} releases +{$versionBonus}";

        return [min($score, 100), $notes];
    }

    /**
     * Security/risk proxy: abandoned status plus an open-issue-to-popularity
     * ratio. We can't audit code, but an abandoned package or a high issue
     * backlog relative to its size are reasonable risk signals.
     *
     * @return array{0: int, 1: array<int, string>}
     */
    protected function securityScore(PackageData $package): array
    {
        if ($package->abandoned || $package->archived) {
            $capped = (int) $this->scoring('security.abandoned', 10);

            return [$capped, ["abandoned/archived → {$capped}"]];
        }

        $openIssues = $package->openIssues;
        $stars      = $package->stars;

        if ($openIssues === null || $stars === null || $stars === 0) {
            $neutral = (int) $this->scoring('security.no_signal', 65);

            return [$neutral, ["no issue/star signal → neutral {$neutral}"]];
        }

        $ratio = $openIssues / max($stars, 1);

        $score = $this->bandByMax($ratio, $this->scoring('security.issue_ratio'), 'max_ratio');

        $notes = [
            $this->abbr($openIssues) . ' open issues / ' . $this->abbr($stars) . '★ (ratio ' . number_format($ratio, 2) . ') → ' . $score,
        ];

        // A maintained license is a small positive signal.
        $licenseBonus = (int) $this->scoring('security.license_bonus', 5);
        if ($licenseBonus > 0 && $package->license && $package->license !== 'NOASSERTION') {
            $score = min(100, $score + $licenseBonus);
            $notes[] = $package->license . " license +{$licenseBonus}";
        }

        return [$score, $notes];
    }

    /**
     * Compact large counts for the breakdown notes: 4_027_071 -> "4M",
     * 99_935_637 -> "99.9M", 12_910 -> "12.9K".
     */
    protected function abbr(int $value): string
    {
        if ($value >= 1_000_000) {
            return rtrim(rtrim(number_format($value / 1_000_000, 1), '0'), '.') . 'M';
        }

        if ($value >= 1_000) {
            return rtrim(rtrim(number_format($value / 1_000, 1), '0'), '.') . 'K';
        }

        return (string) $value;
    }

    protected function compatibilityHint(PackageData $package): string
    {
        $parts = [];

        if (! empty($package->phpConstraints)) {
            $parts[] = 'PHP ' . implode(', ', $package->phpConstraints);
        }

        if (! empty($package->laravelConstraints)) {
            $laravel = $package->laravelConstraints['laravel/framework']
                ?? reset($package->laravelConstraints);
            $parts[] = 'Laravel/Illuminate ' . $laravel;
        }

        if (empty($parts)) {
            return 'No explicit PHP/Laravel constraints were declared by the latest release.';
        }

        return 'Latest release requires: ' . implode(' | ', $parts) . '.';
    }

    protected function recommendation(int $score, string $status, PackageData $package): string
    {
        if ($package->abandoned) {
            $replacement = $package->replacement
                ? " The author suggests migrating to \"{$package->replacement}\"."
                : '';

            return "This package is marked as ABANDONED on Packagist — avoid using it in new projects.{$replacement}";
        }

        if ($package->archived) {
            return 'The GitHub repository is archived (read-only). It will not receive fixes — avoid for new projects.';
        }

        return match ($status) {
            HealthReport::STATUS_SAFE    => 'Healthy and actively maintained. Safe to install.',
            HealthReport::STATUS_CAUTION => 'Usable, but review recent activity, open issues and Laravel compatibility before depending on it.',
            default                      => 'Low health signals (stale, niche, or unstable). Use only if you can vouch for it or maintain a fork.',
        };
    }

    protected function resolveStatus(int $score, PackageData $package): string
    {
        if ($package->abandoned || $package->archived) {
            return HealthReport::STATUS_ABANDONED;
        }

        $safe    = (int) Arr::get($this->config, 'thresholds.safe', 75);
        $caution = (int) Arr::get($this->config, 'thresholds.caution', 50);

        return match (true) {
            $score >= $safe    => HealthReport::STATUS_SAFE,
            $score >= $caution => HealthReport::STATUS_CAUTION,
            default            => HealthReport::STATUS_RISKY,
        };
    }

    /**
     * Map a raw count onto 0-100 using a logarithmic curve so that the
     * difference between 10 and 100 matters more than 10,000 and 100,000.
     */
    protected function logScore(int $value, int $ceiling): int
    {
        if ($value <= 0) {
            return 0;
        }

        if ($value >= $ceiling) {
            return 100;
        }

        return (int) round((log10($value + 1) / log10($ceiling + 1)) * 100);
    }

    protected function isAtLeastOneOh(string $version): bool
    {
        $normalized = ltrim($version, 'vV');
        $major = (int) (explode('.', $normalized)[0] ?? 0);

        return $major >= 1;
    }
}
