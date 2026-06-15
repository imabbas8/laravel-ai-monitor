<?php

namespace Debug\AiHealth\Tests;

use Debug\AiHealth\Data\PackageData;
use Debug\AiHealth\Exceptions\PackageNotFoundException;
use Debug\AiHealth\Services\GitHubService;
use Debug\AiHealth\Services\HealthScoreService;
use Debug\AiHealth\Services\PackagistService;
use Illuminate\Support\Facades\Http;

class PackageServicesTest extends TestCase
{
    public function test_packagist_service_maps_metadata(): void
    {
        Http::fake([
            'packagist.org/*' => Http::response([
                'package' => [
                    'name'        => 'vendor/pkg',
                    'description' => 'desc',
                    'downloads'   => ['total' => 1000, 'monthly' => 100, 'daily' => 10],
                    'favers'      => 42,
                    'repository'  => 'https://github.com/vendor/pkg',
                    'versions'    => [
                        'dev-main' => ['require' => []],
                        'v2.1.0'   => ['require' => ['php' => '^8.1', 'illuminate/support' => '^10.0']],
                        'v1.0.0'   => ['require' => ['php' => '^8.0']],
                    ],
                ],
            ], 200),
        ]);

        $data = app(PackagistService::class)->fetch('vendor/pkg');

        $this->assertSame('vendor/pkg', $data->name);
        $this->assertSame(1000, $data->totalDownloads);
        $this->assertSame(100, $data->monthlyDownloads);
        $this->assertSame(42, $data->favers);
        $this->assertSame('v2.1.0', $data->latestVersion, 'Should skip the dev branch and pick the newest stable tag.');
        $this->assertTrue($data->hasStableRelease);
        $this->assertSame(3, $data->versionCount);
        $this->assertSame('https://github.com/vendor/pkg', $data->repositoryUrl);
        $this->assertArrayHasKey('illuminate/support', $data->laravelConstraints);
    }

    public function test_packagist_service_throws_when_missing(): void
    {
        Http::fake([
            'packagist.org/*' => Http::response('Not Found', 404),
        ]);

        $this->expectException(PackageNotFoundException::class);

        app(PackagistService::class)->fetch('vendor/missing');
    }

    public function test_github_service_enriches_package(): void
    {
        Http::fake([
            'packagist.org/*' => Http::response([
                'package' => [
                    'name'       => 'vendor/pkg',
                    'downloads'  => ['total' => 1, 'monthly' => 1, 'daily' => 1],
                    'repository' => 'https://github.com/vendor/pkg',
                    'versions'   => ['v1.0.0' => ['require' => []]],
                ],
            ], 200),
            'api.github.com/repos/*/stats/participation' => Http::response(['all' => array_fill(0, 52, 2)], 200),
            'api.github.com/repos/*' => Http::response([
                'stargazers_count'  => 555,
                'forks_count'       => 66,
                'open_issues_count' => 7,
                'pushed_at'         => date('c', strtotime('-3 days')),
                'archived'          => false,
                'license'           => ['spdx_id' => 'MIT'],
            ], 200),
        ]);

        $package = app(PackagistService::class)->fetch('vendor/pkg');
        $enriched = app(GitHubService::class)->enrich($package);

        $this->assertSame(555, $enriched->stars);
        $this->assertSame(66, $enriched->forks);
        $this->assertSame(7, $enriched->openIssues);
        $this->assertSame('MIT', $enriched->license);
        $this->assertSame(24, $enriched->commitsLast12Weeks); // 12 weeks * 2
        $this->assertNotNull($enriched->daysSinceLastPush());
    }

    public function test_github_service_is_safe_when_repo_unreachable(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response('rate limited', 403),
            'packagist.org/*'  => Http::response([
                'package' => [
                    'name'       => 'vendor/pkg',
                    'downloads'  => ['total' => 1, 'monthly' => 1, 'daily' => 1],
                    'repository' => 'https://github.com/vendor/pkg',
                    'versions'   => ['v1.0.0' => ['require' => []]],
                ],
            ], 200),
        ]);

        $package = app(PackagistService::class)->fetch('vendor/pkg');
        $enriched = app(GitHubService::class)->enrich($package);

        // Falls back gracefully: no GitHub fields set, no exception.
        $this->assertNull($enriched->stars);

        // The scorer still produces a valid report from Packagist-only data.
        $report = app(HealthScoreService::class)->analyze($enriched);
        $this->assertIsInt($report->score);
        $this->assertGreaterThanOrEqual(0, $report->score);
        $this->assertLessThanOrEqual(100, $report->score);
    }

    public function test_search_excludes_dependencies_noise_and_non_laravel(): void
    {
        Http::fake([
            'packagist.org/search.json*' => Http::response([
                'results' => [
                    ['name' => 'psy/psysh', 'description' => 'An interactive shell for PHP', 'downloads' => 9000, 'favers' => 10],
                    ['name' => 'webpatser/laravel-uuid', 'description' => 'Laravel UUID replacements', 'downloads' => 8000, 'favers' => 10],
                    ['name' => 'symfony/console', 'description' => 'Generic console component', 'downloads' => 99999, 'favers' => 10],
                    ['name' => 'vendor/self', 'description' => 'The package itself', 'downloads' => 50, 'favers' => 10],
                    ['name' => 'good/laravel-repl', 'description' => 'A Laravel REPL console', 'downloads' => 1000, 'favers' => 10],
                ],
            ], 200),
        ]);

        $package = new PackageData(
            name: 'vendor/self',
            keywords: ['laravel', 'REPL', 'psysh'],
            dependencies: ['psy/psysh', 'illuminate/console'],
            laravelConstraints: ['illuminate/console' => '^11.0'],
        );

        $names = array_column(app(PackagistService::class)->search($package), 'name');

        $this->assertContains('good/laravel-repl', $names, 'A genuinely relevant Laravel REPL should be kept.');
        $this->assertNotContains('psy/psysh', $names, 'A dependency must never be suggested.');
        $this->assertNotContains('webpatser/laravel-uuid', $names, '"repl" must not match inside "replacements".');
        $this->assertNotContains('symfony/console', $names, 'Non-Laravel packages are dropped for a Laravel package.');
        $this->assertNotContains('vendor/self', $names, 'The package never suggests itself.');
    }

    public function test_alternatives_never_return_unrelated_doc_builders(): void
    {
        // The real-world bug: Behat (a BDD testing framework) was returning
        // documentation builders because of free-text keyword matching.
        Http::fake([
            'packagist.org/search.json*' => Http::response([
                'results' => [
                    ['name' => 'symfony-tools/docs-builder', 'description' => 'A documentation builder', 'downloads' => 66_000, 'favers' => 1],
                    ['name' => 'codeception/codeception', 'description' => 'BDD-style fullstack testing framework', 'downloads' => 89_000_000, 'favers' => 1],
                ],
            ], 200),
        ]);

        $package = new PackageData(name: 'acme/bdd-kit', keywords: ['symfony', 'documentation', 'bdd']);

        $names = array_column(app(PackagistService::class)->search($package), 'name');

        $this->assertContains('codeception/codeception', $names, 'A real BDD tool should be suggested.');
        $this->assertNotContains('symfony-tools/docs-builder', $names, 'A doc builder must never be a BDD alternative.');
    }

    public function test_curated_alternatives_take_priority(): void
    {
        Http::fake([
            'packagist.org/packages/*' => Http::response([
                'package' => ['description' => 'desc', 'downloads' => ['total' => 1000], 'favers' => 5],
            ], 200),
        ]);

        $package = new PackageData(name: 'behat/behat', keywords: ['bdd', 'testing']);

        $alternatives = app(PackagistService::class)->search($package);

        $this->assertSame(['codeception/codeception', 'phpspec/phpspec'], array_column($alternatives, 'name'));
        $this->assertSame('curated', $alternatives[0]['source']);
    }

    public function test_full_report_array_shape(): void
    {
        Http::fake([
            'api.github.com/repos/*/stats/participation' => Http::response(['all' => array_fill(0, 52, 3)], 200),
            'api.github.com/repos/*' => Http::response([
                'stargazers_count'  => 12000,
                'forks_count'       => 1200,
                'open_issues_count' => 20,
                'pushed_at'         => date('c', strtotime('-5 days')),
                'archived'          => false,
                'license'           => ['spdx_id' => 'MIT'],
            ], 200),
            'packagist.org/*' => Http::response([
                'package' => [
                    'name'       => 'vendor/healthy',
                    'downloads'  => ['total' => 200000000, 'monthly' => 9000000, 'daily' => 300000],
                    'favers'     => 12000,
                    'repository' => 'https://github.com/vendor/healthy',
                    'versions'   => ['v6.0.0' => ['require' => ['php' => '^8.0']]],
                ],
            ], 200),
        ]);

        $package = app(PackagistService::class)->fetch('vendor/healthy');
        $package = app(GitHubService::class)->enrich($package);
        $report = app(HealthScoreService::class)->analyze($package);

        $array = $report->toArray();
        $json = json_encode($array);

        $this->assertIsString($json);
        $this->assertJson($json);
        $this->assertArrayHasKey('score', $array);
        $this->assertArrayHasKey('sub_scores', $array);
        $this->assertArrayHasKey('recommendation', $array);
        $this->assertArrayHasKey('data', $array);
    }
}
