<?php

namespace App\Events\Projects;

use App\Services\Projects\ProjectProgress;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something happened in the analysis or the deployment of a project. Goes out
 * over Reverb on the owner's private channel as `.project.progress`, so the
 * screen follows the work without asking.
 *
 * Broadcast immediately: it is raised from queued jobs, and a second hop through
 * the queue would only delay what is meant to be live.
 *
 * @phpstan-import-type Entry from ProjectProgress
 */
class ProjectProgressed implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<Entry>  $entries
     */
    public function __construct(
        public readonly int $projectId,
        public readonly int $userId,
        public readonly string $status,
        public readonly array $entries,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'project.progress';
    }

    /**
     * @return array{proyecto: int, estado: string, entradas: list<Entry>}
     */
    public function broadcastWith(): array
    {
        return ['proyecto' => $this->projectId, 'estado' => $this->status, 'entradas' => $this->entries];
    }
}
