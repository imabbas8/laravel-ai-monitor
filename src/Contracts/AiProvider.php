<?php

namespace Debug\AiHealth\Contracts;

/**
 * Contract every AI provider driver implements. A provider takes a system
 * prompt and a user prompt and returns a plain-text completion (or null on
 * failure / when not configured).
 */
interface AiProvider
{
    /**
     * The provider's short key, e.g. "anthropic", "openai", "gemini".
     */
    public function name(): string;

    /**
     * Whether the provider is configured well enough to call (has an API key).
     */
    public function available(): bool;

    /**
     * Send the prompts and return the model's text answer, or null on failure.
     */
    public function complete(string $system, string $user): ?string;
}
