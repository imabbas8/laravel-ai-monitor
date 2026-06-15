<?php

namespace Debug\AiHealth\Commands;

use Debug\AiHealth\Data\HealthReport;
use Debug\AiHealth\Exceptions\PackageNotFoundException;
use Debug\AiHealth\Services\AiExplanationService;
use Debug\AiHealth\Services\GitHubService;
use Debug\AiHealth\Services\HealthScoreService;
use Debug\AiHealth\Services\PackagistService;
use Debug\AiHealth\Services\VersionService;
use Illuminate\Console\Command;
use Throwable;

class PackageHealthCommand extends Command
{
    protected ?string $constraint = null;

    protected PackagistService $packagist;

    protected VersionService $versions;
    protected $signature = 'package:health
        {package : The package, e.g. vendor/package or vendor/package:^1.27}
        {--laravel= : Laravel version to check compatibility against (defaults to your app)}
        {--explain : Show exactly how each sub-score and the final score were derived}
        {--ai : Generate a human-friendly AI explanation (overrides config)}
        {--no-ai : Disable the AI explanation even if enabled in config}
        {--provider= : AI provider to use, e.g. anthropic, openai, gemini (defaults to config)}
        {--model= : Override the AI model for this run (any model the provider supports)}
        {--json : Output the full report as JSON}';

    protected $description = 'Check the health (0-100 score) of a PHP/Laravel package before installing it.';

    public function handle(
        PackagistService $packagist,
        GitHubService $github,
        HealthScoreService $scorer,
        AiExplanationService $ai,
        VersionService $versions
    ): int {
        $this->packagist = $packagist;
        $this->versions = $versions;

        [$package, $this->constraint] = $this->parseArgument((string) $this->argument('package'));

        if (! $this->isValidName($package)) {
            $this->error('Invalid package name. Use the "vendor/package" format (e.g. laravel/pint or laravel/pint:^1.27).');

            return self::FAILURE;
        }

        try {
            $report = $this->buildReport($package, $packagist, $github, $scorer);
        } catch (PackageNotFoundException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error("Could not analyze [{$package}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($this->shouldRunAi($ai)) {
            $report->aiExplanation = $this->runAi($ai, $report);
        }

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($report);

        return self::SUCCESS;
    }

    protected function buildReport(
        string $package,
        PackagistService $packagist,
        GitHubService $github,
        HealthScoreService $scorer
    ): HealthReport {
        if (! $this->option('json')) {
            $this->line("<comment>→ Fetching Packagist data for {$package}...</comment>");
        }

        $data = $packagist->fetch($package);

        if (! $this->option('json')) {
            $this->line('<comment>→ Fetching GitHub signals...</comment>');
        }

        $data = $github->enrich($data);

        return $scorer->analyze($data);
    }

    protected function render(HealthReport $report): void
    {
        $package = $report->package;

        $this->output->newLine();
        $this->line('  <fg=cyan;options=bold>📦 ' . $package->name . '</>');

        if ($package->description) {
            $this->line('  <fg=gray>' . $package->description . '</>');
        }

        $this->output->newLine();
        $this->line('  ' . $this->scoreBadge($report) . '  ' . $this->statusBadge($report));
        $this->output->newLine();

        foreach ($report->subScores as $label => $value) {
            $this->line(sprintf(
                '  %-13s %s <fg=gray>%d/100</>',
                ucfirst($label),
                $this->bar($value),
                $value
            ));

            $notes = $report->subScoreNotes[$label] ?? [];

            if (! empty($notes)) {
                $this->line('              <fg=gray>'
                    . str_replace(['<', '>'], ['', ''], implode('  ·  ', $notes))
                    . '</>');
            }
        }

        if ($this->option('explain')) {
            $this->renderExplain($report);
        }

        $this->output->newLine();

        $this->detail('Total downloads', number_format($package->totalDownloads));
        $this->detail('Monthly downloads', number_format($package->monthlyDownloads));
        $this->detail('GitHub stars', $package->stars !== null ? number_format($package->stars) : 'n/a');
        $this->detail('Open issues', $package->openIssues !== null ? number_format($package->openIssues) : 'n/a');
        $this->detail('Latest version', $package->latestVersion ?? 'n/a');
        $this->detail(
            'Last updated',
            $package->daysSinceLastPush() !== null ? $package->daysSinceLastPush() . ' days ago' : 'n/a'
        );

        $this->output->newLine();
        $this->line('  <options=bold>Compatibility</>');
        $this->line('  <fg=gray>' . $report->compatibilityHint . '</>');

        $this->output->newLine();
        $this->line('  <options=bold>Recommendation</>');
        $this->line('  ' . $this->colorForStatus($report->status, $report->recommendation));

        $this->renderVersionMatch($report);
        $this->renderLaravelCompatibility($report);
        $this->renderSources($report);
        $this->renderAlternatives($report);

        if ($report->aiExplanation) {
            $this->output->newLine();
            $this->line('  <fg=magenta;options=bold>🤖 AI verdict</>');
            foreach ($this->wrap($report->aiExplanation, 78) as $line) {
                $this->line('  <fg=gray>' . $line . '</>');
            }
        }

        $this->output->newLine();
    }

    /**
     * Render a label/value detail row with dot leaders. Done manually because
     * the $this->components->twoColumnDetail helper only exists on Laravel 9+.
     */
    protected function detail(string $label, string $value): void
    {
        $width = 60;
        $label = '  ' . $label . ' ';
        $value = ' ' . $value;
        $dots = max(1, $width - mb_strlen($label) - mb_strlen($value));

        $this->line($label . '<fg=gray>' . str_repeat('.', $dots) . '</>' . $value);
    }

    protected function shouldRunAi(AiExplanationService $ai): bool
    {
        if ($this->option('no-ai')) {
            return false;
        }

        // --ai, --provider or --model forces an attempt; otherwise honour the
        // master `enabled` switch in config.
        if ($this->option('ai') || $this->option('provider') || $this->option('model')) {
            return true;
        }

        return $ai->enabled($this->aiProvider(), $this->aiModel());
    }

    protected function runAi(AiExplanationService $ai, HealthReport $report): ?string
    {
        $provider = $this->aiProvider();
        $model = $this->aiModel();

        if (! $ai->providerAvailable($provider, $model)) {
            $this->warn('AI explanation requested but no API key is configured for the selected provider. Set the provider key in config/ai-health.php (or .env).');

            return null;
        }

        if (! $this->option('json')) {
            $this->line('<comment>→ Generating AI verdict...</comment>');
        }

        return $ai->explain($report, $provider, $model);
    }

    protected function aiProvider(): ?string
    {
        $provider = $this->option('provider');

        return $provider !== null && $provider !== '' ? (string) $provider : null;
    }

    protected function aiModel(): ?string
    {
        $model = $this->option('model');

        return $model !== null && $model !== '' ? (string) $model : null;
    }

    protected function scoreBadge(HealthReport $report): string
    {
        $color = $this->scoreColor($report->score);

        return "<fg=white;bg={$color};options=bold> HEALTH {$report->score}/100 </>";
    }

    protected function statusBadge(HealthReport $report): string
    {
        return $this->colorForStatus($report->status, $report->statusLabel());
    }

    protected function colorForStatus(string $status, string $text): string
    {
        $color = match ($status) {
            HealthReport::STATUS_SAFE    => 'green',
            HealthReport::STATUS_CAUTION => 'yellow',
            default                      => 'red',
        };

        return "<fg={$color};options=bold>{$text}</>";
    }

    protected function bar(int $value): string
    {
        $filled = (int) round($value / 10);
        $color = $this->scoreColor($value);

        return "<fg={$color}>" . str_repeat('█', $filled) . '</>'
            . '<fg=gray>' . str_repeat('░', 10 - $filled) . '</>';
    }

    protected function scoreColor(int $value): string
    {
        return match (true) {
            $value >= 75 => 'green',
            $value >= 50 => 'yellow',
            default      => 'red',
        };
    }

    /**
     * @return array<int, string>
     */
    protected function wrap(string $text, int $width): array
    {
        return explode("\n", wordwrap($text, $width, "\n", true));
    }

    protected function isValidName(string $name): bool
    {
        return (bool) preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$#i', $name);
    }

    /**
     * Split a "vendor/package:^1.27" argument into [name, constraint]. Tolerates
     * stray quotes/whitespace a user may paste in. Constraint is null when none.
     *
     * @return array{0: string, 1: ?string}
     */
    protected function parseArgument(string $raw): array
    {
        $raw = trim(trim($raw), "\"'");

        if (! str_contains($raw, ':')) {
            return [strtolower($raw), null];
        }

        [$name, $constraint] = explode(':', $raw, 2);

        $name = strtolower(trim(trim($name), "\"'"));
        $constraint = trim(trim($constraint), "\"'");

        return [$name, $constraint !== '' ? $constraint : null];
    }

    /**
     * The Laravel version to test compatibility against: the --laravel option,
     * otherwise the version of the app this command is running inside.
     */
    protected function laravelTarget(): ?string
    {
        $option = $this->option('laravel');

        if ($option !== null && $option !== '') {
            return (string) $option;
        }

        $app = $this->laravel;

        if (is_object($app) && method_exists($app, 'version')) {
            return (string) $app->version();
        }

        return null;
    }

    /**
     * Show the full weighted derivation of the final score: each sub-score, its
     * configured weight, and its contribution to the total.
     */
    protected function renderExplain(HealthReport $report): void
    {
        $weights = (array) config('ai-health.weights', []);
        $totalWeight = array_sum($weights) ?: 100;

        $this->output->newLine();
        $this->line('  <options=bold>Score derivation</> <fg=gray>(weights from config/ai-health.php)</>');

        $running = 0.0;

        foreach ($report->subScores as $label => $value) {
            $weight = (float) ($weights[$label] ?? 0);
            $contribution = $value * $weight / $totalWeight;
            $running += $contribution;

            $this->line(sprintf(
                '  %-12s <fg=gray>%3d × %d%% =</> %5.1f',
                ucfirst($label),
                $value,
                (int) round($weight / $totalWeight * 100),
                $contribution
            ));
        }

        $this->line('  <fg=gray>' . str_repeat('─', 28) . '</>');
        $this->line(sprintf('  <options=bold>Weighted total</> = %d/100', (int) round($running)));

        if ($report->package->abandoned || $report->package->archived) {
            $cap = (int) config('ai-health.scoring.abandoned_cap', 25);
            $this->line("  <fg=red>Capped at {$cap} (abandoned/archived).</>");
        }
    }

    protected function renderVersionMatch(HealthReport $report): void
    {
        if ($this->constraint === null) {
            return;
        }

        $this->output->newLine();
        $this->line('  <options=bold>Version match for "' . $this->constraint . '"</>');

        $best = $this->versions->resolveConstraint($report->package->versions, $this->constraint);

        if ($best === null) {
            $this->line('  <fg=red>No released version satisfies this constraint.</>');

            return;
        }

        $count = $this->versions->countMatching($report->package->versions, $this->constraint);

        $this->line('  <fg=green>' . $best . '</> <fg=gray>will be installed (' . $count . ' version(s) match).</>');
    }

    protected function renderLaravelCompatibility(HealthReport $report): void
    {
        $target = $this->laravelTarget();

        if ($target === null) {
            return;
        }

        // Was the version typed with --laravel, or auto-detected from this app?
        $option = $this->option('laravel');
        $autoDetected = ($option === null || $option === '');

        // "13.15.0" -> "13" for a clean, human heading.
        $major = explode('.', ltrim($target, 'vV'))[0];
        $label = $autoDetected
            ? "your current Laravel {$major}"
            : "Laravel {$major}";

        $this->output->newLine();
        $this->line('  <options=bold>Will it work on ' . $label . '?</>'
            . ($autoDetected ? ' <fg=gray>(auto-detected ' . $target . ')</>' : ''));

        $normalized = $this->versions->normalize($target);
        $latestOk = $this->versions->constraintsAllow($report->package->laravelConstraints, $normalized);

        if ($latestOk) {
            $this->line('  <fg=green;options=bold>✔ YES</> <fg=gray>— the latest release ('
                . ($report->package->latestVersion ?? 'latest') . ') works on ' . $label . '. Safe to install.</>');

            return;
        }

        $this->line('  <fg=red;options=bold>✘ NO</> <fg=gray>— the latest release ('
            . ($report->package->latestVersion ?? 'latest') . ') does not support ' . $label . '.</>');

        $best = $this->versions->bestVersionForLaravel($report->package->versionConstraints, $target);

        if ($best !== null) {
            $this->line('  <fg=gray>→ Install</> <fg=green;options=bold>'
                . $report->package->name . ':' . $best . '</> <fg=gray>instead — it supports ' . $label . '.</>');
        } else {
            $this->line('  <fg=gray>→ No released version of this package supports ' . $label . '.</>');
        }
    }

    protected function renderSources(HealthReport $report): void
    {
        $package = $report->package;

        $this->output->newLine();
        $this->line('  <options=bold>Sources</> <fg=gray>(verify every number yourself)</>');
        $this->line('  <fg=gray>Packagist:</> https://packagist.org/packages/' . $package->name);

        if ($package->repositoryUrl) {
            $this->line('  <fg=gray>GitHub:</>    ' . $package->repositoryUrl);
        }
    }

    protected function renderAlternatives(HealthReport $report): void
    {
        $alternatives = $this->packagist->search($report->package);

        $this->output->newLine();

        if (empty($alternatives)) {
            // Honest fallback — we never invent unrelated suggestions.
            $this->line('  <options=bold>Alternatives</>');
            $this->line('  <fg=gray>No confident alternatives found.</>');

            return;
        }

        $curated = ($alternatives[0]['source'] ?? '') === 'curated';
        $label = $curated ? 'hand-picked' : 'similar packages, by relevance';

        $this->line('  <options=bold>Alternatives</> <fg=gray>(' . $label . ')</>');

        foreach ($alternatives as $alt) {
            $flag = $alt['downloads'] > $report->package->totalDownloads
                ? ' <fg=yellow>← more widely used</>'
                : '';

            $this->line(sprintf(
                '  <fg=cyan>%-38s</> <fg=gray>%s downloads</>%s',
                $alt['name'],
                number_format($alt['downloads']),
                $flag
            ));
        }
    }
}
