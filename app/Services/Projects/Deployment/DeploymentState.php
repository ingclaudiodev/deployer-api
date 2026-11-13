<?php

namespace App\Services\Projects\Deployment;

/**
 * Where the run of a deployment stands, whatever runs it.
 */
enum DeploymentState
{
    case Running;
    case Succeeded;
    case Failed;
}
