<?php

namespace Debug\AiHealth\Services\Providers;

use Debug\AiHealth\Contracts\AiProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;

/**
 * Google Gemini provider, using the native generateContent endpoint.
 */
class GeminiProvider implements AiProvider
{
    public function __construct(
        protected HttpFactory $http,
        protected array $config = [],
    ) {
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function available(): bool
    {
        return ! empty($this->config['api_key']);
    }

    public function complete(string $system, string $user): ?string
    {
        $base  = rtrim((string) Arr::get($this->config, 'base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $model = Arr::get($this->config, 'model', 'gemini-2.0-flash');

        $response = $this->http
            ->timeout((int) Arr::get($this->config, 'timeout', 60))
            ->withHeaders([
                'x-goog-api-key' => (string) $this->config['api_key'],
                'content-type'   => 'application/json',
            ])
            ->post("{$base}/models/{$model}:generateContent", [
                'systemInstruction' => [
                    'parts' => [['text' => $system]],
                ],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $user]]],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => (int) Arr::get($this->config, 'max_tokens', 600),
                ],
            ]);

        if (! $response->successful()) {
            return null;
        }

        $text = trim((string) $response->json('candidates.0.content.parts.0.text', ''));

        return $text !== '' ? $text : null;
    }
}
