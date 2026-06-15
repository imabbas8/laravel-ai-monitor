<?php

namespace Debug\AiHealth\Services;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use Debug\AiHealth\Data\PackageData;
use Debug\AiHealth\Exceptions\PackageNotFoundException;

/**
 * Fetches package metadata from the Packagist API and folds it into a
 * PackageData object (downloads, favers, versions, abandoned flag, repo URL).
 */
class PackagistService
{
    public function __construct(
        protected HttpFactory $http,
        protected array $config = [],
    ) {
    }

    /**
     * @throws PackageNotFoundException
     */
    public function fetch(string $package): PackageData
    {
        $response = $this->request()->get("https://packagist.org/packages/{$package}.json");

        if ($response->status() === 404) {
            throw new PackageNotFoundException("Package [{$package}] was not found on Packagist.");
        }

        $response->throw();

        $data = $response->json('package', []);

        if (empty($data)) {
            throw new PackageNotFoundException("Package [{$package}] returned no data from Packagist.");
        }

        return $this->map($package, $data);
    }

    protected function map(string $package, array $data): PackageData
    {
        $versions = $data['versions'] ?? [];

        [$latestVersion, $latestData, $hasStable] = $this->resolveLatestVersion($versions);

        $require = $latestData['require'] ?? [];

        return new PackageData(
            name: $data['name'] ?? $package,
            description: $data['description'] ?? null,
            totalDownloads: (int) Arr::get($data, 'downloads.total', 0),
            monthlyDownloads: (int) Arr::get($data, 'downloads.monthly', 0),
            dailyDownloads: (int) Arr::get($data, 'downloads.daily', 0),
            favers: (int) ($data['favers'] ?? 0),
            abandoned: array_key_exists('abandoned', $data),
            replacement: is_string($data['abandoned'] ?? null) ? $data['abandoned'] : null,
            latestVersion: $latestVersion,
            hasStableRelease: $hasStable,
            versionCount: count($versions),
            phpConstraints: $this->extractConstraint($require, 'php'),
            laravelConstraints: $this->extractLaravelConstraints($require),
            repositoryUrl: $data['repository'] ?? null,
            keywords: $this->extractKeywords($latestData),
            dependencies: $this->extractDependencies($require),
            versions: array_keys($versions),
            versionConstraints: $this->mapVersionConstraints($versions),
        );
    }

    /**
     * Real package dependencies from the latest release (drops php / ext-*),
     * so the alternatives engine never suggests a package's own dependency.
     *
     * @return array<int, string>
     */
    protected function extractDependencies(array $require): array
    {
        return array_values(array_filter(
            array_keys($require),
            fn ($name) => $name !== 'php' && ! str_starts_with((string) $name, 'ext-')
        ));
    }

    /**
     * Keywords help us suggest similar packages. They live on each version, so
     * read them off the resolved latest release.
     *
     * @return array<int, string>
     */
    protected function extractKeywords(array $latestData): array
    {
        return array_values(array_filter((array) ($latestData['keywords'] ?? [])));
    }

    /**
     * Build a map of version => its Laravel/Illuminate constraints, so callers
     * can answer "which version of this package supports my Laravel?".
     *
     * @return array<string, array<string, string>>
     */
    protected function mapVersionConstraints(array $versions): array
    {
        $map = [];

        foreach ($versions as $version => $versionData) {
            $map[(string) $version] = $this->extractLaravelConstraints($versionData['require'] ?? []);
        }

        return $map;
    }

    /**
     * Find genuine alternatives to a package. Best-effort: returns an empty list
     * on any failure (or when there is no confident signal) so the core report
     * is never blocked and we never show wrong suggestions.
     *
     * Strategy, in order of confidence:
     *   1. A curated map (config) of known-good alternatives for popular packages.
     *   2. A Packagist search by the package's single most DISTINCTIVE *tag*
     *      (keyword) — never free-text — so a BDD framework can't return doc
     *      builders. Candidates are then ranked by how many of the package's
     *      tags they share (relevance), with downloads only as a tiebreaker.
     *
     * @return array<int, array{name: string, description: ?string, downloads: int, favers: int, source: string}>
     */
    public function search(PackageData $package, ?int $limit = null): array
    {
        if (! Arr::get($this->config, 'alternatives.enabled', true)) {
            return [];
        }

        $limit ??= (int) Arr::get($this->config, 'alternatives.limit', 4);

        // 1. Curated, hand-verified alternatives win outright.
        if ($curated = $this->curatedAlternatives($package, $limit)) {
            return $curated;
        }

        // 2. Tag-based search ranked by tag relevance.
        return $this->tagBasedAlternatives($package, $limit);
    }

    /**
     * @return array<int, array{name: string, description: ?string, downloads: int, favers: int, source: string}>
     */
    protected function curatedAlternatives(PackageData $package, int $limit): array
    {
        $map = (array) Arr::get($this->config, 'alternatives.curated', []);
        $names = $map[$package->name] ?? $map[strtolower($package->name)] ?? null;

        if (! $names) {
            return [];
        }

        $out = [];

        foreach (array_slice((array) $names, 0, $limit) as $name) {
            $lite = $this->fetchLite((string) $name);

            $out[] = [
                'name'        => (string) $name,
                'description' => $lite['description'] ?? null,
                'downloads'   => $lite['downloads'] ?? 0,
                'favers'      => $lite['favers'] ?? 0,
                'source'      => 'curated',
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{name: string, description: ?string, downloads: int, favers: int, source: string}>
     */
    protected function tagBasedAlternatives(PackageData $package, int $limit): array
    {
        $tags = $this->distinctiveTags($package);

        if (empty($tags)) {
            return []; // no confident signal — better than wrong suggestions
        }

        // Search the top few distinctive tags and pool the candidates, so we are
        // not at the mercy of which keyword Packagist happens to list first.
        $queryTags = array_slice($tags, 0, 3);
        $pool = [];

        foreach ($queryTags as $tag) {
            foreach ($this->searchByTag($tag) as $result) {
                $name = $result['name'] ?? null;

                if ($name && ! isset($pool[strtolower($name)])) {
                    $pool[strtolower($name)] = $result;
                }
            }
        }

        $excluded = $this->exclusionSet($package);
        $laravelOnly = $this->looksLikeLaravelPackage($package);

        $matches = [];

        foreach ($pool as $result) {
            $name = $result['name'];

            if (in_array(strtolower($name), $excluded, true)) {
                continue;
            }

            $description = (string) ($result['description'] ?? '');

            // A Laravel package's alternative should also be Laravel-flavoured.
            if ($laravelOnly && ! $this->mentionsLaravel($name, $description)) {
                continue;
            }

            // Relevance = how many of the package's distinctive tags this
            // candidate actually mentions (whole-word) in its name/description.
            // Require at least one — otherwise it isn't a confident match.
            $relevance = $this->countTopicMatches($name, $description, $tags);

            if ($relevance < 1) {
                continue;
            }

            $matches[] = [
                'name'        => $name,
                'description' => $description ?: null,
                'downloads'   => (int) ($result['downloads'] ?? 0),
                'favers'      => (int) ($result['favers'] ?? 0),
                'source'      => 'tags',
                'relevance'   => $relevance,
            ];
        }

        // Rank by relevance first, popularity only as a tiebreaker.
        usort($matches, fn ($a, $b) => [$b['relevance'], $b['downloads']] <=> [$a['relevance'], $a['downloads']]);

        return array_map(
            fn ($m) => Arr::except($m, ['relevance']),
            array_slice($matches, 0, $limit)
        );
    }

    /**
     * Packagist tag search (best-effort): packages that declared $tag.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function searchByTag(string $tag): array
    {
        try {
            $response = $this->request()->get('https://packagist.org/search.json', [
                'tags'     => $tag,
                'per_page' => 25,
            ]);

            return $response->successful() ? $response->json('results', []) : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Fetch just the headline numbers for a single package (best-effort).
     *
     * @return array{description: ?string, downloads: int, favers: int}|array{}
     */
    protected function fetchLite(string $name): array
    {
        try {
            $response = $this->request()->get("https://packagist.org/packages/{$name}.json");

            if (! $response->successful()) {
                return [];
            }

            $data = $response->json('package', []);

            return [
                'description' => $data['description'] ?? null,
                'downloads'   => (int) Arr::get($data, 'downloads.total', 0),
                'favers'      => (int) ($data['favers'] ?? 0),
            ];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Lower-cased names we must never suggest: the package itself and every one
     * of its declared dependencies.
     *
     * @return array<int, string>
     */
    protected function exclusionSet(PackageData $package): array
    {
        return array_map('strtolower', array_merge([$package->name], $package->dependencies));
    }

    protected function looksLikeLaravelPackage(PackageData $package): bool
    {
        if (! empty($package->laravelConstraints)) {
            return true;
        }

        if (stripos($package->name, 'laravel') !== false) {
            return true;
        }

        foreach ($package->keywords as $keyword) {
            if (strcasecmp((string) $keyword, 'laravel') === 0) {
                return true;
            }
        }

        return false;
    }

    protected function mentionsLaravel(string $name, string $description): bool
    {
        $haystack = strtolower($name . ' ' . $description);

        return str_contains($haystack, 'laravel') || str_contains($haystack, 'illuminate');
    }

    /**
     * How many of the package's distinctive tags the candidate mentions
     * (whole-word) in its name/description. Used to rank by relevance.
     *
     * @param  array<int, string>  $topics
     */
    protected function countTopicMatches(string $name, string $description, array $topics): int
    {
        $haystack = strtolower($name . ' ' . $description);
        $count = 0;

        foreach ($topics as $topic) {
            $topic = strtolower(trim((string) $topic));

            // Whole-word match only: "repl" must not match inside "replacements",
            // "acl" must not match inside "oracle", etc.
            if ($topic !== '' && preg_match('/\b' . preg_quote($topic, '/') . '\b/u', $haystack)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The package's distinctive tags, most-specific first. Generic / ecosystem
     * stopwords (config), the package's own name and any term that echoes a
     * dependency (e.g. tinker listing "psysh") are removed — leaving the words
     * that actually describe what the package does.
     *
     * @return array<int, string>
     */
    protected function distinctiveTags(PackageData $package): array
    {
        $stop = array_map('strtolower', (array) Arr::get($this->config, 'alternatives.stopwords', [
            'php', 'dev', 'laravel', 'library', 'package', 'framework', 'tool', 'utility', 'illuminate',
        ]));

        $ownVendor = strtolower((string) (explode('/', $package->name)[0] ?? ''));
        $ownName = strtolower((string) (explode('/', $package->name)[1] ?? ''));
        $depNeedles = $this->dependencyNeedles($package->dependencies);

        return array_values(array_filter($package->keywords, function ($keyword) use ($stop, $ownVendor, $ownName, $depNeedles) {
            $k = strtolower((string) $keyword);

            // Drop generic stopwords, the package's own vendor/name, and any tag
            // that just echoes a dependency.
            if ($k === '' || in_array($k, $stop, true) || $k === $ownName || $k === $ownVendor) {
                return false;
            }

            foreach ($depNeedles as $needle) {
                if ($needle !== '' && str_contains($k, $needle)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Short names of dependencies, e.g. "psy/psysh" -> "psysh", used to strip
     * dependency-echoing keywords from the search query.
     *
     * @param  array<int, string>  $dependencies
     * @return array<int, string>
     */
    protected function dependencyNeedles(array $dependencies): array
    {
        $needles = [];

        foreach ($dependencies as $dependency) {
            $short = strtolower((string) (explode('/', $dependency)[1] ?? ''));

            if ($short !== '') {
                $needles[] = $short;
            }
        }

        return $needles;
    }

    /**
     * Walk the version list and find the highest stable (non-dev, non-pre) tag.
     * Falls back to whatever the newest tag is when no stable release exists.
     *
     * @return array{0: ?string, 1: array, 2: bool}
     */
    protected function resolveLatestVersion(array $versions): array
    {
        $stableLatest = null;
        $stableData = [];
        $anyLatest = null;
        $anyData = [];

        foreach ($versions as $version => $versionData) {
            if ($anyLatest === null) {
                $anyLatest = $version;
                $anyData = $versionData;
            }

            if ($this->isStable($version) && $stableLatest === null) {
                $stableLatest = $version;
                $stableData = $versionData;
            }
        }

        if ($stableLatest !== null) {
            return [$stableLatest, $stableData, true];
        }

        return [$anyLatest, $anyData, false];
    }

    protected function isStable(string $version): bool
    {
        $normalized = ltrim($version, 'vV');

        if (str_starts_with(strtolower($normalized), 'dev-') || str_ends_with(strtolower($normalized), '-dev')) {
            return false;
        }

        // Reject pre-release tags such as 1.0.0-alpha, -beta, -rc.
        return ! preg_match('/-(alpha|beta|rc|pre)/i', $normalized);
    }

    protected function extractConstraint(array $require, string $key): array
    {
        return isset($require[$key]) ? [$require[$key]] : [];
    }

    protected function extractLaravelConstraints(array $require): array
    {
        $constraints = [];

        foreach ($require as $dependency => $constraint) {
            if ($dependency === 'laravel/framework'
                || str_starts_with($dependency, 'illuminate/')) {
                $constraints[$dependency] = $constraint;
            }
        }

        return $constraints;
    }

    protected function request()
    {
        return $this->http
            ->timeout((int) Arr::get($this->config, 'http.timeout', 15))
            ->withHeaders([
                'User-Agent' => Arr::get($this->config, 'http.user_agent', 'laravel-ai-health'),
                'Accept'     => 'application/json',
            ]);
    }
}
