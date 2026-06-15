<?php

namespace Debug\AiHealth\Services;

use Debug\AiHealth\Data\HealthReport;
use Throwable;

/**
 * Optional layer that turns the numeric analysis into a short, plain-language
 * verdict using whichever AI provider the user configured (Anthropic Claude,
 * OpenAI / OpenAI-compatible, Gemini, ...).
 *
 * It degrades gracefully: if the feature is disabled, no provider/key is set,
 * or the call fails, it returns null and the CLI falls back to the rule-based
 * recommendation.
 */
class AiExplanationService
{
    public function __construct(
        protected AiProviderFactory $factory,
        protected array $config = [],
    ) {
    }

    /**
     * Whether an AI verdict can be produced with the given (or default) provider.
     */
    public function enabled(?string $provider = null, ?string $model = null): bool
    {
        if (! ($this->config['enabled'] ?? false)) {
            return false;
        }

        return $this->providerAvailable($provider, $model);
    }

    /**
     * Whether the resolved provider has the credentials it needs (independent
     * of the master `enabled` switch — used when --ai forces a run).
     */
    public function providerAvailable(?string $provider = null, ?string $model = null): bool
    {
        $driver = $this->factory->make($provider, ['model' => $model]);

        return $driver !== null && $driver->available();
    }

    public function explain(HealthReport $report, ?string $provider = null, ?string $model = null): ?string
    {
        $driver = $this->factory->make($provider, ['model' => $model]);

        if ($driver === null || ! $driver->available()) {
            return null;
        }

        try {
            return $driver->complete($this->systemPrompt(), $this->userPrompt($report));
        } catch (Throwable $e) {
            // AI is a nice-to-have; never let it break the health check.
            return null;
        }
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are a Laravel/PHP package-health advisor. You will be given raw metrics
        and a computed 0-100 health score for a Composer package. Write a short,
        plain-language verdict (3-5 sentences) for a developer deciding whether to
        install it. State clearly whether it looks SAFE, USE-WITH-CAUTION, or RISKY,
        and give the one or two concrete reasons that drove that call (maintenance
        recency, popularity, abandoned status, stability, open-issue load). Be direct
        and practical. Do not invent facts that are not in the data. Do not use Markdown
        headers — just a tight paragraph.
        PROMPT;
    }

    protected function userPrompt(HealthReport $report): string
    {
        $payload = [
            'score'              => $report->score,
            'status'             => $report->status,
            'sub_scores'         => $report->subScores,
            'compatibility_hint' => $report->compatibilityHint,
            'metrics'            => $report->package->toArray(),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return "Here is the analysis for the package \"{$report->package->name}\":\n\n{$json}\n\n"
            . 'Give your verdict.';
    }
}
