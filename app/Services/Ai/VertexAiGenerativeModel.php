<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\GenerativeModelException;
use App\Services\Ai\Contracts\GenerativeModel;
use Google\ApiCore\ApiException;
use Google\ApiCore\ValidationException;
use Google\Cloud\AIPlatform\V1\Candidate;
use Google\Cloud\AIPlatform\V1\Candidate\FinishReason;
use Google\Cloud\AIPlatform\V1\Client\PredictionServiceClient;
use Google\Cloud\AIPlatform\V1\Content;
use Google\Cloud\AIPlatform\V1\GenerateContentRequest;
use Google\Cloud\AIPlatform\V1\GenerateContentResponse;
use Google\Cloud\AIPlatform\V1\GenerationConfig;
use Google\Cloud\AIPlatform\V1\GenerationConfig\ThinkingConfig;
use Google\Cloud\AIPlatform\V1\Part;
use Google\Cloud\AIPlatform\V1\Schema;

/**
 * Gemini on Vertex AI through the official SDK.
 *
 * Authentication is Application Default Credentials only: `gcloud auth
 * application-default login` on a developer machine, the service account of the
 * service on Cloud Run. No key file is read from the repository or the image.
 *
 * The transport is REST on purpose: the image ships without the grpc extension,
 * which does not get along with Swoole.
 */
class VertexAiGenerativeModel implements GenerativeModel
{
    private ?PredictionServiceClient $client = null;

    public function __construct(
        private readonly ?string $project,
        private readonly string $location,
        private readonly string $model,
        private readonly int $maxOutputTokens,
        private readonly float $temperature,
        private readonly ?int $thinkingBudget,
        private readonly int $timeoutSeconds,
    ) {}

    public function generateJson(string $systemInstruction, string $prompt, array $schema): GeneratedJson
    {
        $responseSchema = new Schema;
        $responseSchema->mergeFromJsonString((string) json_encode($schema, JSON_THROW_ON_ERROR));

        $answer = $this->generate(
            $systemInstruction,
            [['role' => 'user', 'text' => $prompt]],
            $this->generationConfig()
                ->setResponseMimeType('application/json')
                ->setResponseSchema($responseSchema),
        );

        return new GeneratedJson($answer->text, $answer->model, $answer->inputTokens, $answer->outputTokens, $answer->thinkingTokens);
    }

    public function converse(string $systemInstruction, array $messages): GeneratedText
    {
        return $this->generate($systemInstruction, $messages, $this->generationConfig());
    }

    private function generationConfig(): GenerationConfig
    {
        $generationConfig = (new GenerationConfig)
            ->setCandidateCount(1)
            ->setMaxOutputTokens($this->maxOutputTokens)
            ->setTemperature($this->temperature);

        if ($this->thinkingBudget !== null) {
            $generationConfig->setThinkingConfig(
                (new ThinkingConfig)->setThinkingBudget($this->thinkingBudget),
            );
        }

        return $generationConfig;
    }

    /**
     * @param  array<int, array{role: 'user'|'model', text: string}>  $messages
     *
     * @throws GenerativeModelException
     */
    private function generate(string $systemInstruction, array $messages, GenerationConfig $generationConfig): GeneratedText
    {
        if (blank($this->project)) {
            throw GenerativeModelException::notConfigured('VERTEX_AI_PROJECT');
        }

        $request = (new GenerateContentRequest)
            ->setModel("projects/{$this->project}/locations/{$this->location}/publishers/google/models/{$this->model}")
            ->setSystemInstruction((new Content)->setParts([(new Part)->setText($systemInstruction)]))
            ->setContents(array_map(
                fn (array $message): Content => (new Content)
                    ->setRole($message['role'])
                    ->setParts([(new Part)->setText($message['text'])]),
                $messages,
            ))
            ->setGenerationConfig($generationConfig);

        try {
            $response = $this->client()->generateContent($request, ['timeoutMillis' => $this->timeoutSeconds * 1000]);
        } catch (ApiException|ValidationException $exception) {
            throw GenerativeModelException::requestFailed($exception);
        }

        return $this->toGeneratedText($response);
    }

    private function toGeneratedText(GenerateContentResponse $response): GeneratedText
    {
        /** @var Candidate|null $candidate */
        $candidate = $response->getCandidates()[0] ?? null;
        $finishReason = $candidate?->getFinishReason() ?? FinishReason::FINISH_REASON_UNSPECIFIED;

        if ($candidate === null || $finishReason !== FinishReason::STOP) {
            throw GenerativeModelException::incompleteAnswer(FinishReason::name($finishReason));
        }

        $text = '';

        /** @var Part $part */
        foreach ($candidate->getContent()?->getParts() ?? [] as $part) {
            if (! $part->getThought()) {
                $text .= $part->getText();
            }
        }

        $usage = $response->getUsageMetadata();

        return new GeneratedText(
            text: $text,
            model: $response->getModelVersion() ?: $this->model,
            inputTokens: (int) $usage?->getPromptTokenCount(),
            outputTokens: (int) $usage?->getCandidatesTokenCount(),
            thinkingTokens: (int) $usage?->getThoughtsTokenCount(),
        );
    }

    /**
     * Built on first use and kept: the OAuth token it holds is reused by every
     * later call of the same worker instead of being requested again.
     *
     * @throws ValidationException also when the machine has no Application Default Credentials
     */
    private function client(): PredictionServiceClient
    {
        return $this->client ??= new PredictionServiceClient([
            'transport' => 'rest',
            'apiEndpoint' => $this->location === 'global'
                ? 'aiplatform.googleapis.com'
                : "{$this->location}-aiplatform.googleapis.com",
            // User credentials (local ADC) carry no project of their own: without
            // this the API rejects the call for lack of a quota project.
            'credentialsConfig' => ['quotaProject' => $this->project],
        ]);
    }
}
