<?php

namespace Debug\AiHealth\Services\Providers;

use Debug\AiHealth\Contracts\AiProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;

/**
 * Anthropic Claude provider (default). Calls the Messages API over HTTP, so no
 * SDK dependency is required. Default model: claude-opus-4-8.
 */
class AnthropicProvider implements AiProvider
{
    public function __construct(
        protected HttpFactory $http,
        protected array $config = [],
    ) {
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function available(): bool
    {
        return ! empty($this->config['api_key']);
    }

    public function complete(string $system, string $user): ?string
    {
        $base = rtrim((string) Arr::get($this->config, 'base_url', 'https://api.anthropic.com/v1'), '/');

        $response = $this->http
            ->timeout((int) Arr::get($this->config, 'timeout', 60))
            ->withHeaders([
                'x-api-key'         => (string) $this->config['api_key'],
                'anthropic-version' => (string) Arr::get($this->config, 'version', '2023-06-01'),
                'content-type'      => 'application/json',
            ])
            ->post("{$base}/messages", [
                'model'      => Arr::get($this->config, 'model', 'claude-opus-4-8'),
                'max_tokens' => (int) Arr::get($this->config, 'max_tokens', 600),
                'system'     => $system,
                'messages'   => [
                    ['role' => 'user', 'content' => $user],
                ],
            ]);

        if (! $response->successful()) {
            return null;
        }

        $text = '';

        foreach ($response->json('content', []) as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        $text = trim($text);

        return $text !== '' ? $text : null;
    }
}
