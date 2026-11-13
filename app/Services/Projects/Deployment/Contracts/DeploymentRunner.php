<?php

namespace App\Services\Projects\Deployment\Contracts;

use App\Exceptions\Projects\DeploymentException;
use App\Services\Projects\Deployment\DeploymentState;

/**
 * Runs a shell script somewhere isolated from Deployer, as an identity that can
 * only do what a deployment needs. The scripts of a deployment are written by
 * a model from a repository nobody here reviewed line by line: they never run
 * inside the API.
 */
interface DeploymentRunner
{
    /**
     * Start running the script with the given environment.
     *
     * @param  array<string, string>  $environment
     * @param  list<string>  $tags
     * @return string Id of the run, to ask for its state later.
     *
     * @throws DeploymentException
     */
    public function start(string $script, array $environment, array $tags = []): string;

    /**
     * @throws DeploymentException
     */
    public function state(string $runId): DeploymentState;
}
