<?php

/**
 * Class Vercel\AiGatewayProvider\Models\AiGatewayImageGenerationModel
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
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Class for the Vercel AI Gateway image generation model.
 *
 * @since 1.0.0
 *
 * @phpstan-type ResponseData array{
 *     images?: list<string>,
 *     usage?: array{inputTokens?: int, outputTokens?: int, totalTokens?: int},
 *     providerMetadata?: array<string, array<string, mixed>>
 * }
 */
class AiGatewayImageGenerationModel extends AbstractApiBasedModel implements ImageGenerationModelInterface
{
    use WithAspectRatioTrait;
    use WithProviderOptionsTrait;

    /** @var list<string> */
    private const KNOWN_TOP_LEVEL_OPTIONS = ['seed'];

    /**
     * @var string The full gateway model ID (e.g. "openai/gpt-image-1").
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
     * @since n.e.x.t
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
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        $promptText = $this->extractPromptText($prompt);
        $config = $this->getConfig();

        $requestBody = [
            'prompt' => $promptText,
            'n' => $config->getCandidateCount() ?? 1,
        ];

        $aspectRatio = $this->resolveAspectRatio($config);
        if ($aspectRatio !== null) {
            $requestBody['aspectRatio'] = $aspectRatio;
        }

        $requestBody = $this->amendProviderOptions(
            $requestBody,
            $config->getCustomOptions(),
            self::KNOWN_TOP_LEVEL_OPTIONS
        );

        $request = new Request(
            HttpMethodEnum::POST(),
            AiGatewayProvider::url('image-model'),
            ['Content-Type' => 'application/json'],
            $requestBody
        );
        $request = $request->withHeader('ai-image-model-specification-version', '3');
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
     * Extracts the prompt text from the prompt messages.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return string The prompt text.
     *
     * @throws InvalidArgumentException If the prompt is not valid for image generation.
     */
    private function extractPromptText(array $prompt): string
    {
        if (count($prompt) !== 1) {
            throw new InvalidArgumentException(
                'Image generation requires exactly one message.'
            );
        }

        $message = $prompt[0];
        if (!$message->getRole()->isUser()) {
            throw new InvalidArgumentException(
                'Image generation requires a user message.'
            );
        }

        foreach ($message->getParts() as $part) {
            $text = $part->getText();
            if ($text !== null) {
                return $text;
            }
        }

        throw new InvalidArgumentException(
            'Image generation requires a text part in the message.'
        );
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
        /** @var ResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['images']) || !$responseData['images']) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'images');
        }

        $id = isset($responseData['providerMetadata']['gateway']['generationId'])
            && is_string($responseData['providerMetadata']['gateway']['generationId'])
            ? $responseData['providerMetadata']['gateway']['generationId']
            : '';

        $data = $responseData;

        $candidates = [];
        foreach ($data['images'] as $imageBase64) {
            $file = new File($imageBase64, 'image/png');
            $part = new MessagePart($file);
            $message = new Message(MessageRoleEnum::model(), [$part]);
            $candidates[] = new Candidate($message, FinishReasonEnum::stop());
        }

        $tokenUsage = $this->parseTokenUsage($data);

        $additionalData = $responseData;
        unset(
            $additionalData['images'],
            $additionalData['usage']
        );

        return new GenerativeAiResult(
            $id,
            $candidates,
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * Parses token usage from the response data.
     *
     * @since 1.0.0
     *
     * @param ResponseData $data The response data.
     * @return TokenUsage The parsed token usage.
     */
    private function parseTokenUsage(array $data): TokenUsage
    {
        if (!isset($data['usage']) || !is_array($data['usage'])) {
            return new TokenUsage(0, 0, 0);
        }

        $usage = $data['usage'];
        $inputTokens = isset($usage['inputTokens']) && is_numeric($usage['inputTokens'])
            ? (int) $usage['inputTokens']
            : 0;
        $outputTokens = isset($usage['outputTokens']) && is_numeric($usage['outputTokens'])
            ? (int) $usage['outputTokens']
            : 0;
        $totalTokens = isset($usage['totalTokens']) && is_numeric($usage['totalTokens'])
            ? (int) $usage['totalTokens']
            : ($inputTokens + $outputTokens);

        return new TokenUsage($inputTokens, $outputTokens, $totalTokens);
    }
}
