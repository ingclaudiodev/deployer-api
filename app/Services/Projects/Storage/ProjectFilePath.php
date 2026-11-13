<?php

namespace App\Services\Projects\Storage;

use App\Enums\ProjectFileAction;
use App\Exceptions\Projects\ProjectStorageException;
use App\Models\Project;

/**
 * Names of the objects of a project in the bucket:
 * `{user id}/{project name}/{action}/{path of the file}`.
 *
 * The name of a project is unique per user and never changes, so the prefix of
 * a project is stable for its whole life.
 */
final class ProjectFilePath
{
    /** Relative, no empty or dot segments: a file can never land outside its action. */
    private const string SAFE_PATH = '/^(?!\.{1,2}(\/|$))[A-Za-z0-9._-]+(\/(?!\.{1,2}(\/|$))[A-Za-z0-9._-]+)*$/';

    public static function prefix(Project $project, ProjectFileAction $action): string
    {
        return "{$project->user_id}/{$project->name}/{$action->value}/";
    }

    /**
     * @throws ProjectStorageException when the path could leave the action's folder.
     */
    public static function object(Project $project, ProjectFileAction $action, string $path): string
    {
        if (preg_match(self::SAFE_PATH, $path) !== 1) {
            throw ProjectStorageException::unsafePath($path);
        }

        return self::prefix($project, $action).$path;
    }
}
