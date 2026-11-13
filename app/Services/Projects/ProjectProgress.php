<?php

namespace App\Services\Projects;

use App\Events\Projects\ProjectProgressed;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * What the background jobs of a project are doing, as lines a person can
 * follow: the steps Deployer takes (each with how far along the work is) and
 * the output of the deployment itself. Every line is kept for a while, so the
 * screen can show the story so far, and pushed live to the owner.
 *
 * It is a diary, not a source of truth: the state of a project is in its row.
 * Losing the diary loses nothing but the story, so it lives in the cache.
 *
 * @phpstan-type Entry array{id: int, fecha: string, fase: string, tipo: string, mensaje: string, porcentaje: int}
 */
class ProjectProgress
{
    public const string ANALYSIS = 'analisis';

    public const string DEPLOYMENT = 'despliegue';

    /** A step Deployer takes. */
    public const string STEP = 'paso';

    /** A line of output of the deployment. */
    public const string OUTPUT = 'log';

    public const string ERROR = 'error';

    private const int KEPT_ENTRIES = 500;

    private const int KEPT_DAYS = 3;

    /** One pushed message carries at most this many lines, each cut to this length: the socket has a size limit. */
    private const int LINES_PER_MESSAGE = 30;

    private const int LINE_LENGTH = 400;

    /**
     * Begin the diary of a new attempt: the lines of the previous one go away.
     */
    public function begin(Project $project, string $phase, string $message, int $percent = 1): void
    {
        Cache::forget($this->key($project));

        $this->step($project, $phase, $message, $percent);
    }

    /**
     * A step Deployer takes, with how far along the whole work is (0 to 100).
     */
    public function step(Project $project, string $phase, string $message, int $percent): void
    {
        $this->add($project, $phase, [[self::STEP, $message, $percent]]);
    }

    public function error(Project $project, string $phase, string $message): void
    {
        $this->add($project, $phase, [[self::ERROR, $message, null]]);
    }

    /**
     * Lines of output of the deployment. A line `::deployer:: 40 Texto` is one of
     * the marks Deployer's own script leaves: it counts as a step at 40 %.
     *
     * @param  list<string>  $lines
     */
    public function output(Project $project, string $phase, array $lines): void
    {
        $items = [];

        foreach ($lines as $line) {
            $line = rtrim($line);

            if ($line === '') {
                continue;
            }

            $items[] = preg_match('/^::deployer::\s+(\d{1,3})\s+(.+)$/', $line, $mark) === 1
                ? [self::STEP, $mark[2], min(99, (int) $mark[1])]
                : [self::OUTPUT, $line, null];
        }

        $this->add($project, $phase, $items);
    }

    /**
     * @return list<Entry>
     */
    public function entries(Project $project): array
    {
        $entries = Cache::get($this->key($project));

        return is_array($entries) ? array_values($entries) : [];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int|null}>  $items  Kind, message and percent (`null` keeps the last one).
     */
    private function add(Project $project, string $phase, array $items): void
    {
        if ($items === []) {
            return;
        }

        $entries = $this->entries($project);
        $last = end($entries) ?: null;
        $id = $last['id'] ?? 0;
        $percent = $last['porcentaje'] ?? 0;
        $added = [];

        foreach ($items as [$kind, $message, $itemPercent]) {
            // Progress never goes back: a late line of output must not undo a step.
            $percent = $itemPercent === null ? $percent : max($percent, min(100, $itemPercent));

            $added[] = [
                'id' => ++$id,
                'fecha' => now()->toIso8601String(),
                'fase' => $phase,
                'tipo' => $kind,
                'mensaje' => mb_substr($message, 0, self::LINE_LENGTH),
                'porcentaje' => $percent,
            ];
        }

        Cache::put($this->key($project), array_slice([...$entries, ...$added], -self::KEPT_ENTRIES), now()->addDays(self::KEPT_DAYS));

        foreach (array_chunk($added, self::LINES_PER_MESSAGE) as $chunk) {
            try {
                ProjectProgressed::dispatch($project->id, $project->user_id, $project->status->value, $chunk);
            } catch (Throwable $exception) {
                // The socket server being down must never stop an analysis or a deployment.
                report($exception);
            }
        }
    }

    private function key(Project $project): string
    {
        return "project-progress:{$project->id}";
    }
}
