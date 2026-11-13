<?php

namespace App\Console\Commands;

use App\Exceptions\Ai\GenerativeModelException;
use App\Exceptions\Ai\InvalidAnalysisException;
use App\Services\CloudArchitectAgent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Entry point to try the agent from a terminal. Without --context it analyses a
 * simulated repository, so it also works as a smoke test of the Vertex AI setup.
 * With --output the generated files are written there instead of printed.
 * Every run is a billed call to the model.
 *
 * @phpstan-import-type RepositoryContext from CloudArchitectAgent
 */
#[Signature('architect:analyze
    {--context= : JSON file with the repository context (repository, files, manifests)}
    {--output= : Directory where the generated Dockerfile and deployer/ folder are written}')]
#[Description('Analyse a GitHub repository with Vertex AI and generate its Dockerfile and deployer/ folder')]
class AnalyzeRepositoryCommand extends Command
{
    public function handle(CloudArchitectAgent $agent): int
    {
        $repository = $this->repositoryContext();

        if ($repository === null) {
            return self::INVALID;
        }

        try {
            $analysis = $agent->analyze($repository);
        } catch (GenerativeModelException|InvalidAnalysisException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $output = $this->option('output');

        if (! is_string($output) || $output === '') {
            $this->line((string) json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->writeFiles($analysis['files'], $output);

        $this->line((string) json_encode(
            [...$analysis, 'files' => array_column($analysis['files'], 'path')],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return self::SUCCESS;
    }

    /**
     * Paths arrive already restricted by the agent to Dockerfile, .dockerignore and deployer/.
     *
     * @param  array<int, array{path: string, content: string}>  $files
     */
    private function writeFiles(array $files, string $directory): void
    {
        foreach ($files as $file) {
            $path = rtrim($directory, '/').'/'.$file['path'];

            File::ensureDirectoryExists(dirname($path));
            File::put($path, $file['content']);

            $this->components->info("Written {$path}");
        }
    }

    /**
     * @return RepositoryContext|null
     */
    private function repositoryContext(): ?array
    {
        $path = $this->option('context');

        if (! is_string($path) || $path === '') {
            return $this->simulatedRepository();
        }

        $context = File::exists($path) ? json_decode(File::get($path), true) : null;

        if (! is_array($context) || ! is_string($context['repository'] ?? null)) {
            $this->components->error("[{$path}] must be a JSON file with at least a \"repository\" key.");

            return null;
        }

        /** @var RepositoryContext */
        return $context;
    }

    /**
     * What the GitHub connector would hand over for a small Node API: the tree and
     * the manifests that give the stack away.
     *
     * @return RepositoryContext
     */
    private function simulatedRepository(): array
    {
        return [
            'repository' => 'acme/orders-api',
            'default_branch' => 'main',
            'description' => 'REST API for the orders of an online shop',
            'files' => [
                '.env.example',
                '.nvmrc',
                'package.json',
                'package-lock.json',
                'prisma/schema.prisma',
                'src/server.js',
                'src/routes/orders.js',
                'src/queues/mailer.js',
                'README.md',
            ],
            'manifests' => [
                'package.json' => <<<'JSON'
                    {
                      "name": "orders-api",
                      "engines": { "node": ">=22" },
                      "scripts": { "start": "node src/server.js", "migrate": "prisma migrate deploy" },
                      "dependencies": { "express": "^5.1.0", "@prisma/client": "^6.5.0", "bullmq": "^5.40.0", "ioredis": "^5.6.0" }
                    }
                    JSON,
                '.nvmrc' => '22',
                '.env.example' => "PORT=3000\nDATABASE_URL=postgresql://user:pass@localhost:5432/orders\nREDIS_URL=redis://localhost:6379\nJWT_SECRET=\nMAIL_FROM=orders@acme.test",
                'src/server.js' => "const app = require('express')();\napp.use('/orders', require('./routes/orders'));\napp.listen(process.env.PORT || 3000);",
            ],
        ];
    }
}
