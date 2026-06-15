<?php

namespace Debug\AiHealth\Services\Providers;

use Debug\AiHealth\Contracts\AiProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;

/**
 * OpenAI provider — and, via the configurable base_url, any OpenAI-compatible
 * endpoint (Groq, Together, OpenRouter, Mistral, Ollama, LM Studio, local
 * gateways, etc.). Uses the standard /chat/completions shape.
 */
class OpenAiProvider implements AiProvider
{
    public function __construct(
        protected HttpFactory $http,
        protected array $config = [],
        protected string $name = 'openai',
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function available(): bool
    {
        return ! empty($this->config['api_key']);
    }

    public function complete(string $system, string $user): ?string
    {
        $base = rtrim((string) Arr::get($this->config, 'base_url', 'https://api.openai.com/v1'), '/');

        $response = $this->http
            ->timeout((int) Arr::get($this->config, 'timeout', 60))
            ->withToken((string) $this->config['api_key'])
            ->post("{$base}/chat/completions", [
                'model'      => Arr::get($this->config, 'model', 'gpt-4o'),
                'max_tokens' => (int) Arr::get($this->config, 'max_tokens', 600),
                'messages'   => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

        if (! $response->successful()) {
            return null;
        }

        $text = trim((string) $response->json('choices.0.message.content', ''));

        return $text !== '' ? $text : null;
    }
}
