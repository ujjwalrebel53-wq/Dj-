<?php

declare(strict_types=1);

namespace DjAi\Tools;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class ToolExecutor
{
    /** @param array{id: string, name: string, arguments: array<string, mixed>} $call */
    public function execute(string $workspaceRoot, array $call): string
    {
        $name = $call['name'] ?? '';
        $args = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];

        return match ($name) {
            'read_file' => $this->readFile($workspaceRoot, (string) ($args['path'] ?? '')),
            'write_file' => $this->writeFile(
                $workspaceRoot,
                (string) ($args['path'] ?? ''),
                (string) ($args['content'] ?? ''),
            ),
            'grep' => $this->grep(
                $workspaceRoot,
                (string) ($args['pattern'] ?? ''),
                (string) ($args['glob'] ?? '**/*'),
            ),
            'run_terminal' => $this->runTerminal(
                $workspaceRoot,
                (string) ($args['command'] ?? ''),
                isset($args['cwd']) ? (string) $args['cwd'] : null,
            ),
            default => throw new RuntimeException('Unknown tool: ' . $name),
        };
    }

    private function assertInsideWorkspace(string $workspaceRoot, string $targetPath): string
    {
        $root = realpath($workspaceRoot);
        if ($root === false) {
            throw new RuntimeException('Invalid workspace root');
        }

        $full = realpath($root . '/' . ltrim($targetPath, '/'));
        if ($full === false) {
            $full = $root . '/' . ltrim($targetPath, '/');
        }

        if (!str_starts_with($full, $root)) {
            throw new RuntimeException('Path escapes workspace: ' . $targetPath);
        }

        return $full;
    }

    private function readFile(string $workspaceRoot, string $path): string
    {
        $full = $this->assertInsideWorkspace($workspaceRoot, $path);
        if (!is_file($full)) {
            throw new RuntimeException('File not found: ' . $path);
        }

        return (string) file_get_contents($full);
    }

    private function writeFile(string $workspaceRoot, string $path, string $content): string
    {
        $full = $this->assertInsideWorkspace($workspaceRoot, $path);
        $dir = dirname($full);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory: ' . $dir);
        }

        file_put_contents($full, $content);
        return 'Wrote ' . $path . ' (' . strlen($content) . ' bytes)';
    }

    private function grep(string $workspaceRoot, string $pattern, string $glob): string
    {
        $files = $this->globFiles($workspaceRoot, $glob);
        $hits = [];

        foreach (array_slice($files, 0, 200) as $file) {
            $content = (string) file_get_contents($file);
            $relative = ltrim(str_replace(realpath($workspaceRoot) ?: $workspaceRoot, '', realpath($file) ?: $file), '/');
            $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];

            foreach ($lines as $index => $line) {
                if (@preg_match('/' . $pattern . '/', $line) === 1) {
                    $hits[] = $relative . ':' . ($index + 1) . ': ' . $line;
                    if (count($hits) >= 50) {
                        break 2;
                    }
                }
            }
        }

        return $hits !== [] ? implode("\n", $hits) : 'No matches found';
    }

    private function runTerminal(string $workspaceRoot, string $command, ?string $cwd): string
    {
        $workdir = $cwd !== null && $cwd !== ''
            ? $this->assertInsideWorkspace($workspaceRoot, $cwd)
            : (realpath($workspaceRoot) ?: $workspaceRoot);

        $descriptor = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(['bash', '-lc', $command], $descriptor, $pipes, $workdir);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start process');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $output = trim($stdout . "\n" . $stderr);
        return $output !== '' ? $output : '(no output)';
    }

    /** @return list<string> */
    private function globFiles(string $workspaceRoot, string $globPattern): array
    {
        $root = realpath($workspaceRoot) ?: $workspaceRoot;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $regex = $this->globToRegex($globPattern);
        $files = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if (str_contains($path, '/node_modules/') || str_contains($path, '/.git/')) {
                continue;
            }

            $relative = ltrim(str_replace($root, '', $path), '/');
            if (preg_match($regex, $relative) === 1) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function globToRegex(string $glob): string
    {
        $escaped = preg_quote($glob, '/');
        $regex = str_replace(['\\*\\*', '\\*', '\\?'], ['.*', '[^/]*', '[^/]'], $escaped);
        return '/^' . $regex . '$/i';
    }
}
