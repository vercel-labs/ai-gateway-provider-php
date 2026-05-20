<?php

/**
 * Class Vercel\AiGatewayProvider\Models\AiGatewayVideoGenerationModel
 *
 * @since 1.0.0
 *
 * @package Vercel\AiGatewayProvider
 */

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Models;

use Vercel\AiGatewayProvider\Authentication\AiGatewayRequestAuthentication;
use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\VideoGeneration\Contracts\VideoGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Class for the Vercel AI Gateway video generation model.
 *
 * @since 1.0.0
 *
 * @phpstan-type VideoData array{type?: string, url?: string, data?: string, mediaType?: string}
 * @phpstan-type SsePayload array{
 *     type?: string,
 *     videos?: list<VideoData>,
 *     warnings?: list<array<string, mixed>>,
 *     providerMetadata?: array<string, array<string, mixed>>,
 *     message?: string,
 *     errorType?: string,
 *     statusCode?: int,
 *     param?: mixed
 * }
 */
class AiGatewayVideoGenerationModel extends AbstractApiBasedModel implements VideoGenerationModelInterface
{
    use WithAspectRatioTrait;
    use WithProviderOptionsTrait;

    /** @var list<string> */
    private const KNOWN_TOP_LEVEL_OPTIONS = ['resolution', 'duration', 'fps', 'seed'];

    /**
     * @var string The full gateway model ID (e.g. "google/veo-3.0-fast-generate-001").
     */
    private string $gatewayModelId;

    /**
     * Constructor.
     *
     * @since 1.0.0
     *
     * @param ModelMetadata    $metadata         The metadata for the model.
     * @param ProviderMetadata $providerMetadata The metadata for the model's provider.
     * @param string           $gatewayModelId   The full gateway model ID.
     */
    public function __construct(
        ModelMetadata $metadata,
        ProviderMetadata $providerMetadata,
        string $gatewayModelId
    ) {
        parent::__construct($metadata, $providerMetadata);
        $this->gatewayModelId = $gatewayModelId;
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function getGatewayModelId(): string
    {
        return $this->gatewayModelId;
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    public function getRequestAuthentication(): RequestAuthenticationInterface
    {
        $requestAuthentication = parent::getRequestAuthentication();
        if (!$requestAuthentication instanceof ApiKeyRequestAuthentication) {
            return $requestAuthentication;
        }
        return new AiGatewayRequestAuthentication($requestAuthentication->getApiKey());
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    public function generateVideoResult(array $prompt): GenerativeAiResult
    {
        $promptParts = $this->extractPromptParts($prompt);
        $config = $this->getConfig();

        $requestBody = [
            'prompt' => $promptParts['prompt'],
            'n' => $config->getCandidateCount() ?? 1,
        ];

        $aspectRatio = $this->resolveAspectRatio($config);
        if ($aspectRatio !== null) {
            $requestBody['aspectRatio'] = $aspectRatio;
        }

        if ($promptParts['image'] !== null) {
            $requestBody['image'] = $promptParts['image'];
        }

        $requestBody = $this->amendProviderOptions(
            $requestBody,
            $config->getCustomOptions(),
            self::KNOWN_TOP_LEVEL_OPTIONS
        );

        $request = new Request(
            HttpMethodEnum::POST(),
            AiGatewayProvider::url('video-model'),
            [
                'Content-Type' => 'application/json',
                'Accept' => 'text/event-stream',
            ],
            $requestBody
        );
        $request = $request->withHeader('ai-video-model-specification-version', '4');
        $request = $request->withHeader('ai-model-id', $this->gatewayModelId);
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        $requestOptions = $this->getRequestOptions();
        if ($requestOptions !== null) {
            $request = $request->withOptions($requestOptions);
        }

        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseResponseToGenerativeAiResult($response);
    }

    /**
     * Extracts the prompt text and optional image part from the prompt messages.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return array{prompt: string, image: array<string, string>|null} The extracted prompt.
     *
     * @throws InvalidArgumentException If the prompt is not valid for video generation.
     */
    private function extractPromptParts(array $prompt): array
    {
        if (count($prompt) !== 1) {
            throw new InvalidArgumentException(
                'Video generation requires exactly one message.'
            );
        }

        $message = $prompt[0];
        if (!$message->getRole()->isUser()) {
            throw new InvalidArgumentException(
                'Video generation requires a user message.'
            );
        }

        $promptText = null;
        $imagePart = null;
        foreach ($message->getParts() as $part) {
            $text = $part->getText();
            if ($text !== null && $promptText === null) {
                $promptText = $text;
                continue;
            }

            $file = $part->getFile();
            if ($file !== null && $imagePart === null && $file->isImage()) {
                if ($file->isRemote()) {
                    $imagePart = [
                        'type' => 'url',
                        'url' => (string) $file->getUrl(),
                    ];
                } else {
                    $imagePart = [
                        'type' => 'file',
                        'mediaType' => $file->getMimeType(),
                        'data' => (string) $file->getBase64Data(),
                    ];
                }
            }
        }

        if ($promptText === null) {
            throw new InvalidArgumentException(
                'Video generation requires a text part in the message.'
            );
        }

        return [
            'prompt' => $promptText,
            'image' => $imagePart,
        ];
    }

    /**
     * Parses the API response into a GenerativeAiResult.
     *
     * @since 1.0.0
     *
     * @param Response $response The HTTP response.
     * @return GenerativeAiResult The parsed result.
     *
     * @throws ResponseException If the response data is invalid.
     */
    private function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        $body = $response->getBody();
        if ($body === null || $body === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'body');
        }

        $payload = $this->parseSseFirstDataEvent($body);
        if ($payload === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'data');
        }

        $type = $payload['type'] ?? null;
        if ($type === 'error') {
            $message = isset($payload['message']) && is_string($payload['message'])
                ? $payload['message']
                : 'Unknown error from AI Gateway video model.';
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new ResponseException(sprintf(
                'AI Gateway video model error: %s',
                $message
            ));
        }

        if ($type !== 'result' || !isset($payload['videos']) || !is_array($payload['videos'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'videos');
        }

        $candidates = [];
        foreach ($payload['videos'] as $video) {
            if (!is_array($video)) {
                continue;
            }
            $videoType = $video['type'] ?? null;
            $mediaType = isset($video['mediaType']) && is_string($video['mediaType'])
                ? $video['mediaType']
                : 'video/mp4';

            if ($videoType === 'url' && isset($video['url']) && is_string($video['url'])) {
                $file = new File($video['url'], $mediaType);
            } elseif ($videoType === 'base64' && isset($video['data']) && is_string($video['data'])) {
                $file = new File($video['data'], $mediaType);
            } else {
                continue;
            }

            $part = new MessagePart($file);
            $message = new Message(MessageRoleEnum::model(), [$part]);
            $candidates[] = new Candidate($message, FinishReasonEnum::stop());
        }

        if (count($candidates) === 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'videos',
                'No valid video entries found in response.'
            );
        }

        $id = isset($payload['providerMetadata']['gateway']['generationId'])
            && is_string($payload['providerMetadata']['gateway']['generationId'])
            ? $payload['providerMetadata']['gateway']['generationId']
            : '';

        $additionalData = $payload;
        unset($additionalData['type'], $additionalData['videos']);

        return new GenerativeAiResult(
            $id,
            $candidates,
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * Parses the first SSE data event from a response body.
     *
     * The AI Gateway video endpoint returns a single SSE data event containing
     * the entire JSON payload on one line.
     *
     * @since 1.0.0
     *
     * @param string $body The SSE response body.
     * @return SsePayload|null The decoded payload, or null if no valid data event was found.
     */
    private function parseSseFirstDataEvent(string $body): ?array
    {
        $lines = preg_split('/\r?\n/', $body);
        if ($lines === false) {
            return null;
        }

        foreach ($lines as $line) {
            if (strpos($line, 'data:') !== 0) {
                continue;
            }

            $jsonData = ltrim(substr($line, 5));
            if ($jsonData === '') {
                continue;
            }

            $decoded = json_decode($jsonData, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                continue;
            }

            /** @var SsePayload $decoded */
            return $decoded;
        }

        return null;
    }
}
