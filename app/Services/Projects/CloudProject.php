<?php

namespace App\Services\Projects;

use App\Models\Project;

/**
 * The cloud project a Deployer project runs in: one per project, inside the
 * organization. Only its id is decided here; creating it is part of the
 * deployment.
 */
final class CloudProject
{
    /** The platform accepts 6 to 30 characters. */
    private const int MAX_LENGTH = 30;

    /**
     * The id is derived, never stored: the same project always gets the same
     * one. Lower case letters, digits and hyphens, starting with a letter. A
     * name too long to fit is cut and told apart by a short hash, so two long
     * names that share a beginning never collide.
     */
    public static function idFor(Project $project): string
    {
        $prefix = (string) config('projects.platform.project_prefix');
        $id = "{$prefix}-{$project->user_id}-{$project->name}";

        if (strlen($id) <= self::MAX_LENGTH) {
            return $id;
        }

        $hash = substr(hash('sha256', $id), 0, 6);

        return rtrim(substr($id, 0, self::MAX_LENGTH - 7), '-').'-'.$hash;
    }

    /**
     * The provider's region behind the public region of the project.
     */
    public static function regionFor(Project $project): string
    {
        return (string) config("projects.regions.{$project->region}");
    }
}
