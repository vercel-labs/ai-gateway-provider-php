<?php

/**
 * Class Vercel\AiGatewayProvider\Models\AiGatewayTextGenerationModel
 *
 * @since 1.0.0
 *
 * @package Vercel\AiGatewayProvider
 */

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Models;

use Vercel\AiGatewayProvider\Authentication\AiGatewayRequestAuthentication;
use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

/**
 * Class for the Vercel AI Gateway text generation model.
 *
 * @since 1.0.0
 *
 * @phpstan-type ResponseContentPart array{
 *     type: string,
 *     text?: string,
 *     toolCallId?: string,
 *     toolName?: string,
 *     input?: mixed,
 *     data?: string,
 *     mediaType?: string,
 *     providerMetadata?: array<string, array<string, mixed>>
 * }
 * @phpstan-type ResponseData array{
 *     content?: ResponseContentPart|list<ResponseContentPart>,
 *     usage?: array<string, mixed>,
 *     finishReason?: array{unified: string, raw?: string}
 * }
 */
class AiGatewayTextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    use WithAspectRatioTrait;
    use WithProviderOptionsTrait;

    private const API_NAME = 'AI Gateway';

    private const FINISH_REASON_MAP = [
        'stop' => FinishReasonEnum::STOP,
        'length' => FinishReasonEnum::LENGTH,
        'content-filter' => FinishReasonEnum::CONTENT_FILTER,
        'tool-calls' => FinishReasonEnum::TOOL_CALLS,
        'error' => FinishReasonEnum::ERROR,
        'other' => FinishReasonEnum::STOP,
    ];

    /**
     * @var string The full gateway model ID (e.g. "anthropic/claude-sonnet-4-6").
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
     * Extracts the provider prefix from the gateway model ID.
     *
     * @since n.e.x.t
     *
     * @return string The provider name (e.g. "anthropic"), or empty string if no slash present.
     */
    private function getProviderName(): string
    {
        $slashPos = strpos($this->gatewayModelId, '/');
        if ($slashPos === false) {
            return '';
        }
        return substr($this->gatewayModelId, 0, $slashPos);
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
    public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $requestBody = $this->buildRequestBody($prompt);

        $request = new Request(
            HttpMethodEnum::POST(),
            AiGatewayProvider::url('language-model'),
            ['Content-Type' => 'application/json'],
            $requestBody
        );
        $request = $request->withHeader('ai-language-model-specification-version', '3');
        $request = $request->withHeader('ai-language-model-id', $this->gatewayModelId);
        $request = $request->withHeader('ai-language-model-streaming', 'false');
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        $requestOptions = $this->getRequestOptions();
        if ($requestOptions !== null) {
            $request = $request->withOptions($requestOptions);
        }

        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        /** @var ResponseData|null $data */
        $data = $response->getData();
        if ($data === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromMissingData(self::API_NAME, 'response body');
        }

        return $this->parseResponse($data);
    }

    /**
     * Builds the request body from the prompt messages.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return array<string, mixed> The request body.
     */
    private function buildRequestBody(array $prompt): array
    {
        $config = $this->getConfig();

        $messages = [];

        $systemInstruction = $config->getSystemInstruction();
        if ($systemInstruction !== null) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemInstruction,
            ];
        }

        foreach ($prompt as $message) {
            $role = $this->mapMessageRole($message);

            $content = [];
            foreach ($message->getParts() as $part) {
                $contentPart = $this->buildContentPart($part);
                if ($contentPart !== null) {
                    $content[] = $contentPart;
                }
            }

            if (count($content) > 0) {
                $messages[] = [
                    'role' => $role,
                    'content' => $content,
                ];
            }
        }

        $body = ['prompt' => $messages];

        $maxTokens = $config->getMaxTokens();
        if ($maxTokens !== null) {
            $body['maxTokens'] = $maxTokens;
        }

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $body['temperature'] = $temperature;
        }

        $topP = $config->getTopP();
        if ($topP !== null) {
            $body['topP'] = $topP;
        }

        $topK = $config->getTopK();
        if ($topK !== null) {
            $body['topK'] = $topK;
        }

        $stopSequences = $config->getStopSequences();
        if (is_array($stopSequences)) {
            $body['stopSequences'] = $stopSequences;
        }

        $frequencyPenalty = $config->getFrequencyPenalty();
        if ($frequencyPenalty !== null) {
            $body['frequencyPenalty'] = $frequencyPenalty;
        }

        $presencePenalty = $config->getPresencePenalty();
        if ($presencePenalty !== null) {
            $body['presencePenalty'] = $presencePenalty;
        }

        $functionDeclarations = $config->getFunctionDeclarations();
        if (is_array($functionDeclarations) && count($functionDeclarations) > 0) {
            $body['tools'] = array_map(
                static function (FunctionDeclaration $decl): array {
                    $tool = [
                        'type' => 'function',
                        'name' => $decl->getName(),
                        'description' => $decl->getDescription(),
                    ];
                    $params = $decl->getParameters();
                    if ($params !== null) {
                        $tool['inputSchema'] = $params;
                    }
                    return $tool;
                },
                $functionDeclarations
            );
        }

        $outputMimeType = $config->getOutputMimeType();
        if ($outputMimeType === 'application/json') {
            $responseFormat = ['type' => 'json'];
            $outputSchema = $config->getOutputSchema();
            if (is_array($outputSchema)) {
                $responseFormat['schema'] = $outputSchema;
            }
            $body['responseFormat'] = $responseFormat;
        }

        $outputModalities = $config->getOutputModalities();
        if (is_array($outputModalities) && count($outputModalities) > 0) {
            $isDefaultTextOnly = count($outputModalities) === 1 && $outputModalities[0]->isText();
            if (!$isDefaultTextOnly) {
                $modalityStrings = array_map(
                    static function (ModalityEnum $modality): string {
                        return strtoupper($modality->value);
                    },
                    $outputModalities
                );

                // Technically, only "google" (and "google-vertex") supports this now.
                $slashPos = strpos($this->gatewayModelId, '/');
                if ($slashPos !== false) {
                    $provider = substr($this->gatewayModelId, 0, $slashPos);
                    $providerValue = ['responseModalities' => $modalityStrings];

                    $hasImageModality = false;
                    foreach ($outputModalities as $modality) {
                        if ($modality->isImage()) {
                            $hasImageModality = true;
                            break;
                        }
                    }
                    if ($hasImageModality) {
                        $aspectRatio = $this->resolveAspectRatio($config);
                        if ($aspectRatio !== null) {
                            $providerValue['imageConfig'] = ['aspectRatio' => $aspectRatio];
                        }
                    }

                    $body['providerOptions'] = [$provider => $providerValue];
                }
            }
        }

        $body = $this->amendProviderOptions($body, $config->getCustomOptions());

        return $body;
    }

    /**
     * Maps a message role to the gateway API role string.
     *
     * @since 1.0.0
     *
     * @param Message $message The message.
     * @return string The role string for the gateway API.
     */
    private function mapMessageRole(Message $message): string
    {
        if ($message->getRole()->isUser()) {
            foreach ($message->getParts() as $part) {
                if ($part->getFunctionResponse() !== null) {
                    return 'tool';
                }
            }
            return 'user';
        }
        return 'assistant';
    }

    /**
     * Builds a single content part from a message part.
     *
     * @since 1.0.0
     *
     * @param MessagePart $part The message part.
     * @return array<string, mixed>|null The content part array, or null if not supported.
     */
    private function buildContentPart(MessagePart $part): ?array
    {
        $isThought = $part->getChannel()->isThought();
        $thoughtSignature = $part->getThoughtSignature();

        $text = $part->getText();
        if ($text !== null) {
            if ($isThought) {
                $contentPart = [
                    'type' => 'reasoning',
                    'text' => $text,
                ];
                if ($thoughtSignature !== null) {
                    $contentPart['providerOptions'] = $this->buildThoughtSignatureProviderOptions($thoughtSignature);
                }
                return $contentPart;
            }

            $contentPart = [
                'type' => 'text',
                'text' => $text,
            ];
            if ($thoughtSignature !== null) {
                $contentPart['providerOptions'] = $this->buildThoughtSignatureProviderOptions($thoughtSignature);
            }
            return $contentPart;
        }

        $file = $part->getFile();
        if ($file !== null) {
            $mediaType = $file->getMimeType();
            $fileContentPart = null;

            $url = $file->getUrl();
            if ($url !== null) {
                $fileContentPart = [
                    'type' => 'file',
                    'data' => $url,
                    'mediaType' => $mediaType,
                ];
            }

            if ($fileContentPart === null) {
                $dataUri = $file->getDataUri();
                if ($dataUri !== null) {
                    $fileContentPart = [
                        'type' => 'file',
                        'data' => $dataUri,
                        'mediaType' => $mediaType,
                    ];
                }
            }

            if ($fileContentPart !== null) {
                if ($thoughtSignature !== null) {
                    $fileContentPart['providerOptions'] = $this->buildThoughtSignatureProviderOptions(
                        $thoughtSignature
                    );
                }
                return $fileContentPart;
            }
        }

        $functionCall = $part->getFunctionCall();
        if ($functionCall !== null) {
            $toolCall = [
                'type' => 'tool-call',
                'toolCallId' => $functionCall->getId() ?? '',
                'toolName' => $functionCall->getName() ?? '',
            ];
            $args = $functionCall->getArgs();
            if ($args !== null) {
                $toolCall['input'] = $args;
            }
            if ($thoughtSignature !== null) {
                $toolCall['providerOptions'] = $this->buildThoughtSignatureProviderOptions($thoughtSignature);
            }
            return $toolCall;
        }

        $functionResponse = $part->getFunctionResponse();
        if ($functionResponse !== null) {
            return [
                'type' => 'tool-result',
                'toolCallId' => $functionResponse->getId() ?? '',
                'toolName' => $functionResponse->getName() ?? '',
                'output' => [
                    'type' => 'json',
                    'value' => $functionResponse->getResponse(),
                ],
            ];
        }

        return null;
    }

    /**
     * Parses the API response into a GenerativeAiResult.
     *
     * @since 1.0.0
     *
     * @param ResponseData $data The response data.
     * @return GenerativeAiResult The parsed result.
     *
     * @throws ResponseException If the response data is invalid.
     */
    private function parseResponse(array $data): GenerativeAiResult
    {
        $parts = $this->parseContentParts($data);
        $finishReason = $this->parseFinishReason($data);
        $tokenUsage = $this->parseTokenUsage($data);

        $message = new Message(MessageRoleEnum::model(), $parts);
        $candidate = new Candidate($message, $finishReason);

        return new GenerativeAiResult(
            '',
            [$candidate],
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata()
        );
    }

    /**
     * Parses the content parts from the response data.
     *
     * @since 1.0.0
     *
     * @param ResponseData $data The response data.
     * @return list<MessagePart> The parsed message parts.
     *
     * @throws ResponseException If no text content is found.
     */
    private function parseContentParts(array $data): array
    {
        if (!isset($data['content'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromMissingData(self::API_NAME, 'content');
        }

        $content = $data['content'];

        if (isset($content['type'])) {
            $content = [$content];
        }

        $parts = [];
        /** @var ResponseContentPart $contentPart */
        foreach ($content as $contentPart) {
            if (!isset($contentPart['type'])) {
                continue;
            }

            $thoughtSignature = $this->extractThoughtSignature($contentPart);

            if ($contentPart['type'] === 'reasoning') {
                $text = $contentPart['text'] ?? '';
                $parts[] = new MessagePart(
                    $text,
                    MessagePartChannelEnum::thought(),
                    $thoughtSignature
                );
            } elseif ($contentPart['type'] === 'text' && isset($contentPart['text'])) {
                $parts[] = new MessagePart(
                    $contentPart['text'],
                    null,
                    $thoughtSignature
                );
            } elseif ($contentPart['type'] === 'file' && isset($contentPart['data'], $contentPart['mediaType'])) {
                $parts[] = new MessagePart(
                    new File($contentPart['data'], $contentPart['mediaType']),
                    null,
                    $thoughtSignature
                );
            } elseif ($contentPart['type'] === 'tool-call') {
                $rawInput = $contentPart['input'] ?? null;
                $parts[] = new MessagePart(
                    new FunctionCall(
                        $contentPart['toolCallId'] ?? null,
                        $contentPart['toolName'] ?? null,
                        is_string($rawInput) ? json_decode($rawInput, true) : $rawInput
                    ),
                    null,
                    $thoughtSignature
                );
            }
        }

        if (count($parts) === 0) {
            throw ResponseException::fromInvalidData(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                self::API_NAME,
                'content',
                'No supported content found in response.'
            );
        }

        return $parts;
    }

    /**
     * Builds the correct providerMetadata shape for a thought signature based on the current provider.
     *
     * @since n.e.x.t
     *
     * @param string $thoughtSignature The thought signature value.
     * @return array<string, array<string, string>> The providerMetadata array.
     */
    private function buildThoughtSignatureProviderOptions(string $thoughtSignature): array
    {
        $providerName = $this->getProviderName();

        switch ($providerName) {
            case 'google':
                return [$providerName => ['thoughtSignature' => $thoughtSignature]];

            case 'anthropic':
                return [$providerName => ['signature' => $thoughtSignature]];

            case 'openai':
            case 'xai':
                return [$providerName => ['reasoningEncryptedContent' => $thoughtSignature]];

            default:
                // TODO: Support other providers' thought signatures. This is an assumption that won't always hold.
                return [$providerName => ['signature' => $thoughtSignature]];
        }
    }

    /**
     * Extracts the thought signature from a content part's provider metadata.
     *
     * @since n.e.x.t
     *
     * @param ResponseContentPart $contentPart The content part from the response.
     * @return string|null The thought signature, or null if not present.
     */
    private function extractThoughtSignature(array $contentPart): ?string
    {
        if (!isset($contentPart['providerMetadata']) || !is_array($contentPart['providerMetadata'])) {
            return null;
        }

        $metadata = $contentPart['providerMetadata'];

        $providerName = $this->getProviderName();
        $providerData = $metadata[$providerName] ?? null;

        switch ($providerName) {
            case 'google':
                /*
                 * The AI Gateway may route "google" requests through "vertex",
                 * so the response metadata can appear under either key.
                 */
                if (!is_array($providerData) || !isset($providerData['thoughtSignature'])) {
                    $vertexData = $metadata['vertex'] ?? null;
                    if (is_array($vertexData)) {
                        $providerData = $vertexData;
                    }
                }
                if (!is_array($providerData)) {
                    return null;
                }
                return isset($providerData['thoughtSignature']) && is_string($providerData['thoughtSignature'])
                    ? $providerData['thoughtSignature']
                    : null;

            case 'anthropic':
                if (!is_array($providerData)) {
                    return null;
                }
                return isset($providerData['signature']) && is_string($providerData['signature'])
                    ? $providerData['signature']
                    : null;

            case 'openai':
            case 'xai':
                if (!is_array($providerData)) {
                    return null;
                }
                $key = 'reasoningEncryptedContent';
                return isset($providerData[$key]) && is_string($providerData[$key])
                    ? $providerData[$key]
                    : null;

            default:
                if (!is_array($providerData)) {
                    return null;
                }
                return isset($providerData['signature']) && is_string($providerData['signature'])
                    ? $providerData['signature']
                    : null;
        }
    }

    /**
     * Parses the finish reason from the response data.
     *
     * @since 1.0.0
     *
     * @param ResponseData $data The response data.
     * @return FinishReasonEnum The parsed finish reason.
     *
     * @throws ResponseException If the finish reason is unknown.
     */
    private function parseFinishReason(array $data): FinishReasonEnum
    {
        if (!isset($data['finishReason'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromMissingData(self::API_NAME, 'finishReason');
        }

        $finishReason = $data['finishReason'];

        $reason = is_array($finishReason) ? ($finishReason['unified'] ?? null) : null;
        if ($reason === null || !isset(self::FINISH_REASON_MAP[$reason])) {
            throw ResponseException::fromInvalidData(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                self::API_NAME,
                'finishReason',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                sprintf('Unknown finish reason "%s".', is_string($reason) ? $reason : json_encode($finishReason))
            );
        }

        return FinishReasonEnum::from(self::FINISH_REASON_MAP[$reason]);
    }

    /**
     * Parses the token usage from the response data.
     *
     * @since 1.0.0
     *
     * @param ResponseData $data The response data.
     * @return TokenUsage The parsed token usage.
     */
    private function parseTokenUsage(array $data): TokenUsage
    {
        $promptTokens = 0;
        $completionTokens = 0;
        $thoughtTokens = null;

        if (isset($data['usage']) && is_array($data['usage'])) {
            $usage = $data['usage'];

            $inputTokens = $usage['inputTokens'] ?? null;
            if (is_array($inputTokens) && isset($inputTokens['total']) && is_numeric($inputTokens['total'])) {
                $promptTokens = (int) $inputTokens['total'];
            } elseif (isset($usage['promptTokens']) && is_numeric($usage['promptTokens'])) {
                $promptTokens = (int) $usage['promptTokens'];
            }

            $outputTokens = $usage['outputTokens'] ?? null;
            if (is_array($outputTokens) && isset($outputTokens['total']) && is_numeric($outputTokens['total'])) {
                $completionTokens = (int) $outputTokens['total'];
                if (isset($outputTokens['reasoning']) && is_numeric($outputTokens['reasoning'])) {
                    $thoughtTokens = (int) $outputTokens['reasoning'];
                }
            } elseif (isset($usage['completionTokens']) && is_numeric($usage['completionTokens'])) {
                $completionTokens = (int) $usage['completionTokens'];
            }
        }

        return new TokenUsage(
            $promptTokens,
            $completionTokens,
            $promptTokens + $completionTokens,
            $thoughtTokens
        );
    }
}
