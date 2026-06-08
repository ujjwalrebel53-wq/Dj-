<?php

declare(strict_types=1);

namespace DjAi\Support;

final class DefaultTools
{
    /** @return list<array{name: string, description: string, parameters: array<string, mixed>}> */
    public static function all(): array
    {
        return [
            [
                'name' => 'read_file',
                'description' => 'Read the contents of a file in the workspace',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => [
                            'type' => 'string',
                            'description' => 'Relative path from workspace root',
                        ],
                    ],
                    'required' => ['path'],
                ],
            ],
            [
                'name' => 'write_file',
                'description' => 'Write or overwrite a file in the workspace',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                    ],
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
                        'glob' => [
                            'type' => 'string',
                            'description' => 'Optional glob filter, e.g. **/*.php',
                        ],
                    ],
                    'required' => ['pattern'],
                ],
            ],
            [
                'name' => 'run_terminal',
                'description' => 'Run a shell command in the workspace',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'command' => ['type' => 'string'],
                        'cwd' => ['type' => 'string'],
                    ],
                    'required' => ['command'],
                ],
            ],
        ];
    }
}
