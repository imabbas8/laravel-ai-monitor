<?php

namespace Debug\AiHealth\Services;

use Debug\AiHealth\Contracts\AiProvider;
use Debug\AiHealth\Services\Providers\AnthropicProvider;
use Debug\AiHealth\Services\Providers\GeminiProvider;
use Debug\AiHealth\Services\Providers\OpenAiProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;

/**
 * Builds the right AiProvider driver from config. Any provider listed under
 * ai.providers can be selected; unknown providers are treated as
 * OpenAI-compatible (so a custom base_url + key connects any such endpoint).
 */
class AiProviderFactory
{
    public function __construct(
        protected HttpFactory $http,
        protected array $aiConfig = [],
    ) {
    }

    /**
     * Resolve a provider by key, falling back to the configured default.
     *
     * Any number of providers can be defined under ai.providers — there is no
     * fixed list. Each one chooses how its requests are shaped via an optional
     * `driver` key (anthropic | gemini | openai). When `driver` is omitted the
     * provider key itself is used, and anything that isn't anthropic/gemini is
     * treated as an OpenAI-compatible endpoint. This means you can connect
     * unlimited providers and models — just point base_url + model + api_key
     * (and optionally `driver`) at whatever AI you have.
     *
     * @param  array<string, mixed>  $overrides  Per-run overrides, e.g. ['model' => '...'].
     */
    public function make(?string $provider = null, array $overrides = []): ?AiProvider
    {
        $provider = $provider ?: (string) Arr::get($this->aiConfig, 'provider', 'anthropic');

        $config = Arr::get($this->aiConfig, "providers.{$provider}");

        // Allow an entirely ad-hoc provider supplied purely via overrides
        // (e.g. base_url + model + api_key passed at runtime).
        if (! is_array($config)) {
            $config = [];
        }

        $config = array_merge($config, array_filter(
            $overrides,
            fn ($value) => $value !== null && $value !== ''
        ));

        if (empty($config['api_key'])) {
            return null;
        }

        // Carry the shared max_tokens budget down into the provider config.
        $config['max_tokens'] = $config['max_tokens'] ?? Arr::get($this->aiConfig, 'max_tokens', 600);

        $driver = (string) ($config['driver'] ?? $provider);

        return match ($driver) {
            'anthropic' => new AnthropicProvider($this->http, $config),
            'gemini'    => new GeminiProvider($this->http, $config),
            // 'openai' and any unknown driver use the OpenAI-compatible shape
            // (groq, together, openrouter, mistral, ollama, local gateway, ...).
            default     => new OpenAiProvider($this->http, $config, $provider),
        };
    }

    /**
     * @return array<int, string>
     */
    public function configuredProviders(): array
    {
        $providers = (array) Arr::get($this->aiConfig, 'providers', []);

        return array_keys(array_filter(
            $providers,
            fn ($config) => is_array($config) && ! empty($config['api_key'])
        ));
    }
}
