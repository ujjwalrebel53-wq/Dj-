<?php

declare(strict_types=1);

namespace DjAi;

use Dotenv\Dotenv;

final class Config
{
    public function __construct(
        public readonly int $port,
        public readonly string $llmBaseUrl,
        public readonly string $llmApiKey,
        public readonly string $llmModel,
        public readonly string $embeddingModel,
        public readonly string $dataDir,
    ) {}

    public static function load(string $root): self
    {
        if (is_file($root . '/.env')) {
            Dotenv::createImmutable($root)->safeLoad();
        }

        $dataDir = $_ENV['DATA_DIR'] ?? '.data';
        if (!str_starts_with($dataDir, '/')) {
            $dataDir = $root . '/' . $dataDir;
        }

        return new self(
            port: (int) ($_ENV['PORT'] ?? 8787),
            llmBaseUrl: rtrim((string) ($_ENV['LLM_BASE_URL'] ?? 'https://api.openai.com/v1'), '/'),
            llmApiKey: (string) ($_ENV['LLM_API_KEY'] ?? ''),
            llmModel: (string) ($_ENV['LLM_MODEL'] ?? 'gpt-4o'),
            embeddingModel: (string) ($_ENV['EMBEDDING_MODEL'] ?? 'text-embedding-3-small'),
            dataDir: $dataDir,
        );
    }
}
