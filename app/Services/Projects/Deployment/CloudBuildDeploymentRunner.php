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

    private const string LOGGING_API = 'https://logging.googleapis.com/v2';

    /** Lines read each time the run is looked at; the rest wait for the next look. */
    private const int LOG_LINES_PER_LOOK = 200;

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
            $env[] = "{$name}={$this->literal($value)}";
        }

        $response = $this->send('post', '/builds', [
            'steps' => [[
                'name' => self::IMAGE,
                'entrypoint' => 'bash',
                'args' => ['-c', $this->literal($script)],
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

    public function output(string $runId, ?string $cursor): array
    {
        // A build with its own service account logs to Cloud Logging only: its output is
        // read from there. The cursor is the time of the last line seen.
        $filter = sprintf('resource.type="build" AND resource.labels.build_id="%s"', $runId)
            .($cursor === null ? '' : sprintf(' AND timestamp>"%s"', $cursor));

        try {
            $response = $this->client()->post(self::LOGGING_API.'/entries:list', [
                'resourceNames' => ["projects/{$this->required($this->project, 'DEPLOYMENT_PROJECT')}"],
                'filter' => $filter,
                'orderBy' => 'timestamp asc',
                'pageSize' => self::LOG_LINES_PER_LOOK,
            ]);
        } catch (ConnectionException $exception) {
            throw DeploymentException::requestFailed($exception);
        }

        if (! $response->successful()) {
            throw DeploymentException::unexpectedAnswer($response->status(), $response->body());
        }

        $lines = [];

        foreach ((array) $response->json('entries') as $entry) {
            if (is_array($entry) && is_string($entry['textPayload'] ?? null)) {
                $lines[] = $entry['textPayload'];
                $cursor = is_string($entry['timestamp'] ?? null) ? $entry['timestamp'] : $cursor;
            }
        }

        return ['lines' => $lines, 'cursor' => $cursor];
    }

    public function failure(string $runId): string
    {
        $build = $this->send('get', "/builds/{$runId}");
        $step = collect($build['steps'] ?? [])->first(fn (mixed $step): bool => is_array($step) && ($step['status'] ?? null) === 'FAILURE');

        return implode(' ', array_filter([
            'Build '.$runId.' ended as '.($build['status'] ?? 'UNKNOWN').'.',
            is_string($build['statusDetail'] ?? null) ? $build['statusDetail'] : null,
            is_string($build['failureInfo']['detail'] ?? null) ? $build['failureInfo']['detail'] : null,
            is_array($step) && isset($step['exitCode']) ? "The script exited with code {$step['exitCode']}." : null,
            // The output of the script is in the logs of the build, not in this answer.
            is_string($build['logUrl'] ?? null) ? 'Logs: '.$build['logUrl'] : null,
        ]));
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

    /**
     * Cloud Build reads `$NAME` in a build as one of its own substitutions and
     * refuses the ones it does not know. The script and its values are shell, not
     * a template: `$$` is how a literal `$` is written.
     */
    private function literal(string $text): string
    {
        return str_replace('$', '$$', $text);
    }

    private function required(?string $value, string $key): string
    {
        return $value !== null && $value !== '' ? $value : throw DeploymentException::notConfigured($key);
    }
}
