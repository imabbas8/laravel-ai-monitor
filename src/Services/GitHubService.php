<?php

namespace Debug\AiHealth\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Debug\AiHealth\Data\PackageData;

/**
 * Enriches a PackageData object with GitHub repository signals: stars, forks,
 * open issues, last push date, archive flag and recent commit frequency.
 *
 * GitHub data is best-effort: if the repo can't be reached (private, moved,
 * not on GitHub, or rate-limited) the PackageData is returned unchanged so the
 * scorer can still produce a Packagist-only score.
 */
class GitHubService
{
    public function __construct(
        protected HttpFactory $http,
        protected array $config = [],
    ) {
    }

    public function enrich(PackageData $package): PackageData
    {
        $repo = $this->parseRepo($package->repositoryUrl);

        if ($repo === null) {
            return $package;
        }

        [$owner, $name] = $repo;

        $repoResponse = $this->request()->get("https://api.github.com/repos/{$owner}/{$name}");

        if (! $repoResponse->successful()) {
            return $package;
        }

        $repoData = $repoResponse->json();

        $package->stars        = (int) Arr::get($repoData, 'stargazers_count', 0);
        $package->forks        = (int) Arr::get($repoData, 'forks_count', 0);
        $package->openIssues   = (int) Arr::get($repoData, 'open_issues_count', 0);
        $package->lastPushedAt = Arr::get($repoData, 'pushed_at');
        $package->archived     = (bool) Arr::get($repoData, 'archived', false);
        $package->license      = Arr::get($repoData, 'license.spdx_id');

        $package->commitsLast12Weeks = $this->fetchCommitFrequency($owner, $name);

        return $package;
    }

    /**
     * Sum the weekly commit totals from the participation stats endpoint
     * (last 52 weeks) over the most recent 12 weeks.
     */
    protected function fetchCommitFrequency(string $owner, string $name): ?int
    {
        $response = $this->request()->get("https://api.github.com/repos/{$owner}/{$name}/stats/participation");

        if (! $response->successful()) {
            return null;
        }

        $all = $response->json('all', []);

        if (empty($all)) {
            return 0;
        }

        return (int) array_sum(array_slice($all, -12));
    }

    /**
     * Pull owner/repo out of a Packagist repository URL.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function parseRepo(?string $url): ?array
    {
        if (! $url || ! str_contains($url, 'github.com')) {
            return null;
        }

        if (! preg_match('#github\.com[/:]([^/]+)/([^/]+?)(?:\.git)?/?$#', $url, $matches)) {
            return null;
        }

        return [$matches[1], $matches[2]];
    }

    protected function request()
    {
        $request = $this->http
            ->timeout((int) Arr::get($this->config, 'http.timeout', 15))
            ->withHeaders([
                'User-Agent' => Arr::get($this->config, 'http.user_agent', 'laravel-ai-health'),
                'Accept'     => 'application/vnd.github+json',
            ]);

        if ($token = Arr::get($this->config, 'github_token')) {
            $request = $request->withToken($token);
        }

        return $request;
    }
}
