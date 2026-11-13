<?php

namespace App\Services\Projects\Storage\Contracts;

use App\Enums\ProjectFileAction;
use App\Exceptions\Projects\ProjectStorageException;
use App\Models\Project;
use App\Services\Projects\Storage\ProjectFilePath;

/**
 * Where the files Deployer writes for a project are kept, one space per project
 * ({@see ProjectFilePath}). Writing a path again keeps the earlier content as an
 * older version of the same file.
 */
interface ProjectFileStore
{
    /**
     * @return string The version the store gave this content.
     *
     * @throws ProjectStorageException
     */
    public function put(Project $project, ProjectFileAction $action, string $path, string $contents): string;

    /**
     * The current content of a file; `null` when it was never written.
     *
     * @throws ProjectStorageException
     */
    public function get(Project $project, ProjectFileAction $action, string $path): ?string;

    /**
     * Paths written under an action, relative to it.
     *
     * @return list<string>
     *
     * @throws ProjectStorageException
     */
    public function paths(Project $project, ProjectFileAction $action): array;
}
