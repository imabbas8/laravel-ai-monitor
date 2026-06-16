# Laravel AI Monitor

> Check the **health** of any PHP/Laravel package *before* you install it — a 0–100 score from live Packagist + GitHub data, with an optional plain-language AI verdict from **any** AI provider.

[![Latest Version](https://img.shields.io/packagist/v/laravel-ai-monitor/laravel-ai-monitor.svg)](https://packagist.org/packages/laravel-ai-monitor/laravel-ai-monitor)
[![Total Downloads](https://img.shields.io/packagist/dt/laravel-ai-monitor/laravel-ai-monitor.svg)](https://packagist.org/packages/laravel-ai-monitor/laravel-ai-monitor)
[![License](https://img.shields.io/packagist/l/laravel-ai-monitor/laravel-ai-monitor.svg)](LICENSE)

**By:** [laravel-ai-monitor — debugflow.com](https://debugflow.com)
**Works on:** Laravel **8, 9, 10, 11, 12 and 13** · PHP **8.0+**

---

## What it does

One Artisan command, `php artisan package:health vendor/package`, that answers *"should I install this package?"* by pulling **live** data and turning it into a clear verdict. In a single run it gives you:

- 🩺 **A 0–100 health score** — a weighted blend of five signals: Maintenance, Community, Usage, Stability, Security.
- 🚦 **A plain status** — `Active / Safe`, `Use with caution`, `Risky`, or `Abandoned` (abandoned/archived packages are hard-capped low).
- 📊 **Real stats** — total & monthly downloads, GitHub stars, open issues, latest version, last update — pulled live from **Packagist + GitHub**.
- 🎯 **Version resolution** — pass `vendor/package:^1.27` and it tells you the exact version Composer would install.
- 🧩 **Laravel compatibility (automatic)** — it **auto-detects the Laravel version of the app you run it in** and tells you, with a plain **YES / NO**, whether the package you're about to install is suitable. If the latest release doesn't support your Laravel, it **suggests which version of the package does**. Pass `--laravel=10` to check a different version on purpose.
- 🔗 **Sources** — prints the exact Packagist + GitHub URLs every number came from, so you can verify them yourself.
- 🔁 **Alternatives** — genuinely similar packages, found by Packagist **tag** (not free-text) and ranked by relevance, with a curated map for popular packages. Returns "no confident alternatives" rather than wrong ones.
- 📝 **A rule-based recommendation** — a direct, human verdict on whether to install.
- 🤖 **An optional AI verdict** — a short plain-language summary from **any** AI provider (Anthropic, OpenAI, Gemini, or any OpenAI-compatible endpoint). Fully optional; the score works without it.
- 🧪 **Machine-readable output** — `--json` for scripts and CI gating.

No API keys are needed for the core score — only the optional AI verdict needs one.

---

## Table of contents

- [Install](#install)
- [Quick start](#quick-start)
- [Get REAL results (step by step)](#get-real-results-step-by-step)
- [Command reference](#command-reference)
- [Reading the report](#reading-the-report)
- [How the score is calculated](#how-the-score-is-calculated)
- [AI verdict — connect any AI](#ai-verdict--connect-any-ai)
  - [Pick a provider / model per run](#pick-a-provider--model-per-run)
  - [Add your own provider (unlimited)](#add-your-own-provider-unlimited)
  - [Environment variables](#environment-variables)
- [Using it in scripts & CI (`--json`)](#using-it-in-scripts--ci---json)
- [Configuration reference](#configuration-reference)
- [How it works internally](#how-it-works-internally)
- [Troubleshooting](#troubleshooting)
- [Testing](#testing)

---

## Install

```bash
composer require laravel-ai-monitor/laravel-ai-monitor
```

The service provider is auto-discovered — no manual registration needed.

Publish the config file if you want to customise weights, thresholds or AI providers:

```bash
php artisan vendor:publish --tag=ai-health-config
# creates config/ai-health.php
```

---

## Quick start

```bash
# Check the package you're about to install, against your Laravel version
php artisan package:health spatie/laravel-permission --laravel=10
```

```
  📦 spatie/laravel-permission
  Permission handling for Laravel 12 and up

   HEALTH 98/100   Active / Safe

  Maintenance   █████████░ 93/100
              pushed 7d ago → 100  ·  ~23 commits/12wk → 80
  Community     ██████████ 100/100
              12.9K★ → 100  ·  1.8K forks → 100  ·  13.1K favers → 100
  Usage         ██████████ 100/100
              4M/mo → 100  ·  99.9M total → 100
  Stability     ██████████ 100/100
              stable release +50  ·  ≥ 1.0 +25  ·  229 releases +25
  Security      ██████████ 100/100
              0 open issues / 12.9K★ (ratio 0.00) → 100  ·  MIT license +5

  Total downloads ............................ 99,935,637
  Monthly downloads ............................ 4,027,071
  GitHub stars ................................... 12,910
  Open issues ......................................... 0
  Latest version ................................... 8.0.0
  Last updated .................................. 7 days ago

  Compatibility
  Latest release requires: PHP ^8.2 | Laravel/Illuminate ^12.0|^13.0.

  Recommendation
  Healthy and actively maintained. Safe to install.

  Will it work on Laravel 10?
  ✘ NO — the latest release (8.0.0) does not support Laravel 10.
  → Install spatie/laravel-permission:6.25.0 instead — it supports Laravel 10.

  Sources (verify every number yourself)
  Packagist: https://packagist.org/packages/spatie/laravel-permission
  GitHub:    https://github.com/spatie/laravel-permission

  Alternatives (similar packages, by usage)
  bezhansalleh/filament-shield        3,655,723 downloads
  kodeine/laravel-acl                   359,032 downloads
  konekt/acl                             73,535 downloads
```

Every sub-score prints the **factors it's made of** right below the bar — so you can always see *why* it's 93 and not 91. (Maintenance 93 = `100 × 0.65 (recency) + 80 × 0.35 (activity)`.)

> Pass a version constraint (`spatie/laravel-permission:^6.0`) to also see a **Version match** line telling you exactly which version Composer would install.

No API keys are required for the core score — only the optional AI verdict needs a key.

---

## Get REAL results (step by step)

> **Important:** `vendor/bin/phpunit` uses **mocked / fake** HTTP responses **on purpose** — that is how automated tests are supposed to work (deterministic, offline, no rate limits). The numbers you see in a test run are fixtures, **not** a real package check. To see **real** data you must run the tool against the live APIs, using one of the two methods below.

### Method A — Standalone script (fastest, no Laravel app needed)

This repo ships a tiny runner at [`examples/health.php`](examples/health.php) that boots the services with a real HTTP client and hits the **live** Packagist + GitHub APIs.

```bash
# 1. Install dependencies (once)
composer install

# 2. Run against ANY real package
php examples/health.php spatie/laravel-permission
php examples/health.php nesbot/carbon
php examples/health.php laravel/framework
```

The standalone runner also accepts a **version constraint** and your **Laravel version**:

```bash
# Resolve a Composer-style constraint (what would actually install?)
php examples/health.php laravel/framework:^13.8

# Check whether the package works on the Laravel version you run
php examples/health.php spatie/laravel-permission 11

# Both together
php examples/health.php laravel/framework:^13.8 12
```

On top of the score, the script prints:

- **Version match** — for a `:constraint`, the exact version Composer would install (or `NONE`).
- **Compatibility with Laravel X** — `YES` / `NO`, per `illuminate/*` requirement.
- **Sources** — the exact Packagist + GitHub URLs every number came from, so you can verify them yourself.
- **Alternatives** — similar packages (same keywords) ranked by usage, flagging any that are more widely used than the one you checked.

Example real output:

```
  spatie/laravel-permission
  Permission handling for Laravel 12 and up

  HEALTH 98/100   Active / Safe

  Maintenance   #########. 93/100
  Community     ########## 100/100
  Usage         ########## 100/100
  ...
  Total downloads ... 99,935,637
  Monthly downloads . 4,027,071
  GitHub stars ...... 12,910
```

Optional — raise the GitHub rate limit (60 → 5000 req/hr) by exporting a token first:

```bash
# macOS / Linux
export AI_HEALTH_GITHUB_TOKEN=ghp_your_token
# Windows PowerShell
$env:AI_HEALTH_GITHUB_TOKEN = "ghp_your_token"

php examples/health.php laravel/sanctum
```

### Method B — Inside a real Laravel app (full `php artisan` command)

Use this when you want the polished Artisan UI / `--json` / `--ai` options.

```bash
# 1. Create a throwaway Laravel app next to this package
composer create-project laravel/laravel health-test
cd health-test
```

```jsonc
// 2. In health-test/composer.json, point Composer at your LOCAL copy
"repositories": [
    { "type": "path", "url": "../laravel-ai-monitor" }
],
```

```bash
# 3. Pull the package in from your local path
composer require laravel-ai-monitor/laravel-ai-monitor:@dev

# 4. Run the real command (live Packagist + GitHub)
php artisan package:health spatie/laravel-permission
php artisan package:health laravel/framework --json
```

Once the package is published to Packagist, end users skip the `repositories` step and just `composer require laravel-ai-monitor/laravel-ai-monitor`.

---

## Command reference

```
php artisan package:health {package} [options]
```

| Argument / Option   | Description                                                                 |
| ------------------- | --------------------------------------------------------------------------- |
| `package`           | **Required.** `vendor/package`, or `vendor/package:constraint` (e.g. `laravel/pint:^1.27`). |
| `--laravel=`        | Laravel version to check compatibility against (defaults to your app's version). |
| `--explain`         | Show exactly how each sub-score **and** the final weighted score were derived. |
| `--ai`              | Force the AI verdict on for this run (overrides config).                    |
| `--no-ai`           | Disable the AI verdict even if it's enabled in config.                      |
| `--provider=`       | Which AI provider to use, e.g. `anthropic`, `openai`, `gemini`, or your own.|
| `--model=`          | Override the AI model for this run — **any** model the provider supports.   |
| `--json`            | Output the full report as JSON (for scripts / CI) instead of the UI.        |

### Examples

```bash
# Core health check (no AI)
php artisan package:health laravel/sanctum

# Resolve a Composer constraint (what version would actually install?)
php artisan package:health laravel/pint:^1.27

# Check whether it supports a specific Laravel version (suggests an older
# version of the package if the latest release doesn't support it)
php artisan package:health spatie/laravel-permission --laravel=10

# Add an AI verdict using your default configured provider
php artisan package:health barryvdh/laravel-debugbar --ai

# Use a specific provider and model just for this run
php artisan package:health livewire/livewire --provider=openai --model=gpt-4o-mini
php artisan package:health livewire/livewire --provider=gemini --model=gemini-2.0-flash

# Turn AI off for one run even though it's enabled in config
php artisan package:health nesbot/carbon --no-ai

# Machine-readable output
php artisan package:health spatie/laravel-data --json
```

Exit codes: `0` success · `1` failure (invalid name, package not found, or fetch error).

---

## Reading the report

| Section            | What it tells you                                                                 |
| ------------------ | --------------------------------------------------------------------------------- |
| **HEALTH x/100**   | The overall weighted score. Green ≥ 75, yellow ≥ 50, red below.                   |
| **Status**         | `Active / Safe`, `Active / Use with caution`, `Risky`, or `Abandoned`.            |
| **Sub-scores**     | The five dimensions that make up the score — each prints the **exact factors** behind it (see [the formula](#how-the-score-is-calculated)). |
| **Stats**          | Raw numbers: downloads, stars, open issues, latest version, last update.          |
| **Compatibility**  | The PHP / Laravel constraints declared by the latest stable release.              |
| **Recommendation** | A direct, rule-based verdict on whether to install.                               |
| **Version match**  | (When you pass `:constraint`) the exact version Composer would install.            |
| **Will it work on your current Laravel?** | Auto-detects your app's Laravel version and gives a plain YES/NO — and which version of the package to use if NO. Override with `--laravel=`. |
| **Sources**        | The Packagist + GitHub URLs every number came from, so you can verify them.        |
| **Alternatives**   | Genuinely similar packages (Packagist tag match + curated map), ranked by relevance. Shows "no confident alternatives" if unsure. |
| **🤖 AI verdict**  | (Optional) A short plain-language summary from your AI provider.                  |

---

## How the score is calculated

The final score is a **weighted average of five sub-scores**, each 0–100. The weights are configurable in `config/ai-health.php`:

| Dimension     | Default weight | What it measures                                              |
| ------------- | -------------- | ------------------------------------------------------------ |
| Maintenance   | 30%            | How recently the repo was pushed + recent commit frequency   |
| Community     | 20%            | GitHub stars, forks and Packagist favers (logarithmic)       |
| Usage         | 20%            | Monthly + total Composer downloads (logarithmic)             |
| Stability     | 15%            | Tagged stable release, version history, ≥ 1.0                |
| Security      | 15%            | Abandoned/archived flag + open-issue-to-popularity ratio     |

- An **abandoned** Packagist package or an **archived** GitHub repo is hard-capped at a low score (≤ 25) regardless of its other signals.
- Counts (stars, downloads…) are scored on a **logarithmic curve**, so going from 10 → 100 matters more than 10,000 → 100,000.
- If GitHub data can't be fetched (private repo, not on GitHub, rate-limited), the score is still produced from Packagist data alone.

**Status thresholds** (configurable): Safe ≥ 75, Use-with-caution ≥ 50, otherwise Risky.

### Exact formula for each sub-score

Nothing is a black box — the CLI prints these factors under every bar, and here are the precise rules behind them:

**Maintenance** = `recency × 0.65 + activity × 0.35` (if no GitHub data: a neutral **50**).
| Days since last push | recency | Commits / 12 weeks | activity |
| --- | --- | --- | --- |
| ≤ 30 → 100 · ≤ 90 → 85 · ≤ 180 → 70 · ≤ 365 → 50 · ≤ 730 → 30 · else 10 | | ≥ 30 → 100 · ≥ 10 → 80 · ≥ 3 → 60 · ≥ 1 → 40 · else 15 | |

**Community** = `stars × 0.55 + forks × 0.25 + favers × 0.20`, each mapped with `log10(n+1) / log10(ceiling+1) × 100` (ceilings: stars 5 000, forks 1 000, favers 2 000).

**Usage** = `monthly × 0.70 + total × 0.30`, same log curve (ceilings: monthly 500 000, total 50 000 000).

**Stability** = `+50` tagged stable release · `+25` latest is ≥ 1.0 · version-history bonus `≥20 → +25 · ≥8 → +18 · ≥3 → +10 · ≥1 → +5` (capped at 100).

**Security** = open-issue-to-stars ratio: `≤0.02 → 100 · ≤0.05 → 85 · ≤0.10 → 70 · ≤0.20 → 55 · ≤0.40 → 40 · else 25`, then `+5` for a real license. Abandoned/archived → **10**. No issue/star signal → neutral **65**.

**Every number above lives in `config/ai-health.php`** under the `scoring` key — recency/activity bands, log-curve ceilings, sub-weights, stability bonuses and the security ratio bands are all tunable. Run any check with **`--explain`** to see the exact weighted derivation for that package, and the `--json` output includes a `sub_score_breakdown` field with the same factors, machine-readable.

---

## AI verdict — connect any AI

The AI layer turns the raw analysis into a short, human-friendly verdict. It is:

- **Optional** — the core score works without it.
- **Multi-provider** — Anthropic Claude, OpenAI, Google Gemini, and **any OpenAI-compatible endpoint** (Groq, OpenRouter, Together, Mistral, Ollama, LM Studio, your own gateway…).
- **SDK-free** — every provider is called over plain HTTP. Nothing extra to install.
- **Fail-safe** — if it's disabled, no key is set, or the call fails, the tool still prints the full rule-based report.

Built-in providers:

| Provider          | `--provider` value | Default model              |
| ----------------- | ------------------ | -------------------------- |
| Anthropic Claude  | `anthropic`        | `claude-opus-4-8`          |
| OpenAI            | `openai`           | `gpt-4o`                   |
| Google Gemini     | `gemini`           | `gemini-2.0-flash`         |
| Groq (example)    | `groq`             | `llama-3.3-70b-versatile`  |

### Enable it

```dotenv
AI_HEALTH_AI_ENABLED=true
AI_HEALTH_AI_PROVIDER=anthropic      # the default provider
AI_HEALTH_ANTHROPIC_API_KEY=sk-ant-xxx
```

Then any run prints an AI verdict. Or leave it disabled and turn it on per run with `--ai`.

### Pick a provider / model per run

You are not locked to one provider or model. Switch freely on the command line:

```bash
php artisan package:health vendor/pkg --provider=openai
php artisan package:health vendor/pkg --provider=gemini --model=gemini-1.5-pro
php artisan package:health vendor/pkg --ai --model=claude-haiku-4-5
```

`--model` accepts **any** model string the chosen provider supports — there is no allow-list.

### Add your own provider (unlimited)

You can register as many providers as you want under `ai.providers` in `config/ai-health.php`. Each entry chooses how its request is shaped via an optional `driver` (`anthropic`, `openai`, or `gemini`). Omit `driver` and anything that isn't `anthropic`/`gemini` is treated as **OpenAI-compatible**.

```php
// config/ai-health.php → ai.providers
'providers' => [

    // A local Ollama server (OpenAI-compatible)
    'ollama' => [
        'api_key'  => 'ollama',                       // any non-empty value
        'model'    => 'llama3.1',
        'base_url' => 'http://localhost:11434/v1',
        'driver'   => 'openai',
    ],

    // OpenRouter — one key, hundreds of models
    'openrouter' => [
        'api_key'  => env('OPENROUTER_API_KEY'),
        'model'    => 'anthropic/claude-3.5-sonnet',
        'base_url' => 'https://openrouter.ai/api/v1',
        'driver'   => 'openai',
    ],

],
```

```bash
php artisan package:health vendor/pkg --provider=ollama
php artisan package:health vendor/pkg --provider=openrouter --model=google/gemini-pro-1.5
```

### Environment variables

| Variable                          | Purpose                                                        |
| --------------------------------- | -------------------------------------------------------------- |
| `AI_HEALTH_AI_ENABLED`            | `true` to enable the AI verdict by default.                    |
| `AI_HEALTH_AI_PROVIDER`           | Default provider key (`anthropic`, `openai`, `gemini`, …).     |
| `AI_HEALTH_AI_MAX_TOKENS`         | Max tokens for the verdict (default `600`).                    |
| `AI_HEALTH_AI_TIMEOUT`            | HTTP timeout in seconds for AI calls (default `60`).           |
| `AI_HEALTH_ANTHROPIC_API_KEY`     | Anthropic Claude key (falls back to `ANTHROPIC_API_KEY`).      |
| `AI_HEALTH_ANTHROPIC_MODEL`       | Anthropic model (default `claude-opus-4-8`).                   |
| `AI_HEALTH_OPENAI_API_KEY`        | OpenAI key (falls back to `OPENAI_API_KEY`).                   |
| `AI_HEALTH_OPENAI_MODEL`          | OpenAI model (default `gpt-4o`).                               |
| `AI_HEALTH_OPENAI_BASE_URL`       | Override for OpenAI-compatible endpoints.                      |
| `AI_HEALTH_GEMINI_API_KEY`        | Gemini key (falls back to `GEMINI_API_KEY`).                   |
| `AI_HEALTH_GEMINI_MODEL`          | Gemini model (default `gemini-2.0-flash`).                     |
| `AI_HEALTH_GITHUB_TOKEN`          | Optional GitHub token (raises rate limit 60→5000/hr).          |

---

## Using it in scripts & CI (`--json`)

`--json` prints the full report as JSON so you can gate installs in a pipeline:

```bash
php artisan package:health spatie/laravel-data --json
```

```json
{
  "package": "spatie/laravel-data",
  "score": 88,
  "status": "safe",
  "status_label": "Active / Safe",
  "sub_scores": { "maintenance": 92, "community": 90, "usage": 85, "stability": 95, "security": 80 },
  "sub_score_breakdown": {
    "maintenance": ["pushed 4d ago → 100", "~15 commits/12wk → 80"],
    "community": ["1.4K★ → 88", "120 forks → 70", "900 favers → 84"],
    "usage": ["...": "..."]
  },
  "compatibility_hint": "Latest release requires: PHP ^8.1 | Laravel/Illuminate ^10.0|^11.0.",
  "recommendation": "Healthy and actively maintained. Safe to install.",
  "ai_explanation": null,
  "data": { "total_downloads": 12345678, "stars": 1400, "...": "..." }
}
```

Example: fail a CI step if the score is below 60:

```bash
SCORE=$(php artisan package:health some/package --json | php -r 'echo json_decode(file_get_contents("php://stdin"))->score;')
[ "$SCORE" -ge 60 ] || { echo "Package health too low: $SCORE"; exit 1; }
```

---

## Configuration reference

After publishing, `config/ai-health.php` exposes:

| Key                         | Description                                                              |
| --------------------------- | ------------------------------------------------------------------------ |
| `github_token`              | GitHub API token for higher rate limits.                                 |
| `http.timeout`              | Timeout (seconds) for Packagist/GitHub calls (default `15`).             |
| `http.user_agent`           | User-Agent sent with API requests.                                       |
| `weights.*`                 | Weight of each sub-score; should add up to 100.                          |
| `thresholds.safe`           | Minimum score for the "Safe" label (default `75`).                       |
| `thresholds.caution`        | Minimum score for the "Use with caution" label (default `50`).           |
| `ai.enabled`                | Master switch for the AI verdict.                                        |
| `ai.provider`               | Default provider key.                                                    |
| `ai.max_tokens`             | Token budget for the verdict.                                            |
| `ai.providers.*`            | Provider definitions (`api_key`, `model`, `base_url`, `driver`, …).      |

---

## How it works internally

```
php artisan package:health vendor/pkg:^1.27 --laravel=10
        │
        ▼
PackageHealthCommand   (parses the :constraint and --laravel target)
        │  1. PackagistService   → downloads, versions, keywords, per-version
        │                          constraints, abandoned flag, repo URL
        │  2. GitHubService      → stars, forks, open issues, last push, commit freq
        │  3. HealthScoreService → weighted 0–100 score + status + recommendation
        │  4. VersionService     → constraint → installable version, and the best
        │                          version for your Laravel (composer/semver)
        │  5. PackagistService::search → similar packages (alternatives)
        │  6. AiExplanationService (optional)
        │         └─ AiProviderFactory → Anthropic | OpenAI | Gemini | custom driver
        ▼
   Clean CLI report  (or JSON with --json)
```

Each layer is a separately bound, injectable service, so you can reuse `PackagistService`, `GitHubService`, `HealthScoreService` or `VersionService` directly in your own code.

---

## Troubleshooting

| Symptom                                   | Fix                                                                              |
| ----------------------------------------- | -------------------------------------------------------------------------------- |
| `Invalid package name`                    | Use the `vendor/package` format (lowercase, e.g. `laravel/framework`).           |
| `was not found on Packagist`              | Check the spelling; the package must exist on packagist.org.                     |
| GitHub stats show `n/a`                   | Repo isn't on GitHub, is private, or you hit the rate limit — set `AI_HEALTH_GITHUB_TOKEN`. |
| AI verdict never appears                  | Set `ai.enabled=true` (or pass `--ai`) **and** a valid key for the provider.     |
| "no API key is configured" warning        | The selected `--provider` has no `api_key` in config/env.                        |

---

## Testing

```bash
composer install
vendor/bin/phpunit
```

The package ships with a full suite (HTTP fully mocked — no network needed) covering the scoring logic, Packagist/GitHub services, version/constraint resolution, the command output, JSON output, and every AI provider — **32 tests** in total.

> ⚠️ **The numbers in the test suite are fake fixtures by design** — `phpunit` never calls the real APIs, so it stays fast, offline and deterministic. **Do not** expect real download counts here. To check a real package, use one of the methods in [Get REAL results (step by step)](#get-real-results-step-by-step).

---

## License

MIT © [debug flow](https://debugflow.com)
