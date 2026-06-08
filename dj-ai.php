<?php
/**
 * Dj AI — Cursor jaisa coding assistant (single PHP file)
 *
 * Run:
 *   cp .env.example .env
 *   php dj-ai.php
 *
 * Default LLM: WormGPT API (https://wormgpt.freeapihub.workers.dev/chat?q=)
 *
 * Endpoints:
 *   GET  /health
 *   POST /v1/chat
 *   POST /v1/index
 *   POST /v1/search
 */

declare(strict_types=1);

function djAiRoot(): string
{
    return defined('DJ_AI_ROOT') ? (string) DJ_AI_ROOT : __DIR__;
}

// ─── CLI server ───────────────────────────────────────────────────────────────
if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
    $root = djAiRoot();
    loadEnv($root . '/.env');
    $port = (int) (env('PORT', '8787'));
    echo "Dj AI API → http://127.0.0.1:{$port}\n";
    passthru(sprintf('php -S 0.0.0.0:%d %s', $port, escapeshellarg(__FILE__)));
    exit(0);
}

// ─── .env helpers ─────────────────────────────────────────────────────────────
function loadEnv(string $path): void
{
    if (!is_file($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
}

function env(string $key, string $default = ''): string
{
    return (string) ($_ENV[$key] ?? getenv($key) ?: $default);
}

// ─── Config ───────────────────────────────────────────────────────────────────
final class DjConfig
{
    public function __construct(
        public readonly string $provider,
        public readonly string $wormgptUrl,
        public readonly string $llmBaseUrl,
        public readonly string $llmApiKey,
        public readonly string $llmModel,
        public readonly string $embeddingModel,
        public readonly string $dataDir,
    ) {}

    public function isWormgpt(): bool
    {
        return $this->provider === 'wormgpt';
    }

    public static function load(string $root): self
    {
        loadEnv($root . '/.env');
        $dataDir = env('DATA_DIR', '.data');
        if (!str_starts_with($dataDir, '/')) {
            $dataDir = $root . '/' . $dataDir;
        }

        return new self(
            provider: strtolower(env('LLM_PROVIDER', 'wormgpt')),
            wormgptUrl: rtrim(env('WORMGPT_API_URL', 'https://wormgpt.freeapihub.workers.dev/chat'), '/'),
            llmBaseUrl: rtrim(env('LLM_BASE_URL', 'https://api.openai.com/v1'), '/'),
            llmApiKey: env('LLM_API_KEY'),
            llmModel: env('LLM_MODEL', 'gpt-4o'),
            embeddingModel: env('EMBEDDING_MODEL', 'text-embedding-3-small'),
            dataDir: $dataDir,
        );
    }
}

// ─── Router ───────────────────────────────────────────────────────────────────
final class DjRouter
{
    /** @var array<string, callable> */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes['GET ' . $path] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes['POST ' . $path] = $handler;
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (str_ends_with($path, '/') && $path !== '/') {
            $path = rtrim($path, '/') ?: '/';
        }

        $handler = $this->routes[$method . ' ' . $path] ?? null;
        if ($handler === null) {
            self::json(['error' => 'Not found'], 404);
            return;
        }
        $handler();
    }

    /** @return array<string, mixed> */
    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}

// ─── Agent tools definition ───────────────────────────────────────────────────
/** @return list<array{name: string, description: string, parameters: array<string, mixed>}> */
function djDefaultTools(): array
{
    return [
        [
            'name' => 'read_file',
            'description' => 'Read the contents of a file in the workspace',
            'parameters' => [
                'type' => 'object',
                'properties' => ['path' => ['type' => 'string', 'description' => 'Relative path from workspace root']],
                'required' => ['path'],
            ],
        ],
        [
            'name' => 'write_file',
            'description' => 'Write or overwrite a file in the workspace',
            'parameters' => [
                'type' => 'object',
                'properties' => ['path' => ['type' => 'string'], 'content' => ['type' => 'string']],
                'required' => ['path', 'content'],
            ],
        ],
        [
            'name' => 'grep',
            'description' => 'Search for a regex pattern across workspace files',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'pattern' => ['type' => 'string'],
                    'glob' => ['type' => 'string', 'description' => 'Optional glob, e.g. **/*.php'],
                ],
                'required' => ['pattern'],
            ],
        ],
        [
            'name' => 'run_terminal',
            'description' => 'Run a shell command in the workspace',
            'parameters' => [
                'type' => 'object',
                'properties' => ['command' => ['type' => 'string'], 'cwd' => ['type' => 'string']],
                'required' => ['command'],
            ],
        ],
    ];
}

// ─── Tool executor ────────────────────────────────────────────────────────────
final class DjToolExecutor
{
    /** @param array{id: string, name: string, arguments: array<string, mixed>} $call */
    public function execute(string $workspaceRoot, array $call): string
    {
        $name = (string) ($call['name'] ?? '');
        $args = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];

        return match ($name) {
            'read_file' => $this->readFile($workspaceRoot, (string) ($args['path'] ?? '')),
            'write_file' => $this->writeFile($workspaceRoot, (string) ($args['path'] ?? ''), (string) ($args['content'] ?? '')),
            'grep' => $this->grep($workspaceRoot, (string) ($args['pattern'] ?? ''), (string) ($args['glob'] ?? '**/*')),
            'run_terminal' => $this->runTerminal($workspaceRoot, (string) ($args['command'] ?? ''), isset($args['cwd']) ? (string) $args['cwd'] : null),
            default => throw new RuntimeException('Unknown tool: ' . $name),
        };
    }

    private function inside(string $root, string $target): string
    {
        $root = realpath($root) ?: $root;
        $full = realpath($root . '/' . ltrim($target, '/')) ?: ($root . '/' . ltrim($target, '/'));
        if (!str_starts_with($full, $root)) {
            throw new RuntimeException('Path escapes workspace: ' . $target);
        }
        return $full;
    }

    private function readFile(string $root, string $path): string
    {
        $full = $this->inside($root, $path);
        if (!is_file($full)) {
            throw new RuntimeException('File not found: ' . $path);
        }
        return (string) file_get_contents($full);
    }

    private function writeFile(string $root, string $path, string $content): string
    {
        $full = $this->inside($root, $path);
        $dir = dirname($full);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory');
        }
        file_put_contents($full, $content);
        return 'Wrote ' . $path . ' (' . strlen($content) . ' bytes)';
    }

    private function grep(string $root, string $pattern, string $glob): string
    {
        $hits = [];
        foreach (array_slice($this->globFiles($root, $glob), 0, 200) as $file) {
            $relative = ltrim(str_replace(realpath($root) ?: $root, '', realpath($file) ?: $file), '/');
            $lines = preg_split("/\r\n|\n|\r/", (string) file_get_contents($file)) ?: [];
            foreach ($lines as $i => $line) {
                if (@preg_match('/' . $pattern . '/', $line) === 1) {
                    $hits[] = $relative . ':' . ($i + 1) . ': ' . $line;
                    if (count($hits) >= 50) {
                        break 2;
                    }
                }
            }
        }
        return $hits !== [] ? implode("\n", $hits) : 'No matches found';
    }

    private function runTerminal(string $root, string $command, ?string $cwd): string
    {
        $workdir = ($cwd !== null && $cwd !== '') ? $this->inside($root, $cwd) : (realpath($root) ?: $root);
        $pipes = [];
        $process = proc_open(['bash', '-lc', $command], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workdir);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start process');
        }
        fclose($pipes[0]);
        $out = trim((stream_get_contents($pipes[1]) ?: '') . "\n" . (stream_get_contents($pipes[2]) ?: ''));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        return $out !== '' ? $out : '(no output)';
    }

    /** @return list<string> */
    private function globFiles(string $root, string $globPattern): array
    {
        $root = realpath($root) ?: $root;
        $regex = '/^' . str_replace(['\\*\\*', '\\*', '\\?'], ['.*', '[^/]*', '[^/]'], preg_quote($globPattern, '/')) . '$/i';
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/node_modules/') || str_contains($path, '/.git/')) {
                continue;
            }
            $rel = ltrim(str_replace($root, '', $path), '/');
            if (preg_match($regex, $rel) === 1) {
                $files[] = $path;
            }
        }
        return $files;
    }
}

// ─── LLM service ──────────────────────────────────────────────────────────────
final class DjLlm
{
    public function __construct(private readonly DjConfig $config) {}

    /** @param list<string> $rules */
    public function systemPrompt(array $rules, string $context): string
    {
        $rulesBlock = $rules !== [] ? "\n\nUser rules:\n" . implode("\n", array_map(fn ($r) => '- ' . $r, $rules)) : '';
        $ctx = $context !== '' ? "\n\nRelevant workspace context:\n" . $context : '';
        return 'You are Dj AI, a coding assistant similar to Cursor. You help users write, debug, and understand code. Prefer minimal focused changes. Use tools when needed.' . $rulesBlock . $ctx;
    }

    /**
     * @param list<array{role: string, content: string, name?: string, toolCallId?: string}> $messages
     * @param list<array{name: string, description: string, parameters: array<string, mixed>}> $tools
     */
    public function streamChat(array $messages, array $tools, callable $onEvent, ?string $model = null): void
    {
        if ($this->config->isWormgpt()) {
            $this->streamWormgpt($messages, $onEvent);
            return;
        }

        $pending = [];
        $this->stream('/chat/completions', [
            'model' => $model ?? $this->config->llmModel,
            'messages' => $this->toMessages($messages),
            'tools' => array_map(fn ($t) => ['type' => 'function', 'function' => $t], $tools),
            'stream' => true,
        ], function (array $chunk) use (&$pending, $onEvent): void {
            $choice = $chunk['choices'][0] ?? null;
            if ($choice === null) {
                return;
            }
            $delta = $choice['delta'] ?? [];
            if (!empty($delta['content'])) {
                $onEvent(['type' => 'text', 'content' => (string) $delta['content']]);
            }
            if (!empty($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $td) {
                    $i = (int) ($td['index'] ?? 0);
                    $pending[$i] ??= ['id' => (string) ($td['id'] ?? bin2hex(random_bytes(8))), 'name' => '', 'args' => ''];
                    if (!empty($td['id'])) {
                        $pending[$i]['id'] = (string) $td['id'];
                    }
                    if (!empty($td['function']['name'])) {
                        $pending[$i]['name'] = (string) $td['function']['name'];
                    }
                    if (isset($td['function']['arguments'])) {
                        $pending[$i]['args'] .= (string) $td['function']['arguments'];
                    }
                }
            }
            if (($choice['finish_reason'] ?? null) === 'tool_calls') {
                foreach ($pending as $call) {
                    $args = json_decode($call['args'] !== '' ? $call['args'] : '{}', true);
                    $onEvent(['type' => 'tool_call', 'toolCall' => ['id' => $call['id'], 'name' => $call['name'], 'arguments' => is_array($args) ? $args : []]]);
                }
            }
        });
    }

    /** @return list<float> */
    public function embed(string $text): array
    {
        if ($this->config->isWormgpt()) {
            return $this->localEmbed($text);
        }

        $res = $this->post('/embeddings', ['model' => $this->config->embeddingModel, 'input' => $text]);
        $emb = $res['data'][0]['embedding'] ?? [];
        return is_array($emb) ? array_map('floatval', $emb) : [];
    }

    /**
     * @param list<array{role: string, content: string, name?: string, toolCallId?: string}> $messages
     * @param callable(array{type: string, content?: string}): void $onEvent
     */
    private function streamWormgpt(array $messages, callable $onEvent): void
    {
        $prompt = $this->messagesToPrompt($messages);
        if (strlen($prompt) > 6000) {
            $prompt = substr($prompt, -6000);
        }

        $url = $this->config->wormgptUrl . '?q=' . rawurlencode($prompt);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('WormGPT request failed: ' . $err);
        }

        $decoded = json_decode($body, true);
        if ($status >= 400 || !is_array($decoded)) {
            throw new RuntimeException('WormGPT error (' . $status . '): ' . $body);
        }

        if (($decoded['status'] ?? '') !== 'success') {
            $message = (string) ($decoded['error'] ?? $decoded['reply'] ?? $body);
            throw new RuntimeException('WormGPT error: ' . $message);
        }

        $reply = (string) ($decoded['reply'] ?? '');
        foreach (str_split($reply, 24) as $chunk) {
            $onEvent(['type' => 'text', 'content' => $chunk]);
        }
    }

    /** @param list<array{role: string, content: string, name?: string, toolCallId?: string}> $messages */
    private function messagesToPrompt(array $messages): string
    {
        $parts = [];
        foreach ($messages as $m) {
            $role = strtoupper((string) ($m['role'] ?? 'user'));
            $content = trim((string) ($m['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            if ($role === 'TOOL') {
                $parts[] = '[TOOL ' . ($m['name'] ?? 'result') . "]\n" . $content;
                continue;
            }
            $parts[] = "[{$role}]\n" . $content;
        }

        return implode("\n\n", $parts);
    }

    /** @return list<float> */
    private function localEmbed(string $text): array
    {
        $dim = 256;
        $vec = array_fill(0, $dim, 0.0);
        $words = preg_split('/\W+/u', mb_strtolower($text)) ?: [];
        foreach ($words as $word) {
            if ($word === '' || strlen($word) < 2) {
                continue;
            }
            $idx = crc32($word) % $dim;
            $vec[$idx] += 1.0;
        }
        $norm = sqrt(array_sum(array_map(fn ($v) => $v ** 2, $vec)));
        if ($norm > 0) {
            $vec = array_map(fn ($v) => $v / $norm, $vec);
        }
        return $vec;
    }

    /** @param list<array{role: string, content: string, name?: string, toolCallId?: string}> $messages */
    private function toMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            if (($m['role'] ?? '') === 'tool') {
                $out[] = ['role' => 'tool', 'content' => (string) ($m['content'] ?? ''), 'tool_call_id' => (string) ($m['toolCallId'] ?? 'tool')];
            } else {
                $item = ['role' => (string) ($m['role'] ?? 'user'), 'content' => (string) ($m['content'] ?? '')];
                if (!empty($m['name'])) {
                    $item['name'] = (string) $m['name'];
                }
                $out[] = $item;
            }
        }
        return $out;
    }

    /** @param array<string, mixed> $payload */
    private function post(string $path, array $payload): array
    {
        $ch = curl_init($this->config->llmBaseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->config->llmApiKey],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 120,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('LLM failed: ' . $err);
        }
        $decoded = json_decode($body, true);
        if ($status >= 400) {
            $msg = is_array($decoded) ? ($decoded['error']['message'] ?? $body) : $body;
            throw new RuntimeException('LLM error (' . $status . '): ' . $msg);
        }
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function stream(string $path, array $payload, callable $onData): void
    {
        $buffer = '';
        $ch = curl_init($this->config->llmBaseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $this->config->llmApiKey],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 0,
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$buffer, $onData): int {
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line === '' || $line === 'data: [DONE]' || !str_starts_with($line, 'data: ')) {
                        continue;
                    }
                    $decoded = json_decode(substr($line, 6), true);
                    if (is_array($decoded)) {
                        $onData($decoded);
                    }
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($ok === false) {
            throw new RuntimeException('LLM stream failed');
        }
        if ($status >= 400) {
            throw new RuntimeException('LLM stream error (' . $status . ')');
        }
    }
}

// ─── Indexer ──────────────────────────────────────────────────────────────────
final class DjIndexer
{
    public function __construct(private readonly DjConfig $config, private readonly DjLlm $llm) {}

    /** @param list<string>|null $paths */
    public function index(string $workspaceRoot, ?array $paths = null): int
    {
        $files = $paths ?? $this->discover($workspaceRoot);
        $all = [];
        foreach (array_slice($files, 0, 500) as $file) {
            $full = $workspaceRoot . '/' . ltrim($file, '/');
            if (!is_file($full)) {
                continue;
            }
            foreach ($this->chunks($file, (string) file_get_contents($full)) as $chunk) {
                $chunk['embedding'] = $this->llm->embed($chunk['path'] . "\n" . $chunk['text']);
                $all[] = $chunk;
            }
        }
        $out = $this->indexFile($workspaceRoot);
        if (!is_dir(dirname($out)) && !mkdir(dirname($out), 0775, true) && !is_dir(dirname($out))) {
            throw new RuntimeException('Cannot create index dir');
        }
        file_put_contents($out, json_encode($all, JSON_UNESCAPED_UNICODE));
        return count($all);
    }

    /** @return list<array{path: string, score: float, snippet: string}> */
    public function search(string $workspaceRoot, string $query, int $limit = 8): array
    {
        $file = $this->indexFile($workspaceRoot);
        if (!is_file($file)) {
            return [];
        }
        $chunks = json_decode((string) file_get_contents($file), true);
        if (!is_array($chunks)) {
            return [];
        }
        $qEmb = $this->llm->embed($query);
        $ranked = [];
        foreach ($chunks as $c) {
            if (!is_array($c) || !is_array($c['embedding'] ?? null)) {
                continue;
            }
            $ranked[] = [
                'path' => (string) ($c['path'] ?? ''),
                'score' => $this->cosine($qEmb, array_map('floatval', $c['embedding'])),
                'snippet' => substr((string) ($c['text'] ?? ''), 0, 300),
            ];
        }
        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($ranked, 0, $limit);
    }

    /** @return list<string> */
    private function discover(string $root): array
    {
        $exts = ['php', 'ts', 'tsx', 'js', 'jsx', 'py', 'go', 'rs', 'java', 'md', 'json'];
        $root = realpath($root) ?: $root;
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            if (str_contains($path, '/node_modules/') || str_contains($path, '/.git/') || str_contains($path, '/vendor/')) {
                continue;
            }
            if (in_array(strtolower($file->getExtension()), $exts, true)) {
                $files[] = ltrim(str_replace($root, '', $path), '/');
            }
        }
        return $files;
    }

    /** @return list<array{path: string, startLine: int, endLine: int, text: string, embedding: list<float>}> */
    private function chunks(string $path, string $content, int $size = 40): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $out = [];
        for ($i = 0; $i < count($lines); $i += $size) {
            $slice = array_slice($lines, $i, $size);
            $out[] = ['path' => $path, 'startLine' => $i + 1, 'endLine' => $i + count($slice), 'text' => implode("\n", $slice), 'embedding' => []];
        }
        return $out;
    }

    private function indexFile(string $workspaceRoot): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $workspaceRoot) ?? 'ws';
        return $this->config->dataDir . '/indexes/' . $safe . '/chunks.json';
    }

    /** @param list<float> $a @param list<float> $b */
    private function cosine(array $a, array $b): float
    {
        $dot = $normA = $normB = 0.0;
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }
        $d = sqrt($normA) * sqrt($normB);
        return $d > 0 ? $dot / $d : 0.0;
    }
}

// ─── Agent loop ───────────────────────────────────────────────────────────────
final class DjAgent
{
    public function __construct(
        private readonly DjLlm $llm,
        private readonly DjIndexer $indexer,
        private readonly DjToolExecutor $tools,
    ) {}

    /**
     * @param list<array{role: string, content: string, name?: string, toolCallId?: string}> $baseMessages
     * @param list<string> $rules
     * @param list<array{path: string, content: string, language?: string}> $contextFiles
     */
    public function run(string $workspaceRoot, array $baseMessages, array $rules, array $contextFiles, callable $send, ?string $model = null): void
    {
        $messages = $baseMessages;
        $tools = djDefaultTools();

        for ($step = 0; $step < 12; $step++) {
            $lastUser = null;
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? '') === 'user') {
                    $lastUser = $messages[$i];
                    break;
                }
            }

            $ctx = $this->formatContext($contextFiles);
            if ($workspaceRoot !== '' && $lastUser !== null) {
                $hits = $this->indexer->search($workspaceRoot, (string) ($lastUser['content'] ?? ''), 5);
                if ($hits !== []) {
                    $ctx .= "\n\nSemantic search hits:\n" . implode("\n\n", array_map(
                        fn ($h) => $h['path'] . ' (score ' . number_format($h['score'], 2) . ")\n" . $h['snippet'],
                        $hits
                    ));
                }
            }

            $system = ['role' => 'system', 'content' => $this->llm->systemPrompt($rules, $ctx)];
            $text = '';
            $toolCalls = [];

            $this->llm->streamChat([$system, ...$messages], $tools, function (array $ev) use (&$text, &$toolCalls, $send): void {
                if ($ev['type'] === 'text' && isset($ev['content'])) {
                    $text .= (string) $ev['content'];
                    $send(['type' => 'text', 'content' => (string) $ev['content']]);
                }
                if ($ev['type'] === 'tool_call' && isset($ev['toolCall'])) {
                    $toolCalls[] = $ev['toolCall'];
                    $send(['type' => 'tool_call', 'toolCall' => $ev['toolCall']]);
                }
            }, $model);

            if ($toolCalls === []) {
                if ($text !== '') {
                    $messages[] = ['role' => 'assistant', 'content' => $text];
                }
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $text !== '' ? $text : '(calling tools)'];
            foreach ($toolCalls as $call) {
                try {
                    $output = $this->tools->execute($workspaceRoot, $call);
                } catch (Throwable $e) {
                    $output = $e->getMessage();
                }
                $send(['type' => 'tool_result', 'toolResult' => ['id' => (string) $call['id'], 'output' => $output]]);
                $messages[] = ['role' => 'tool', 'content' => $output, 'toolCallId' => (string) $call['id'], 'name' => (string) $call['name']];
            }
        }

        $send(['type' => 'done']);
    }

    /** @param list<array{path: string, content: string}> $files */
    private function formatContext(array $files): string
    {
        return implode("\n\n", array_map(fn ($f) => '--- ' . ($f['path'] ?? '') . " ---\n" . ($f['content'] ?? ''), $files));
    }
}

// ─── HTTP bootstrap ───────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$root = djAiRoot();
$config = DjConfig::load($root);
$llm = new DjLlm($config);
$indexer = new DjIndexer($config, $llm);
$agent = new DjAgent($llm, $indexer, new DjToolExecutor());
$router = new DjRouter();

$router->get('/health', static function () use ($config, $root): void {
    DjRouter::json([
        'ok' => true,
        'provider' => $config->provider,
        'wormgptUrl' => $config->isWormgpt() ? $config->wormgptUrl : null,
        'root' => $root,
        'workspace' => env('WORKSPACE_ROOT', $root),
        'host' => $_SERVER['HTTP_HOST'] ?? null,
    ]);
});

$router->post('/v1/chat', static function () use ($agent, $root): void {
    $body = DjRouter::body();
    $workspaceRoot = (string) ($body['workspaceRoot'] ?? env('WORKSPACE_ROOT', $root));
    $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
    $rules = is_array($body['rules'] ?? null) ? $body['rules'] : [];
    $contextFiles = is_array($body['contextFiles'] ?? null) ? $body['contextFiles'] : [];
    $model = isset($body['model']) ? (string) $body['model'] : null;
    $stream = ($body['stream'] ?? true) !== false;

    if (!$stream) {
        $chunks = [];
        $agent->run($workspaceRoot, $messages, $rules, $contextFiles, static function (array $c) use (&$chunks): void {
            $chunks[] = $c;
        }, $model);
        DjRouter::json(['chunks' => $chunks]);
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

$router->post('/v1/index', static function () use ($indexer): void {
    $body = DjRouter::body();
    $workspaceRoot = (string) ($body['workspaceRoot'] ?? '');
    if ($workspaceRoot === '') {
        DjRouter::json(['error' => 'workspaceRoot is required'], 400);
        return;
    }
    $paths = is_array($body['paths'] ?? null) ? $body['paths'] : null;
    DjRouter::json(['indexedChunks' => $indexer->index($workspaceRoot, $paths)]);
});

$router->post('/v1/search', static function () use ($indexer): void {
    $body = DjRouter::body();
    $workspaceRoot = (string) ($body['workspaceRoot'] ?? '');
    $query = (string) ($body['query'] ?? '');
    if ($workspaceRoot === '' || $query === '') {
        DjRouter::json(['error' => 'workspaceRoot and query are required'], 400);
        return;
    }
    DjRouter::json(['results' => $indexer->search($workspaceRoot, $query, (int) ($body['limit'] ?? 8))]);
});

$router->dispatch();
