<?php
/**
 * Server-only configuration. Process environment takes precedence over .env.local.
 * Default model is Claude Haiku 4.5: ASPRIMA only classifies intent into a fixed
 * set of topics and paraphrases a server-supplied guide in <=150 words, a task that
 * does not need a larger model. Raise ANTHROPIC_MODEL to claude-sonnet-5 or
 * claude-opus-5 if answer quality needs it; no other code change is required.
 */
require_once __DIR__ . '/env_loader.php';
if (is_file(__DIR__ . '/.env.local')) loadEnv(__DIR__ . '/.env.local');
return [
    'key' => trim((string)env('ANTHROPIC_API_KEY', '')),
    'model' => trim((string)env('ANTHROPIC_MODEL', 'claude-haiku-4-5')),
    'enabled' => filter_var(env('ASPRIMA_AI_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
];
