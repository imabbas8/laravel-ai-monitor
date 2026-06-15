<?php

namespace Debug\AiHealth\Data;

/**
 * The result of analysing a package: the overall score, the five sub-scores
 * that produced it, a status label, a compatibility hint and a recommendation.
 */
class HealthReport
{
    public const STATUS_SAFE     = 'safe';
    public const STATUS_CAUTION  = 'caution';
    public const STATUS_RISKY    = 'risky';
    public const STATUS_ABANDONED = 'abandoned';

    /**
     * @param  array<string, int>  $subScores  e.g. ['maintenance' => 80, ...]
     * @param  array<string, array<int, string>>  $subScoreNotes  the factors
     *         behind each sub-score, e.g. ['maintenance' => ['pushed 2d ago → 100', ...]]
     */
    public function __construct(
        public PackageData $package,
        public int $score,
        public string $status,
        public array $subScores,
        public string $compatibilityHint,
        public string $recommendation,
        public array $subScoreNotes = [],
        public ?string $aiExplanation = null,
    ) {
    }

    public function isActive(): bool
    {
        return ! $this->package->abandoned && ! $this->package->archived;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_SAFE      => 'Active / Safe',
            self::STATUS_CAUTION   => 'Active / Use with caution',
            self::STATUS_RISKY     => 'Risky',
            self::STATUS_ABANDONED => 'Abandoned',
            default                => ucfirst($this->status),
        };
    }

    public function toArray(): array
    {
        return [
            'package'            => $this->package->name,
            'score'              => $this->score,
            'status'             => $this->status,
            'status_label'       => $this->statusLabel(),
            'sub_scores'         => $this->subScores,
            'sub_score_breakdown' => $this->subScoreNotes,
            'compatibility_hint' => $this->compatibilityHint,
            'recommendation'     => $this->recommendation,
            'ai_explanation'     => $this->aiExplanation,
            'data'               => $this->package->toArray(),
        ];
    }
}
