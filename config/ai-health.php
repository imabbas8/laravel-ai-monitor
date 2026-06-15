<?php

return [

    /*
    |--------------------------------------------------------------------------
    | GitHub API token
    |--------------------------------------------------------------------------
    |
    | Optional. Unauthenticated GitHub API requests are limited to 60/hour.
    | Provide a personal access token (no scopes needed for public repos) to
    | raise that to 5000/hour. Set AI_HEALTH_GITHUB_TOKEN in your .env.
    |
    */
    'github_token' => env('AI_HEALTH_GITHUB_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | HTTP settings
    |--------------------------------------------------------------------------
    */
    'http' => [
        'timeout'    => env('AI_HEALTH_HTTP_TIMEOUT', 15),
        'user_agent' => 'laravel-ai-health',
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring weights
    |--------------------------------------------------------------------------
    |
    | The final 0-100 health score is a weighted average of five sub-scores
    | (each itself 0-100). Weights should add up to 100.
    |
    */
    'weights' => [
        'maintenance' => env('AI_HEALTH_WEIGHT_MAINTENANCE', 30),
        'community'   => env('AI_HEALTH_WEIGHT_COMMUNITY', 20),
        'usage'       => env('AI_HEALTH_WEIGHT_USAGE', 20),
        'stability'   => env('AI_HEALTH_WEIGHT_STABILITY', 15),
        'security'    => env('AI_HEALTH_WEIGHT_SECURITY', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Status thresholds
    |--------------------------------------------------------------------------
    |
    | Score boundaries used to translate the numeric score into a label.
    |
    */
    'thresholds' => [
        'safe'    => env('AI_HEALTH_THRESHOLD_SAFE', 75),
        'caution' => env('AI_HEALTH_THRESHOLD_CAUTION', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scoring rules (every threshold is tunable — nothing is hidden in code)
    |--------------------------------------------------------------------------
    |
    | Each sub-score is 0-100. The exact inputs and mapping for each one are
    | listed below so the final HEALTH score is fully explainable (run with
    | --explain to see the derivation for a specific package). Band tables are
    | evaluated top-to-bottom; the first matching row wins, otherwise `default`.
    |
    */
    'scoring' => [

        // A package flagged abandoned (Packagist) or archived (GitHub) can never
        // score above this, no matter how good its other signals are.
        'abandoned_cap' => 25,

        'maintenance' => [
            // Score when there is no GitHub signal at all (stay neutral).
            'no_data' => 50,
            // Blend of "how recently pushed" and "how active lately".
            'blend' => ['recency' => 0.65, 'activity' => 0.35],
            // days-since-last-push => score
            'recency' => [
                ['max_days' => 30,  'score' => 100],
                ['max_days' => 90,  'score' => 85],
                ['max_days' => 180, 'score' => 70],
                ['max_days' => 365, 'score' => 50],
                ['max_days' => 730, 'score' => 30],
                ['max_days' => null, 'score' => 10],
            ],
            // commits-in-last-12-weeks => score
            'activity' => [
                ['min_commits' => 30, 'score' => 100],
                ['min_commits' => 10, 'score' => 80],
                ['min_commits' => 3,  'score' => 60],
                ['min_commits' => 1,  'score' => 40],
                ['min_commits' => 0,  'score' => 15],
            ],
        ],

        'community' => [
            // log10(value+1) / log10(ceiling+1) * 100, then weighted.
            'weights'  => ['stars' => 0.55, 'forks' => 0.25, 'favers' => 0.20],
            'ceilings' => ['stars' => 5000, 'forks' => 1000, 'favers' => 2000],
        ],

        'usage' => [
            'weights'  => ['monthly' => 0.70, 'total' => 0.30],
            'ceilings' => ['monthly' => 500_000, 'total' => 50_000_000],
        ],

        'stability' => [
            'stable_release' => 50,  // has a tagged stable release
            'at_least_1_0'   => 25,  // latest version is >= 1.0
            // released-version-count => bonus
            'version_history' => [
                ['min' => 20, 'bonus' => 25],
                ['min' => 8,  'bonus' => 18],
                ['min' => 3,  'bonus' => 10],
                ['min' => 1,  'bonus' => 5],
                ['min' => 0,  'bonus' => 0],
            ],
        ],

        'security' => [
            'abandoned'     => 10, // abandoned/archived
            'no_signal'     => 65, // can't compute the issue/star ratio
            'license_bonus' => 5,  // declares a real licence
            // open-issues / stars ratio => score
            'issue_ratio' => [
                ['max_ratio' => 0.02, 'score' => 100],
                ['max_ratio' => 0.05, 'score' => 85],
                ['max_ratio' => 0.10, 'score' => 70],
                ['max_ratio' => 0.20, 'score' => 55],
                ['max_ratio' => 0.40, 'score' => 40],
                ['max_ratio' => null, 'score' => 25],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Alternatives engine
    |--------------------------------------------------------------------------
    |
    | Suggests genuinely similar packages. It searches Packagist by the
    | package's most distinctive *tags* (keywords), never free-text, so a BDD
    | testing framework cannot return documentation builders. Generic / noisy
    | keywords below are ignored when picking the tag to search by.
    |
    | `curated` lets you hard-pin known-good alternatives for popular packages
    | (vendor/name => [vendor/name, ...]); these always win over the tag search.
    |
    */
    'alternatives' => [
        'enabled' => true,
        'limit'   => 4,

        'stopwords' => [
            // ecosystems
            'php', 'laravel', 'illuminate', 'symfony', 'drupal', 'wordpress', 'yii', 'cakephp', 'magento',
            // generic dev terms
            'dev', 'development', 'library', 'lib', 'package', 'packages', 'framework', 'tool', 'tools',
            'utility', 'utilities', 'helper', 'helpers', 'component', 'components', 'api', 'sdk',
            'extension', 'extensions', 'plugin', 'plugins', 'bundle', 'module', 'modules',
            'documentation', 'docs', 'support',
            // process / business noise
            'agile', 'scrum', 'business', 'examples', 'example', 'story', 'user', 'management',
        ],

        // 'vendor/name' => ['vendor/alt-one', 'vendor/alt-two', ...]
        'curated' => [
            'behat/behat'          => ['codeception/codeception', 'phpspec/phpspec'],
            'phpunit/phpunit'      => ['pestphp/pest', 'codeception/codeception'],
            'guzzlehttp/guzzle'    => ['symfony/http-client', 'php-http/curl-client'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI explanation (optional, multi-provider)
    |--------------------------------------------------------------------------
    |
    | When enabled, the raw analysis is sent to an AI provider to produce a
    | short, human-friendly verdict. You can plug in ANY AI: Anthropic Claude,
    | OpenAI (or any OpenAI-compatible endpoint such as Groq, OpenRouter,
    | Together, Mistral, Ollama, LM Studio, ...) and Google Gemini.
    |
    | Pick the active provider with `provider` (or per-run with --provider=).
    | Each provider keeps its own API key and model, so a user can configure as
    | many as they like and switch freely. No SDK is required — every provider
    | is called over plain HTTP.
    |
    */
    'ai' => [
        'enabled'    => env('AI_HEALTH_AI_ENABLED', false),
        'provider'   => env('AI_HEALTH_AI_PROVIDER', 'anthropic'),
        'max_tokens' => env('AI_HEALTH_AI_MAX_TOKENS', 600),

        'providers' => [

            'anthropic' => [
                'api_key'  => env('AI_HEALTH_ANTHROPIC_API_KEY', env('ANTHROPIC_API_KEY')),
                'model'    => env('AI_HEALTH_ANTHROPIC_MODEL', 'claude-opus-4-8'),
                'base_url' => env('AI_HEALTH_ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
                'version'  => env('AI_HEALTH_ANTHROPIC_VERSION', '2023-06-01'),
                'timeout'  => env('AI_HEALTH_AI_TIMEOUT', 60),
            ],

            'openai' => [
                'api_key'  => env('AI_HEALTH_OPENAI_API_KEY', env('OPENAI_API_KEY')),
                'model'    => env('AI_HEALTH_OPENAI_MODEL', 'gpt-4o'),
                'base_url' => env('AI_HEALTH_OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'timeout'  => env('AI_HEALTH_AI_TIMEOUT', 60),
            ],

            'gemini' => [
                'api_key'  => env('AI_HEALTH_GEMINI_API_KEY', env('GEMINI_API_KEY')),
                'model'    => env('AI_HEALTH_GEMINI_MODEL', 'gemini-2.0-flash'),
                'base_url' => env('AI_HEALTH_GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
                'timeout'  => env('AI_HEALTH_AI_TIMEOUT', 60),
            ],

            // Example of an OpenAI-compatible provider. Duplicate this block,
            // rename the key, point base_url at your endpoint and add the key.
            'groq' => [
                'api_key'  => env('AI_HEALTH_GROQ_API_KEY'),
                'model'    => env('AI_HEALTH_GROQ_MODEL', 'llama-3.3-70b-versatile'),
                'base_url' => env('AI_HEALTH_GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
                'timeout'  => env('AI_HEALTH_AI_TIMEOUT', 60),
            ],

        ],
    ],

];
