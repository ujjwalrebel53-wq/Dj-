<?php

declare(strict_types=1);

namespace DjAi\Services;

use DjAi\Config;
use RuntimeException;

final class LlmService
{
    public function __construct(private readonly Config $config) {}

    /** @param list<string> $rules */
    public function buildSystemPrompt(array $rules, string $contextBlock): string
    {
        $rulesBlock = $rules !== []
            ? "\n\nUser rules:\n" . implode("\n", array_map(static fn (string $r): string => '- ' . $r, $rules))
            : '';

        $context = $contextBlock !== ''
            ? "\n\nRelevant workspace context:\n" . $contextBlock
            : '';

        return implode('', [
            'You are Dj AI, a coding assistant similar to Cursor.',
            'You help users write, debug, and understand code in their workspace.',
            'Prefer minimal, focused changes. Use tools when you need file or terminal access.',
            $rulesBlock,
            $context,
        ]);
    }

    /**
     * @param list<array{role: string, content: string, name?: string, toolCallId?: string}> $messages
     * @param list<array{name: string, description: string, parameters: array<string, mixed>}> $tools
     * @param callable(array{type: string, content?: string, toolCall?: array{id: string, name: string, arguments: array<string, mixed>}}): void $onEvent
     */
    public function streamChat(array $messages, array $tools, callable $onEvent, ?string $model = null): void
    {
        $payload = [
            'model' => $model ?? $this->config->llmModel,
            'messages' => $this->toOpenAiMessages($messages),
            'tools' => $this->toOpenAiTools($tools),
            'stream' => true,
        ];

        $pendingCalls = [];

        $this->streamRequest('/chat/completions', $payload, function (array $chunk) use (&$pendingCalls, $onEvent): void {
            $choice = $chunk['choices'][0] ?? null;
            if ($choice === null) {
                return;
            }

            $delta = $choice['delta'] ?? [];
            if (!empty($delta['content'])) {
                $onEvent(['type' => 'text', 'content' => (string) $delta['content']]);
            }

            if (!empty($delta['tool_calls']) && is_array($delta['tool_calls'])) {
                foreach ($delta['tool_calls'] as $toolDelta) {
                    $index = (int) ($toolDelta['index'] ?? 0);
                    if (!isset($pendingCalls[$index])) {
                        $pendingCalls[$index] = [
                            'id' => (string) ($toolDelta['id'] ?? bin2hex(random_bytes(8))),
                            'name' => '',
                            'args' => '',
                        ];
                    }

                    if (!empty($toolDelta['id'])) {
                        $pendingCalls[$index]['id'] = (string) $toolDelta['id'];
                    }
                    if (!empty($toolDelta['function']['name'])) {
                        $pendingCalls[$index]['name'] = (string) $toolDelta['function']['name'];
                    }
                    if (isset($toolDelta['function']['arguments'])) {
                        $pendingCalls[$index]['args'] .= (string) $toolDelta['function']['arguments'];
                    }
                }
            }

            if (($choice['finish_reason'] ?? null) === 'tool_calls') {
                foreach ($pendingCalls as $call) {
                    $args = json_decode($call['args'] !== '' ? $call['args'] : '{}', true);
                    $onEvent([
                        'type' => 'tool_call',
                        'toolCall' => [
                            'id' => $call['id'],
                            'name' => $call['name'],
                            'arguments' => is_array($args) ? $args : [],
                        ],
                    ]);
                }
            }
        });
    }

    /** @return list<float> */
    public function embedText(string $text): array
    {
        $response = $this->postJson('/embeddings', [
            'model' => $this->config->embeddingModel,
            'input' => $text,
        ]);

        $embedding = $response['data'][0]['embedding'] ?? [];
        return is_array($embedding) ? array_map('floatval', $embedding) : [];
    }

    /** @param list<array{role: string, content: string, name?: string, toolCallId?: string}> $messages */
    private function toOpenAiMessages(array $messages): array
    {
        $out = [];
        foreach ($messages as $message) {
            if (($message['role'] ?? '') === 'tool') {
                $out[] = [
                    'role' => 'tool',
                    'content' => (string) ($message['content'] ?? ''),
                    'tool_call_id' => (string) ($message['toolCallId'] ?? 'tool'),
                ];
                continue;
            }

            $item = [
                'role' => (string) ($message['role'] ?? 'user'),
                'content' => (string) ($message['content'] ?? ''),
            ];
            if (!empty($message['name'])) {
                $item['name'] = (string) $message['name'];
            }
            $out[] = $item;
        }

        return $out;
    }

    /** @param list<array{name: string, description: string, parameters: array<string, mixed>}> $tools */
    private function toOpenAiTools(array $tools): array
    {
        return array_map(static fn (array $tool): array => [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['parameters'],
            ],
        ], $tools);
    }

    /** @param array<string, mixed> $payload */
    private function postJson(string $path, array $payload): array
    {
        $ch = curl_init($this->config->llmBaseUrl . $path);
        if ($ch === false) {
            throw new RuntimeException('Failed to init curl');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config->llmApiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 120,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('LLM request failed: ' . $error);
        }

        $decoded = json_decode($body, true);
        if ($status >= 400) {
            $message = is_array($decoded) ? ($decoded['error']['message'] ?? $body) : $body;
            throw new RuntimeException('LLM error (' . $status . '): ' . $message);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $payload */
    private function streamRequest(string $path, array $payload, callable $onData): void
    {
        $buffer = '';

        $ch = curl_init($this->config->llmBaseUrl . $path);
        if ($ch === false) {
            throw new RuntimeException('Failed to init curl');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config->llmApiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 0,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$buffer, $onData): int {
                $buffer .= $chunk;

                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $line = trim($line);

                    if ($line === '' || $line === 'data: [DONE]') {
                        continue;
                    }

                    if (!str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $json = substr($line, 6);
                    $decoded = json_decode($json, true);
                    if (is_array($decoded)) {
                        $onData($decoded);
                    }
                }

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($ok === false) {
            throw new RuntimeException('LLM stream failed: ' . $error);
        }

        if ($status >= 400) {
            throw new RuntimeException('LLM stream error (' . $status . ')');
        }
    }
}
