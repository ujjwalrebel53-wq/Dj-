<?php

declare(strict_types=1);

use DjAi\Config;
use DjAi\Http\Router;
use DjAi\Services\AgentService;
use DjAi\Services\IndexerService;
use DjAi\Services\LlmService;
use DjAi\Tools\ToolExecutor;

require dirname(__DIR__) . '/vendor/autoload.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$config = Config::load(dirname(__DIR__));
$llm = new LlmService($config);
$indexer = new IndexerService($config, $llm);
$tools = new ToolExecutor();
$agent = new AgentService($llm, $indexer, $tools);

$router = new Router();
$router->get('/health', static function () {
    Router::json(['ok' => true]);
});

$router->post('/v1/chat', static function () use ($agent, $config) {
    $body = Router::jsonBody();
    $workspaceRoot = (string) ($body['workspaceRoot'] ?? getcwd() ?: '.');
    $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
    $rules = is_array($body['rules'] ?? null) ? $body['rules'] : [];
    $contextFiles = is_array($body['contextFiles'] ?? null) ? $body['contextFiles'] : [];
    $model = isset($body['model']) ? (string) $body['model'] : null;
    $stream = ($body['stream'] ?? true) !== false;

    if (!$stream) {
        $chunks = [];
        $agent->run(
            $workspaceRoot,
            $messages,
            $rules,
            $contextFiles,
            static function (array $chunk) use (&$chunks): void {
                $chunks[] = $chunk;
            },
            $model
        );
        Router::json(['chunks' => $chunks]);
        return;
    }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    while (ob_get_level() > 0) {
        ob_end_flush();
    }

    $send = static function (array $chunk): void {
        echo 'data: ' . json_encode($chunk, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    };

    try {
        $agent->run($workspaceRoot, $messages, $rules, $contextFiles, $send, $model);
    } catch (Throwable $e) {
        $send(['type' => 'error', 'error' => $e->getMessage()]);
    }
});

$router->post('/v1/index', static function () use ($indexer) {
    $body = Router::jsonBody();
    $workspaceRoot = (string) ($body['workspaceRoot'] ?? '');
    $paths = is_array($body['paths'] ?? null) ? $body['paths'] : null;

    if ($workspaceRoot === '') {
        Router::json(['error' => 'workspaceRoot is required'], 400);
        return;
    }

    $count = $indexer->indexWorkspace($workspaceRoot, $paths);
    Router::json(['indexedChunks' => $count]);
});

$router->post('/v1/search', static function () use ($indexer) {
    $body = Router::jsonBody();
    $workspaceRoot = (string) ($body['workspaceRoot'] ?? '');
    $query = (string) ($body['query'] ?? '');
    $limit = (int) ($body['limit'] ?? 8);

    if ($workspaceRoot === '' || $query === '') {
        Router::json(['error' => 'workspaceRoot and query are required'], 400);
        return;
    }

    Router::json(['results' => $indexer->searchWorkspace($workspaceRoot, $query, $limit)]);
});

$router->dispatch();
