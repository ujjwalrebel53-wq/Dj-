<?php

declare(strict_types=1);

namespace DjAi\Services;

use DjAi\Support\DefaultTools;
use DjAi\Tools\ToolExecutor;
use Throwable;

final class AgentService
{
    public function __construct(
        private readonly LlmService $llm,
        private readonly IndexerService $indexer,
        private readonly ToolExecutor $tools,
    ) {}

    /**
     * @param list<array{role: string, content: string, name?: string, toolCallId?: string}> $baseMessages
     * @param list<string> $rules
     * @param list<array{path: string, content: string, language?: string}> $contextFiles
     * @param callable(array<string, mixed>): void $send
     */
    public function run(
        string $workspaceRoot,
        array $baseMessages,
        array $rules,
        array $contextFiles,
        callable $send,
        ?string $model = null,
    ): void {
        $messages = $baseMessages;
        $tools = DefaultTools::all();
        $maxSteps = 12;

        for ($step = 0; $step < $maxSteps; $step++) {
            $lastUser = null;
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if (($messages[$i]['role'] ?? '') === 'user') {
                    $lastUser = $messages[$i];
                    break;
                }
            }

            $contextBlock = $this->formatContext($contextFiles);
            if ($workspaceRoot !== '' && $lastUser !== null) {
                $hits = $this->indexer->searchWorkspace($workspaceRoot, (string) ($lastUser['content'] ?? ''), 5);
                if ($hits !== []) {
                    $parts = array_map(
                        static fn (array $hit): string => $hit['path'] . ' (score ' . number_format($hit['score'], 2) . ")\n" . $hit['snippet'],
                        $hits
                    );
                    $contextBlock .= "\n\nSemantic search hits:\n" . implode("\n\n", $parts);
                }
            }

            $systemMessage = [
                'role' => 'system',
                'content' => $this->llm->buildSystemPrompt($rules, $contextBlock),
            ];

            $assistantText = '';
            $toolCalls = [];

            $this->llm->streamChat(
                [$systemMessage, ...$messages],
                $tools,
                function (array $event) use (&$assistantText, &$toolCalls, $send): void {
                    if ($event['type'] === 'text' && isset($event['content'])) {
                        $assistantText .= (string) $event['content'];
                        $send(['type' => 'text', 'content' => (string) $event['content']]);
                    }

                    if ($event['type'] === 'tool_call' && isset($event['toolCall'])) {
                        $toolCalls[] = $event['toolCall'];
                        $send(['type' => 'tool_call', 'toolCall' => $event['toolCall']]);
                    }
                },
                $model
            );

            if ($toolCalls === []) {
                if ($assistantText !== '') {
                    $messages[] = ['role' => 'assistant', 'content' => $assistantText];
                }
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $assistantText !== '' ? $assistantText : '(calling tools)'];

            foreach ($toolCalls as $call) {
                try {
                    $output = $this->tools->execute($workspaceRoot, $call);
                    $send([
                        'type' => 'tool_result',
                        'toolResult' => ['id' => (string) $call['id'], 'output' => $output],
                    ]);
                    $messages[] = [
                        'role' => 'tool',
                        'content' => $output,
                        'toolCallId' => (string) $call['id'],
                        'name' => (string) $call['name'],
                    ];
                } catch (Throwable $e) {
                    $output = $e->getMessage();
                    $send([
                        'type' => 'tool_result',
                        'toolResult' => ['id' => (string) $call['id'], 'output' => $output],
                    ]);
                    $messages[] = [
                        'role' => 'tool',
                        'content' => $output,
                        'toolCallId' => (string) $call['id'],
                        'name' => (string) $call['name'],
                    ];
                }
            }
        }

        $send(['type' => 'done']);
    }

    /** @param list<array{path: string, content: string, language?: string}> $files */
    private function formatContext(array $files): string
    {
        $parts = [];
        foreach ($files as $file) {
            $parts[] = '--- ' . ($file['path'] ?? 'unknown') . " ---\n" . ($file['content'] ?? '');
        }

        return implode("\n\n", $parts);
    }
}
