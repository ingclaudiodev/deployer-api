<?php

namespace App\Enums;

/**
 * What Deployer did when it wrote a file of a project. The value is the folder
 * the file lives in inside the project's space of the bucket.
 */
enum ProjectFileAction: string
{
    /** The architect's reading of the repository: stack, services, warnings. */
    case Analysis = 'analisis';

    /** The files that deploy the project: `Dockerfile`, `.dockerignore` and `deployer/`. */
    case Deployment = 'despliegue';

    /** Snapshots of the deployment files the owner signed, one folder per approval. Never rewritten. */
    case Approved = 'aprobado';

    /** What each deployment left behind: the services it ended with. */
    case Result = 'resultado';
}
