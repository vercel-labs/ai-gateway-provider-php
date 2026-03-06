<?php

/**
 * Class Vercel\AiGatewayProvider\Provider\AiGatewayProvider
 *
 * @since 1.0.0
 *
 * @package Vercel\AiGatewayProvider
 */

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Provider;

use Vercel\AiGatewayProvider\Metadata\AiGatewayModelMetadataDirectory;
use Vercel\AiGatewayProvider\Models\AiGatewayImageGenerationModel;
use Vercel\AiGatewayProvider\Models\AiGatewayTextGenerationModel;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Class for the Vercel AI Gateway provider.
 *
 * @since 1.0.0
 */
class AiGatewayProvider extends AbstractApiProvider
{
    public const VERSION = '1.0.0-alpha';

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function baseUrl(): string
    {
        return 'https://ai-gateway.vercel.sh/v3/ai';
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        /** @var AiGatewayModelMetadataDirectory $directory */
        $directory = static::modelMetadataDirectory();
        $gatewayModelId = $directory->getGatewayModelId($modelMetadata->getId());

        foreach ($modelMetadata->getSupportedCapabilities() as $capability) {
            if ($capability->isImageGeneration()) {
                return new AiGatewayImageGenerationModel(
                    $modelMetadata,
                    $providerMetadata,
                    $gatewayModelId
                );
            }
        }

        return new AiGatewayTextGenerationModel(
            $modelMetadata,
            $providerMetadata,
            $gatewayModelId
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $args = [
            'ai_gateway',
            'AI Gateway',
            ProviderTypeEnum::cloud(),
            null,
            RequestAuthenticationMethod::apiKey(),
        ];
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            $args[] = function_exists('__')
                // phpcs:ignore Generic.Files.LineLength
                ? __('Generate and edit text, images, and more with over 100 AI models from over 20 providers.', 'ai-gateway-provider')
                : 'Generate and edit text, images, and more with over 100 AI models from over 20 providers.';
        }
        return new ProviderMetadata(...$args);
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        return new ListModelsApiBasedProviderAvailability(static::modelMetadataDirectory());
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new AiGatewayModelMetadataDirectory();
    }
}
