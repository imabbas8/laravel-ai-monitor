<?php

namespace Debug\AiHealth\Tests;

use Illuminate\Support\Facades\Http;

class PackageHealthCommandTest extends TestCase
{
    protected function fakeHealthyPackage(): void
    {
        Http::fake([
            'api.github.com/repos/*/stats/participation' => Http::response([
                'all' => array_fill(0, 52, 3), // ~36 commits in the last 12 weeks
            ], 200),
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
                    'name'        => 'vendor/healthy',
                    'description' => 'A healthy package',
                    'downloads'   => ['total' => 200000000, 'monthly' => 9000000, 'daily' => 300000],
                    'favers'      => 12000,
                    'repository'  => 'https://github.com/vendor/healthy',
                    'versions'    => [
                        'v6.0.0' => ['require' => ['php' => '^8.0', 'laravel/framework' => '^10.0|^11.0']],
                        'v5.0.0' => ['require' => ['php' => '^8.0']],
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_it_reports_a_healthy_package(): void
    {
        $this->fakeHealthyPackage();

        $this->artisan('package:health', ['package' => 'vendor/healthy'])
            ->assertExitCode(0)
            ->expectsOutputToContain('vendor/healthy')
            ->expectsOutputToContain('Safe to install.');
    }

    public function test_it_outputs_json_without_error(): void
    {
        $this->fakeHealthyPackage();

        // The JSON body itself (shape + parseability) is asserted in
        // PackageServicesTest::test_full_report_array_shape.
        $this->artisan('package:health', ['package' => 'vendor/healthy', '--json' => true])
            ->assertExitCode(0)
            ->expectsOutputToContain('"score"');
    }

    public function test_it_flags_an_abandoned_package(): void
    {
        Http::fake([
            'api.github.com/repos/*' => Http::response([
                'stargazers_count'  => 8000,
                'forks_count'       => 900,
                'open_issues_count' => 10,
                'pushed_at'         => date('c', strtotime('-400 days')),
                'archived'          => false,
                'license'           => ['spdx_id' => 'MIT'],
            ], 200),
            'packagist.org/*' => Http::response([
                'package' => [
                    'name'       => 'vendor/abandoned',
                    'downloads'  => ['total' => 50000000, 'monthly' => 1000000, 'daily' => 30000],
                    'favers'     => 5000,
                    'abandoned'  => 'vendor/replacement',
                    'repository' => 'https://github.com/vendor/abandoned',
                    'versions'   => [
                        'v3.0.0' => ['require' => ['php' => '^8.0']],
                    ],
                ],
            ], 200),
        ]);

        // Note: only one expectsOutputToContain per output line — Laravel matches
        // each substring against a separate write call. "Abandoned" is the status
        // badge line; "ABANDONED" is the recommendation line. The replacement
        // text itself is asserted at the unit level in HealthScoreServiceTest.
        $this->artisan('package:health', ['package' => 'vendor/abandoned'])
            ->assertExitCode(0)
            ->expectsOutputToContain('Abandoned')
            ->expectsOutputToContain('ABANDONED');
    }

    public function test_it_fails_for_a_missing_package(): void
    {
        Http::fake([
            'packagist.org/*' => Http::response('Not Found', 404),
        ]);

        $this->artisan('package:health', ['package' => 'vendor/does-not-exist'])
            ->assertExitCode(1)
            ->expectsOutputToContain('was not found on Packagist');
    }

    public function test_it_rejects_an_invalid_name(): void
    {
        $this->artisan('package:health', ['package' => 'not-a-valid-name'])
            ->assertExitCode(1)
            ->expectsOutputToContain('Invalid package name');
    }

    public function test_it_appends_an_ai_verdict_with_a_provider(): void
    {
        config()->set('ai-health.ai.enabled', true);
        config()->set('ai-health.ai.provider', 'openai');
        config()->set('ai-health.ai.providers.openai.api_key', 'sk-test');

        $this->fakeHealthyPackage();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'This package is SAFE to install.']]],
            ], 200),
            'api.github.com/repos/*/stats/participation' => Http::response(['all' => array_fill(0, 52, 3)], 200),
            'api.github.com/repos/*' => Http::response([
                'stargazers_count' => 12000, 'forks_count' => 1200, 'open_issues_count' => 20,
                'pushed_at' => date('c', strtotime('-5 days')), 'archived' => false,
                'license' => ['spdx_id' => 'MIT'],
            ], 200),
            'packagist.org/*' => Http::response([
                'package' => [
                    'name' => 'vendor/healthy',
                    'downloads' => ['total' => 200000000, 'monthly' => 9000000, 'daily' => 300000],
                    'favers' => 12000, 'repository' => 'https://github.com/vendor/healthy',
                    'versions' => ['v6.0.0' => ['require' => ['php' => '^8.0']]],
                ],
            ], 200),
        ]);

        $this->artisan('package:health', ['package' => 'vendor/healthy', '--ai' => true, '--provider' => 'openai'])
            ->assertExitCode(0)
            ->expectsOutputToContain('AI verdict');
    }
}
