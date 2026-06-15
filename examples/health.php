<?php

/*
|------------------------------------------------------------------------------
| Standalone REAL run — no Laravel app required.
|------------------------------------------------------------------------------
|
| This script boots the package's services with a real HTTP client and hits the
| LIVE Packagist + GitHub APIs. It is the quickest way to see real output while
| developing this package (the phpunit suite is mocked on purpose).
|
| Usage:
|   php examples/health.php vendor/package
|   AI_HEALTH_GITHUB_TOKEN=ghp_xxx php examples/health.php laravel/framework
|
*/

require __DIR__ . '/../vendor/autoload.php';

use Composer\Semver\Semver;
use Debug\AiHealth\Services\GitHubService;
use Debug\AiHealth\Services\HealthScoreService;
use Debug\AiHealth\Services\PackagistService;
use Illuminate\Http\Client\Factory as HttpFactory;

$arg = $argv[1] ?? null;

if (! $arg) {
    fwrite(STDERR, "Usage: php examples/health.php vendor/package[:constraint] [laravelVersion]\n");
    fwrite(STDERR, "   e.g. php examples/health.php laravel/framework\n");
    fwrite(STDERR, "        php examples/health.php laravel/framework:^13.8\n");
    fwrite(STDERR, "        php examples/health.php spatie/laravel-permission 11   (check Laravel 11 support)\n");
    exit(1);
}

// Accept Composer-style "vendor/package:^13.8" — split the constraint off the
// name (the name itself never contains a colon).
[$package, $constraint] = array_pad(explode(':', $arg, 2), 2, null);

// Optional 2nd argument: the Laravel version YOU are running, e.g. "11" or "10.4".
$laravelVersion = $argv[2] ?? null;

// The config file uses env(); make a tiny shim so it works outside Laravel.
if (! function_exists('env')) {
    function env($key, $default = null)
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}

$config = require __DIR__ . '/../config/ai-health.php';

$http = new HttpFactory();

$packagist = new PackagistService($http, $config);
$github     = new GitHubService($http, $config);
$scorer     = new HealthScoreService($config);

try {
    $data = $packagist->fetch($package);   // REAL Packagist call
    $data = $github->enrich($data);        // REAL GitHub call
    $report = $scorer->analyze($data);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\n  " . $report->package->name . "\n";
if ($report->package->description) {
    echo '  ' . $report->package->description . "\n";
}
echo "\n  HEALTH {$report->score}/100   " . $report->statusLabel() . "\n\n";

foreach ($report->subScores as $label => $value) {
    $filled = (int) round($value / 10);
    $bar = str_repeat('#', $filled) . str_repeat('.', 10 - $filled);
    echo sprintf("  %-13s %s %d/100\n", ucfirst($label), $bar, $value);
}

echo "\n";
echo '  Total downloads ... ' . number_format($report->package->totalDownloads) . "\n";
echo '  Monthly downloads . ' . number_format($report->package->monthlyDownloads) . "\n";
echo '  GitHub stars ...... ' . ($report->package->stars !== null ? number_format($report->package->stars) : 'n/a') . "\n";
echo '  Open issues ....... ' . ($report->package->openIssues !== null ? number_format($report->package->openIssues) : 'n/a') . "\n";
echo '  Latest version .... ' . ($report->package->latestVersion ?? 'n/a') . "\n";
echo '  Last updated ...... ' . ($report->package->daysSinceLastPush() !== null ? $report->package->daysSinceLastPush() . ' days ago' : 'n/a') . "\n";
echo "\n  " . $report->recommendation . "\n";

// --- Version constraint check (only when vendor/package:constraint was given) --
if ($constraint !== null && $constraint !== '') {
    $allVersions = packageVersions($package);
    $matching = Semver::satisfiedBy($allVersions, $constraint);

    // Prefer a real, stable tag over dev-branch aliases like "13.x-dev".
    $stableMatches = array_filter(
        $matching,
        fn ($v) => ! preg_match('/-(dev|alpha|beta|rc|pre)/i', $v) && ! str_starts_with(strtolower($v), 'dev-')
    );

    $best = Semver::rsort($stableMatches ?: $matching);

    echo "\n  Version match for constraint \"{$constraint}\":\n";
    echo '    Latest available .. ' . ($report->package->latestVersion ?? 'n/a') . "\n";

    if (empty($best)) {
        echo "    Best match ........ NONE — no released version satisfies this constraint\n";
    } else {
        echo '    Best match ........ ' . $best[0] . "  (composer will install this)\n";
        echo '    Total matching .... ' . count($matching) . ' version(s)' . "\n";
    }
}

// --- Will it work on MY Laravel version? --------------------------------------
if ($laravelVersion !== null && $laravelVersion !== '') {
    $constraints = $report->package->laravelConstraints;

    echo "\n  Compatibility with Laravel {$laravelVersion}:\n";

    if (empty($constraints)) {
        echo "    No explicit Laravel/Illuminate constraint declared — likely\n";
        echo "    framework-agnostic, should work but test it yourself.\n";
    } else {
        $target = normalizeVersion($laravelVersion);
        $allOk = true;

        foreach ($constraints as $dependency => $range) {
            $ok = Semver::satisfies($target, $range);
            $allOk = $allOk && $ok;
            echo sprintf("    %-22s requires %-22s %s\n", $dependency, $range, $ok ? 'OK' : 'NOT compatible');
        }

        echo $allOk
            ? "    => YES, this package supports Laravel {$laravelVersion}.\n"
            : "    => NO, this release does NOT support Laravel {$laravelVersion} — pick another version/package.\n";
    }
}

// --- Sources (proof — verify every number yourself) ---------------------------
echo "\n  Sources (yahan ja ke khud verify karo):\n";
echo '    Packagist : https://packagist.org/packages/' . $report->package->name . "\n";
echo '    Raw data  : https://packagist.org/packages/' . $report->package->name . ".json\n";
if ($report->package->repositoryUrl) {
    $ghApi = preg_replace('#https?://github\.com/([^/]+/[^/.]+).*#', 'https://api.github.com/repos/$1', $report->package->repositoryUrl);
    echo '    GitHub    : ' . $report->package->repositoryUrl . "\n";
    echo '    Raw data  : ' . $ghApi . "\n";
}

// --- Alternatives (same keywords, better / more-used options) -----------------
$keywords = packageKeywords($package);
$alts = suggestAlternatives($keywords, $report->package->name);

echo "\n  Alternatives (same keywords, ranked by usage):\n";
if (empty($alts)) {
    echo "    (no similar packages found)\n";
} else {
    foreach ($alts as $a) {
        $flag = $a['downloads'] > $report->package->totalDownloads ? '  <- more widely used' : '';
        $version = latestStableVersion($a['name']) ?? 'n/a';
        echo sprintf("    %-34s %-10s %14s downloads%s\n", $a['name'], $version, number_format($a['downloads']), $flag);
    }
}
echo "\n";

/**
 * Turn "11" or "10.4" into a full "x.y.z" string Semver can test.
 */
function normalizeVersion(string $version): string
{
    $parts = explode('.', ltrim($version, 'vV'));

    while (count($parts) < 3) {
        $parts[] = '0';
    }

    return implode('.', array_slice($parts, 0, 3));
}

/**
 * All released version strings for a package, straight from Packagist.
 *
 * @return array<int, string>
 */
function packageVersions(string $package): array
{
    $json = @file_get_contents("https://packagist.org/packages/{$package}.json");

    if (! $json) {
        return [];
    }

    return array_keys(json_decode($json, true)['package']['versions'] ?? []);
}

/**
 * Newest stable (non dev / pre-release) tag for a package, for display.
 */
function latestStableVersion(string $package): ?string
{
    $versions = array_map(fn ($v) => ltrim($v, 'vV'), packageVersions($package));

    $stable = array_filter(
        $versions,
        fn ($v) => ! preg_match('/-(dev|alpha|beta|rc|pre)/i', $v) && ! str_starts_with(strtolower($v), 'dev-')
    );

    $pool = $stable ?: $versions;

    if (empty($pool)) {
        return null;
    }

    usort($pool, 'version_compare');

    return 'v' . end($pool);
}

/**
 * Read the latest version's keywords straight from the Packagist JSON.
 */
function packageKeywords(string $package): array
{
    $json = @file_get_contents("https://packagist.org/packages/{$package}.json");

    if (! $json) {
        return [];
    }

    $versions = json_decode($json, true)['package']['versions'] ?? [];
    $latest = reset($versions) ?: [];

    return $latest['keywords'] ?? [];
}

/**
 * Search Packagist by the package's keywords, drop the package itself and
 * generic terms, then return the most-used similar packages.
 *
 * @return array<int, array{name: string, downloads: int, favers: int}>
 */
function suggestAlternatives(array $keywords, string $currentName): array
{
    $stop = ['php', 'dev', 'laravel', 'library', 'package', 'framework', 'tool', 'utility'];
    $terms = array_values(array_filter($keywords, fn ($k) => ! in_array(strtolower($k), $stop, true)));
    $query = implode(' ', array_slice($terms ?: $keywords, 0, 2));

    if ($query === '') {
        return [];
    }

    $json = @file_get_contents('https://packagist.org/search.json?q=' . urlencode($query) . '&per_page=15');

    if (! $json) {
        return [];
    }

    $alts = [];

    foreach (json_decode($json, true)['results'] ?? [] as $r) {
        if (! isset($r['name']) || strtolower($r['name']) === strtolower($currentName)) {
            continue;
        }

        $alts[] = [
            'name'      => $r['name'],
            'downloads' => (int) ($r['downloads'] ?? 0),
            'favers'    => (int) ($r['favers'] ?? 0),
        ];
    }

    usort($alts, fn ($a, $b) => $b['downloads'] <=> $a['downloads']);

    return array_slice($alts, 0, 4);
}
