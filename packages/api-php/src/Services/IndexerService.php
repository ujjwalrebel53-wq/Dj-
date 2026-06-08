<?php

declare(strict_types=1);

namespace DjAi\Services;

use DjAi\Config;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class IndexerService
{
    public function __construct(
        private readonly Config $config,
        private readonly LlmService $llm,
    ) {}

    /** @param list<string>|null $paths */
    public function indexWorkspace(string $workspaceRoot, ?array $paths = null): int
    {
        $files = $paths ?? $this->discoverFiles($workspaceRoot);
        $allChunks = [];

        foreach (array_slice($files, 0, 500) as $file) {
            $full = $workspaceRoot . '/' . ltrim($file, '/');
            if (!is_file($full)) {
                continue;
            }

            $content = (string) file_get_contents($full);
            foreach ($this->chunkFile($file, $content) as $chunk) {
                $chunk['embedding'] = $this->llm->embedText($chunk['path'] . "\n" . $chunk['text']);
                $allChunks[] = $chunk;
            }
        }

        $out = $this->indexPath($workspaceRoot);
        $dir = dirname($out);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Failed to create index directory');
        }

        file_put_contents($out, json_encode($allChunks, JSON_UNESCAPED_UNICODE));
        return count($allChunks);
    }

    /** @return list<array{path: string, score: float, snippet: string}> */
    public function searchWorkspace(string $workspaceRoot, string $query, int $limit = 8): array
    {
        $out = $this->indexPath($workspaceRoot);
        if (!is_file($out)) {
            return [];
        }

        $chunks = json_decode((string) file_get_contents($out), true);
        if (!is_array($chunks)) {
            return [];
        }

        $queryEmbedding = $this->llm->embedText($query);
        $ranked = [];

        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }

            $embedding = $chunk['embedding'] ?? [];
            if (!is_array($embedding)) {
                continue;
            }

            $ranked[] = [
                'path' => (string) ($chunk['path'] ?? ''),
                'score' => $this->cosineSimilarity($queryEmbedding, array_map('floatval', $embedding)),
                'snippet' => substr((string) ($chunk['text'] ?? ''), 0, 300),
            ];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($ranked, 0, $limit);
    }

    /** @return list<string> */
    private function discoverFiles(string $workspaceRoot): array
    {
        $extensions = ['php', 'ts', 'tsx', 'js', 'jsx', 'py', 'go', 'rs', 'java', 'md', 'json'];
        $root = realpath($workspaceRoot) ?: $workspaceRoot;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if (str_contains($path, '/node_modules/') || str_contains($path, '/.git/') || str_contains($path, '/vendor/')) {
                continue;
            }

            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $extensions, true)) {
                continue;
            }

            $files[] = ltrim(str_replace($root, '', $path), '/');
        }

        return $files;
    }

    /** @return list<array{path: string, startLine: int, endLine: int, text: string, embedding: list<float>}> */
    private function chunkFile(string $path, string $content, int $chunkSize = 40): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $chunks = [];

        for ($i = 0; $i < count($lines); $i += $chunkSize) {
            $slice = array_slice($lines, $i, $chunkSize);
            $chunks[] = [
                'path' => $path,
                'startLine' => $i + 1,
                'endLine' => $i + count($slice),
                'text' => implode("\n", $slice),
                'embedding' => [],
            ];
        }

        return $chunks;
    }

    private function indexPath(string $workspaceRoot): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $workspaceRoot) ?? 'workspace';
        return $this->config->dataDir . '/indexes/' . $safe . '/chunks.json';
    }

    /** @param list<float> $a @param list<float> $b */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        $count = min(count($a), count($b));

        for ($i = 0; $i < $count; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] ** 2;
            $normB += $b[$i] ** 2;
        }

        $denominator = sqrt($normA) * sqrt($normB);
        return $denominator > 0 ? $dot / $denominator : 0.0;
    }
}
