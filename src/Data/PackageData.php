<?php

namespace Debug\AiHealth\Data;

/**
 * Raw, normalised data collected from Packagist and GitHub for a single package.
 *
 * This is a plain container — it does no scoring. The HealthScoreService reads
 * from it, and the AiExplanationService serialises it for the prompt.
 */
class PackageData
{
    public function __construct(
        public string $name,
        // Packagist
        public ?string $description = null,
        public int $totalDownloads = 0,
        public int $monthlyDownloads = 0,
        public int $dailyDownloads = 0,
        public int $favers = 0,
        public bool $abandoned = false,
        public ?string $replacement = null,
        public ?string $latestVersion = null,
        public bool $hasStableRelease = false,
        public int $versionCount = 0,
        public array $phpConstraints = [],
        public array $laravelConstraints = [],
        public ?string $repositoryUrl = null,
        public array $keywords = [],
        /** @var array<int, string> dependency package names from the latest release */
        public array $dependencies = [],
        /** @var array<int, string> ordered version strings, newest first */
        public array $versions = [],
        /** @var array<string, array<string, string>> version => [dependency => constraint] */
        public array $versionConstraints = [],
        // GitHub
        public ?int $stars = null,
        public ?int $forks = null,
        public ?int $openIssues = null,
        public ?string $lastPushedAt = null,
        public ?int $commitsLast12Weeks = null,
        public bool $archived = false,
        public ?string $license = null,
    ) {
    }

    public function daysSinceLastPush(): ?int
    {
        if (! $this->lastPushedAt) {
            return null;
        }

        $timestamp = strtotime($this->lastPushedAt);

        if ($timestamp === false) {
            return null;
        }

        return (int) floor((time() - $timestamp) / 86400);
    }

    public function toArray(): array
    {
        return [
            'name'                => $this->name,
            'description'         => $this->description,
            'total_downloads'     => $this->totalDownloads,
            'monthly_downloads'   => $this->monthlyDownloads,
            'daily_downloads'     => $this->dailyDownloads,
            'favers'              => $this->favers,
            'abandoned'           => $this->abandoned,
            'replacement'         => $this->replacement,
            'latest_version'      => $this->latestVersion,
            'has_stable_release'  => $this->hasStableRelease,
            'version_count'       => $this->versionCount,
            'php_constraints'     => $this->phpConstraints,
            'laravel_constraints' => $this->laravelConstraints,
            'repository_url'      => $this->repositoryUrl,
            'stars'               => $this->stars,
            'forks'               => $this->forks,
            'open_issues'         => $this->openIssues,
            'last_pushed_at'      => $this->lastPushedAt,
            'days_since_push'     => $this->daysSinceLastPush(),
            'commits_last_12_weeks' => $this->commitsLast12Weeks,
            'archived'            => $this->archived,
            'license'             => $this->license,
        ];
    }
}
