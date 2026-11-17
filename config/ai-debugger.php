<?php

return [
    'anthropic' => [
        // API key from https://console.anthropic.com
        'api_key' => env('AI_DEBUGGER_ANTHROPIC_API_KEY', env('ANTHROPIC_API_KEY')),
        // Optional, e.g. for a proxy. Leave empty for https://api.anthropic.com
        'base_url' => env('AI_DEBUGGER_ANTHROPIC_BASE_URL'),
        'model' => env('AI_DEBUGGER_MODEL', 'claude-opus-5-5'),
        // low, medium, high, xhigh, max
        'effort' => env('AI_DEBUGGER_EFFORT', 'high'),
        'max_tokens' => (int) env('AI_DEBUGGER_MAX_TOKENS', 64000),
        // Seconds a single request may take
        'timeout' => (int) env('AI_DEBUGGER_TIMEOUT', 900),
    ],

    // Maximum number of model turns per session before the agent is stopped.
    'max_iterations' => (int) env('AI_DEBUGGER_MAX_ITERATIONS', 80),

    // auto = panel backup if the server has a free backup slot, otherwise an archive inside the server files
    // archive = always a tar.gz archive in /.ai-debugger/backups (counts against the server disk)
    // panel = always a regular panel backup (needs a free backup slot)
    'backup_mode' => env('AI_DEBUGGER_BACKUP_MODE', 'auto'),

    // Minutes to wait for a panel backup to finish.
    'backup_timeout' => (int) env('AI_DEBUGGER_BACKUP_TIMEOUT', 60),

    // Allow server owners / subusers (with file + power permissions) to use the debugger from the server panel.
    'allow_server_users' => (bool) env('AI_DEBUGGER_ALLOW_SERVER_USERS', false),

    // Sessions a non-admin user can start per server per day.
    'user_daily_limit' => (int) env('AI_DEBUGGER_USER_DAILY_LIMIT', 3),

    // Use the Modrinth API to identify mods, detect client-only mods and find updates.
    'modrinth' => (bool) env('AI_DEBUGGER_MODRINTH', true),

    // Jars larger than this (MiB) are not downloaded for analysis.
    'max_jar_size' => (int) env('AI_DEBUGGER_MAX_JAR_SIZE', 128),

    // Seconds a test start may take at most.
    'max_test_start_seconds' => (int) env('AI_DEBUGGER_MAX_TEST_START', 900),

    // Queue the debug jobs run on.
    'queue' => env('AI_DEBUGGER_QUEUE', 'default'),
];
