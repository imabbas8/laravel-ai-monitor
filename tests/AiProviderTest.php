<?php

namespace Debug\AiHealth\Tests;

use Debug\AiHealth\Data\HealthReport;
use Debug\AiHealth\Data\PackageData;
use Debug\AiHealth\Services\AiExplanationService;
use Debug\AiHealth\Services\AiProviderFactory;
use Debug\AiHealth\Services\Providers\AnthropicProvider;
use Debug\AiHealth\Services\Providers\GeminiProvider;
use Debug\AiHealth\Services\Providers\OpenAiProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

class AiProviderTest extends TestCase
{
    protected function report(): HealthReport
    {
        return new HealthReport(
            package: new PackageData(name: 'vendor/pkg'),
            score: 80,
            status: HealthReport::STATUS_SAFE,
            subScores: ['maintenance' => 80, 'community' => 80, 'usage' => 80, 'stability' => 80, 'security' => 80],
            compatibilityHint: 'PHP ^8.0',
            recommendation: 'Safe to install.',
        );
    }

    protected function aiConfig(string $default, array $providers): array
    {
        return [
            'enabled'    => true,
            'provider'   => $default,
            'max_tokens' => 600,
            'providers'  => $providers,
        ];
    }

    public function test_factory_selects_each_known_provider(): void
    {
        $http = app(HttpFactory::class);

        $config = $this->aiConfig('anthropic', [
            'anthropic' => ['api_key' => 'a'],
            'openai'    => ['api_key' => 'b'],
            'gemini'    => ['api_key' => 'c'],
        ]);

        $factory = new AiProviderFactory($http, $config);

        $this->assertInstanceOf(AnthropicProvider::class, $factory->make('anthropic'));
        $this->assertInstanceOf(OpenAiProvider::class, $factory->make('openai'));
        $this->assertInstanceOf(GeminiProvider::class, $factory->make('gemini'));
    }

    public function test_factory_treats_unknown_provider_as_openai_compatible(): void
    {
        $http = app(HttpFactory::class);

        $factory = new AiProviderFactory($http, $this->aiConfig('groq', [
            'groq' => ['api_key' => 'x', 'base_url' => 'https://api.groq.com/openai/v1'],
        ]));

        $provider = $factory->make('groq');

        $this->assertInstanceOf(OpenAiProvider::class, $provider);
        $this->assertSame('groq', $provider->name());
    }

    public function test_factory_returns_null_for_unconfigured_provider(): void
    {
        $factory = new AiProviderFactory(app(HttpFactory::class), $this->aiConfig('anthropic', []));

        $this->assertNull($factory->make('nope'));
    }

    public function test_a_custom_named_provider_can_pick_any_driver(): void
    {
        $factory = new AiProviderFactory(app(HttpFactory::class), $this->aiConfig('anthropic', [
            // Arbitrary name, explicit driver — unlimited custom providers.
            'my-llm'    => ['api_key' => 'k', 'driver' => 'openai', 'base_url' => 'https://llm.example.com/v1'],
            'my-claude' => ['api_key' => 'k', 'driver' => 'anthropic'],
            'my-gemini' => ['api_key' => 'k', 'driver' => 'gemini'],
        ]));

        $this->assertInstanceOf(OpenAiProvider::class, $factory->make('my-llm'));
        $this->assertSame('my-llm', $factory->make('my-llm')->name());
        $this->assertInstanceOf(AnthropicProvider::class, $factory->make('my-claude'));
        $this->assertInstanceOf(GeminiProvider::class, $factory->make('my-gemini'));
    }

    public function test_model_can_be_overridden_at_runtime(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'ok']]],
            ], 200),
        ]);

        $config = $this->aiConfig('openai', [
            'openai' => ['api_key' => 'sk-test', 'model' => 'gpt-4o'],
        ]);

        $service = new AiExplanationService(new AiProviderFactory(app(HttpFactory::class), $config), $config);

        // Pass an arbitrary model at call time — any model the endpoint supports.
        $service->explain($this->report(), 'openai', 'o3-mini');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions')
            && $request['model'] === 'o3-mini');
    }

    public function test_configured_providers_lists_only_those_with_keys(): void
    {
        $factory = new AiProviderFactory(app(HttpFactory::class), $this->aiConfig('anthropic', [
            'anthropic' => ['api_key' => 'a'],
            'openai'    => ['api_key' => ''],
            'gemini'    => ['api_key' => 'c'],
        ]));

        $this->assertSame(['anthropic', 'gemini'], $factory->configuredProviders());
    }

    public function test_anthropic_provider_parses_response(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'This package looks SAFE.']],
            ], 200),
        ]);

        $service = new AiExplanationService(
            new AiProviderFactory(app(HttpFactory::class), $this->aiConfig('anthropic', [
                'anthropic' => ['api_key' => 'key', 'model' => 'claude-opus-4-8'],
            ])),
            $this->aiConfig('anthropic', ['anthropic' => ['api_key' => 'key']]),
        );

        $this->assertTrue($service->enabled());
        $this->assertSame('This package looks SAFE.', $service->explain($this->report()));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.anthropic.com')
            && $request['model'] === 'claude-opus-4-8'
            && $request->hasHeader('x-api-key', 'key'));
    }

    public function test_openai_provider_parses_response(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'USE WITH CAUTION.']]],
            ], 200),
        ]);

        $factory = new AiProviderFactory(app(HttpFactory::class), $this->aiConfig('openai', [
            'openai' => ['api_key' => 'sk-test', 'model' => 'gpt-4o'],
        ]));

        $service = new AiExplanationService($factory, $this->aiConfig('openai', [
            'openai' => ['api_key' => 'sk-test'],
        ]));

        $this->assertSame('USE WITH CAUTION.', $service->explain($this->report(), 'openai'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'chat/completions')
            && $request->hasHeader('Authorization', 'Bearer sk-test'));
    }

    public function test_gemini_provider_parses_response(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'RISKY package.']]]]],
            ], 200),
        ]);

        $factory = new AiProviderFactory(app(HttpFactory::class), $this->aiConfig('gemini', [
            'gemini' => ['api_key' => 'g-key', 'model' => 'gemini-2.0-flash'],
        ]));

        $service = new AiExplanationService($factory, $this->aiConfig('gemini', [
            'gemini' => ['api_key' => 'g-key'],
        ]));

        $this->assertSame('RISKY package.', $service->explain($this->report(), 'gemini'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-2.0-flash:generateContent')
            && $request->hasHeader('x-goog-api-key', 'g-key'));
    }

    public function test_provider_failure_returns_null(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response('error', 500),
        ]);

        $factory = new AiProviderFactory(app(HttpFactory::class), $this->aiConfig('anthropic', [
            'anthropic' => ['api_key' => 'key'],
        ]));

        $service = new AiExplanationService($factory, $this->aiConfig('anthropic', [
            'anthropic' => ['api_key' => 'key'],
        ]));

        $this->assertNull($service->explain($this->report()));
    }

    public function test_disabled_when_no_key(): void
    {
        $factory = new AiProviderFactory(app(HttpFactory::class), $this->aiConfig('anthropic', [
            'anthropic' => ['api_key' => ''],
        ]));

        $service = new AiExplanationService($factory, $this->aiConfig('anthropic', [
            'anthropic' => ['api_key' => ''],
        ]));

        $this->assertFalse($service->enabled());
        $this->assertFalse($service->providerAvailable());
        $this->assertNull($service->explain($this->report()));
    }
}
