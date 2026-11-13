<?php

namespace App\Services\Projects\Storage;

use App\Enums\ProjectFileAction;
use App\Exceptions\Projects\ProjectStorageException;
use App\Models\Project;
use App\Services\Projects\Storage\Contracts\ProjectFileStore;
use Google\Cloud\Core\Exception\GoogleException;
use Google\Cloud\Core\Exception\NotFoundException;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;

/**
 * Project files in a private Cloud Storage bucket. Credentials are Application
 * Default Credentials, like Vertex AI: no key file. History relies on the
 * bucket's object versioning: the version returned is the object's generation.
 */
class GcsProjectFileStore implements ProjectFileStore
{
    private ?Bucket $resolvedBucket = null;

    public function __construct(
        private readonly ?string $project,
        private readonly ?string $bucket,
    ) {}

    public function put(Project $project, ProjectFileAction $action, string $path, string $contents): string
    {
        $name = ProjectFilePath::object($project, $action, $path);

        try {
            $object = $this->bucket()->upload($contents, ['name' => $name]);

            return (string) ($object->info()['generation'] ?? '');
        } catch (GoogleException $exception) {
            throw ProjectStorageException::requestFailed($exception);
        }
    }

    public function get(Project $project, ProjectFileAction $action, string $path): ?string
    {
        $name = ProjectFilePath::object($project, $action, $path);

        try {
            return $this->bucket()->object($name)->downloadAsString();
        } catch (NotFoundException) {
            return null;
        } catch (GoogleException $exception) {
            throw ProjectStorageException::requestFailed($exception);
        }
    }

    public function paths(Project $project, ProjectFileAction $action): array
    {
        $prefix = ProjectFilePath::prefix($project, $action);
        $paths = [];

        try {
            foreach ($this->bucket()->objects(['prefix' => $prefix]) as $object) {
                $paths[] = substr($object->name(), strlen($prefix));
            }
        } catch (GoogleException $exception) {
            throw ProjectStorageException::requestFailed($exception);
        }

        return $paths;
    }

    private function bucket(): Bucket
    {
        if (blank($this->bucket)) {
            throw ProjectStorageException::notConfigured();
        }

        return $this->resolvedBucket ??= (new StorageClient(array_filter(['projectId' => $this->project])))
            ->bucket((string) $this->bucket);
    }
}
