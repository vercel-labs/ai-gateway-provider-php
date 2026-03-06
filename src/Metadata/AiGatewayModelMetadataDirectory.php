<?php

/**
 * Class Vercel\AiGatewayProvider\Metadata\AiGatewayModelMetadataDirectory
 *
 * @since 1.0.0
 *
 * @package Vercel\AiGatewayProvider
 */

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Metadata;

use Vercel\AiGatewayProvider\Authentication\AiGatewayRequestAuthentication;
use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Class for the model metadata directory for the Vercel AI Gateway.
 *
 * @since 1.0.0
 *
 * @phpstan-type ConfigModelData array{
 *     id: string,
 *     name: string,
 *     modelType: string,
 *     specification: array{modelId: string}
 * }
 * @phpstan-type ConfigResponseData array{
 *     models: list<ConfigModelData>
 * }
 */
class AiGatewayModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory
{
    /**
     * Map of flat model IDs to full gateway model IDs.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    private array $gatewayModelIdMap = [];

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
     * Gets the full gateway model ID for a given flat model ID.
     *
     * @since 1.0.0
     *
     * @param string $modelId The flat model ID (e.g. "claude-sonnet-4-6").
     * @return string The full gateway model ID (e.g. "anthropic/claude-sonnet-4-6").
     *
     * @throws InvalidArgumentException If the model ID is not found.
     */
    public function getGatewayModelId(string $modelId): string
    {
        if (!isset($this->gatewayModelIdMap[$modelId])) {
            throw new InvalidArgumentException(
                sprintf('No gateway model ID found for model "%s".', $modelId)
            );
        }
        return $this->gatewayModelIdMap[$modelId];
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function sendListModelsRequest(): array
    {
        $request = new Request(
            HttpMethodEnum::GET(),
            AiGatewayProvider::url('config')
        );
        $request = $this->getRequestAuthentication()->authenticateRequest($request);

        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        /** @var ConfigResponseData|null $data */
        $data = $response->getData();
        if ($data === null || !isset($data['models'])) {
            return [];
        }

        $textOptions = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::topK()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::presencePenalty()),
            new SupportedOption(OptionEnum::frequencyPenalty()),
            new SupportedOption(OptionEnum::functionDeclarations()),
            new SupportedOption(OptionEnum::outputMimeType()),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::customOptions()),
            new SupportedOption(
                OptionEnum::inputModalities(),
                [
                    [ModalityEnum::text()],
                    [ModalityEnum::text(), ModalityEnum::image()],
                    [ModalityEnum::text(), ModalityEnum::audio()],
                    [ModalityEnum::text(), ModalityEnum::document()],
                    [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::audio()],
                    [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::document()],
                    [ModalityEnum::text(), ModalityEnum::audio(), ModalityEnum::document()],
                    [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::audio(), ModalityEnum::document()],
                ]
            ),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ];

        $imageOptions = [
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::outputFileType(), [[FileTypeEnum::inline()]]),
            new SupportedOption(OptionEnum::outputMediaOrientation()),
            new SupportedOption(OptionEnum::outputMediaAspectRatio()),
            new SupportedOption(OptionEnum::customOptions()),
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
        ];

        $textAndImageOptions = array_merge(
            array_filter(
                $textOptions,
                static function (SupportedOption $option): bool {
                    return $option->getName() !== OptionEnum::outputModalities();
                }
            ),
            [
                new SupportedOption(OptionEnum::outputFileType(), [[FileTypeEnum::inline()]]),
                new SupportedOption(OptionEnum::outputMediaOrientation()),
                new SupportedOption(OptionEnum::outputMediaAspectRatio()),
                new SupportedOption(OptionEnum::outputModalities(), [
                    [ModalityEnum::text()],
                    [ModalityEnum::image()],
                    [ModalityEnum::image(), ModalityEnum::text()],
                ]),
            ]
        );

        $modelsMetadata = [];
        foreach ($data['models'] as $model) {
            if (!isset($model['modelType'])) {
                continue;
            }

            if (!isset($model['specification']['modelId'])) {
                continue;
            }

            $specModelId = $model['specification']['modelId'];
            $gatewayId = $model['id'] ?? $specModelId;

            $slashPos = strpos($specModelId, '/');
            $flatId = $slashPos !== false ? substr($specModelId, $slashPos + 1) : $specModelId;

            $name = $model['name'] ?? $flatId;

            $modelType = $model['modelType'];
            switch ($modelType) {
                case 'language':
                    if (
                        str_starts_with($flatId, 'gemini-')
                        && (str_contains($flatId, '-image-') || str_ends_with($flatId, '-image'))
                    ) {
                        $capabilities = [CapabilityEnum::textGeneration(), CapabilityEnum::imageGeneration()];
                        $options = $textAndImageOptions;
                    } else {
                        $capabilities = [CapabilityEnum::textGeneration()];
                        $options = $textOptions;
                    }
                    break;
                case 'image':
                    $capabilities = [CapabilityEnum::imageGeneration()];
                    $options = $imageOptions;
                    break;
                default:
                    continue 2;
            }

            $this->gatewayModelIdMap[$flatId] = $gatewayId;

            $modelsMetadata[] = new ModelMetadata(
                $flatId,
                $name,
                $capabilities,
                $options
            );
        }

        usort($modelsMetadata, [$this, 'modelSortCallback']);

        $sortedModelsMetadata = [];
        foreach ($modelsMetadata as $modelMetadata) {
            $sortedModelsMetadata[$modelMetadata->getId()] = $modelMetadata;
        }

        return $sortedModelsMetadata;
    }

    /**
     * Sorts models so that popular providers appear first, with alphabetical sorting within each group.
     *
     * @since 1.0.0
     *
     * @param ModelMetadata $a First model to compare.
     * @param ModelMetadata $b Second model to compare.
     * @return int Negative if $a should come first, positive if $b should come first, zero if equal.
     */
    private function modelSortCallback(ModelMetadata $a, ModelMetadata $b): int
    {
        $priorityProviders = ['anthropic', 'openai', 'google', 'xai'];

        $providerA = $this->getProviderForModel($a->getId());
        $providerB = $this->getProviderForModel($b->getId());

        $aIsPriority = in_array($providerA, $priorityProviders, true);
        $bIsPriority = in_array($providerB, $priorityProviders, true);

        if ($aIsPriority && !$bIsPriority) {
            return -1;
        }
        if (!$aIsPriority && $bIsPriority) {
            return 1;
        }

        return strcmp($a->getId(), $b->getId());
    }

    /**
     * Gets the provider prefix for a given flat model ID.
     *
     * @since 1.0.0
     *
     * @param string $flatId The flat model ID.
     * @return string The provider prefix, or empty string if not found.
     */
    private function getProviderForModel(string $flatId): string
    {
        if (!isset($this->gatewayModelIdMap[$flatId])) {
            return '';
        }

        $gatewayId = $this->gatewayModelIdMap[$flatId];
        $slashPos = strpos($gatewayId, '/');
        if ($slashPos === false) {
            return '';
        }

        return substr($gatewayId, 0, $slashPos);
    }
}
