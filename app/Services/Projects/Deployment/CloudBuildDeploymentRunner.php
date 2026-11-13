<?php

namespace App\Services\Projects\Deployment;

use App\Exceptions\Projects\DeploymentException;
use App\Services\Projects\Deployment\Contracts\DeploymentRunner;
use Google\Auth\ApplicationDefaultCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Runs a deployment as one build of Cloud Build in Deployer's own "factory"
 * project, as the service account dedicated to deployments. That account, not
 * the API's, is the one allowed to create cloud projects in the organization.
 *
 * REST on purpose, like Vertex AI: no gRPC next to Swoole. The API authenticates
 * with Application Default Credentials and needs to be allowed to create builds
 * and to act as the deployments account.
 */
class CloudBuildDeploymentRunner implements DeploymentRunner
{
    private const string API = 'https://cloudbuild.googleapis.com/v1';

    private const string SCOPE = 'https://www.googleapis.com/auth/cloud-platform';

    /** Image with gcloud, curl and tar. */
    private const string IMAGE = 'gcr.io/google.com/cloudsdktool/cloud-sdk:slim';

    private const int TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly ?string $project,
        private readonly string $region,
        private readonly ?string $serviceAccount,
        private readonly int $buildTimeoutSeconds,
    ) {}

    public function start(string $script, array $environment, array $tags = []): string
    {
        $env = [];

        foreach ($environment as $name => $value) {
            $env[] = "{$name}={$value}";
        }

        $response = $this->send('post', '/builds', [
            'steps' => [[
                'name' => self::IMAGE,
                'entrypoint' => 'bash',
                'args' => ['-c', $script],
                'env' => $env,
            ]],
            'serviceAccount' => "projects/{$this->required($this->project, 'DEPLOYMENT_PROJECT')}/serviceAccounts/{$this->required($this->serviceAccount, 'DEPLOYMENT_SERVICE_ACCOUNT')}",
            'timeout' => "{$this->buildTimeoutSeconds}s",
            // A build with its own service account must say where its logs go.
            'options' => ['logging' => 'CLOUD_LOGGING_ONLY'],
            'tags' => $tags,
        ]);

        $id = $response['metadata']['build']['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw DeploymentException::unexpectedAnswer(200, 'No build id in the answer.');
        }

        return $id;
    }

    public function state(string $runId): DeploymentState
    {
        $status = $this->send('get', "/builds/{$runId}")['status'] ?? null;

        return match ($status) {
            'SUCCESS' => DeploymentState::Succeeded,
            'QUEUED', 'PENDING', 'WORKING' => DeploymentState::Running,
            default => DeploymentState::Failed,
        };
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $body = []): array
    {
        $url = self::API."/projects/{$this->required($this->project, 'DEPLOYMENT_PROJECT')}/locations/{$this->region}{$path}";

        try {
            $response = $method === 'post' ? $this->client()->post($url, $body) : $this->client()->get($url);
        } catch (ConnectionException $exception) {
            throw DeploymentException::requestFailed($exception);
        }

        if (! $response->successful()) {
            throw DeploymentException::unexpectedAnswer($response->status(), $response->body());
        }

        return (array) $response->json();
    }

    private function client(): PendingRequest
    {
        try {
            $token = ApplicationDefaultCredentials::getCredentials(self::SCOPE)->fetchAuthToken()['access_token'] ?? null;
        } catch (Throwable $exception) {
            throw DeploymentException::requestFailed($exception);
        }

        if (! is_string($token) || $token === '') {
            throw DeploymentException::notConfigured('Application Default Credentials');
        }

        return Http::withToken($token)->acceptJson()->timeout(self::TIMEOUT_SECONDS);
    }

    private function required(?string $value, string $key): string
    {
        return $value !== null && $value !== '' ? $value : throw DeploymentException::notConfigured($key);
    }
}
