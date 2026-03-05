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
            $args[] = 'Text generation with any AI model via the Vercel AI Gateway.';
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
