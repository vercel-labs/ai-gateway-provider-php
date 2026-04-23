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
use Vercel\AiGatewayProvider\Models\AiGatewayTextAndImageGenerationModel;
use Vercel\AiGatewayProvider\Models\AiGatewayTextGenerationModel;
use WordPress\AiClient\Common\Exception\RuntimeException;
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
        $capabilities = $modelMetadata->getSupportedCapabilities();

        $hasTextGeneration = false;
        $hasImageGeneration = false;
        foreach ($capabilities as $capability) {
            if ($capability->isTextGeneration()) {
                $hasTextGeneration = true;
            }
            if ($capability->isImageGeneration()) {
                $hasImageGeneration = true;
            }
        }

        if ($hasTextGeneration && $hasImageGeneration) {
            return new AiGatewayTextAndImageGenerationModel(
                $modelMetadata,
                $providerMetadata,
                $gatewayModelId
            );
        }

        if ($hasTextGeneration) {
            return new AiGatewayTextGenerationModel(
                $modelMetadata,
                $providerMetadata,
                $gatewayModelId
            );
        }

        if ($hasImageGeneration) {
            return new AiGatewayImageGenerationModel(
                $modelMetadata,
                $providerMetadata,
                $gatewayModelId
            );
        }

        throw new RuntimeException(
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            'Unsupported model capabilities: ' . implode(', ', $capabilities)
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        if (function_exists('__')) {
            /** @var string $description */
            $description = __('Access hundreds of text, image, and video AI models through 1 API key. Get unified billing, observability, and no markup from list price.', 'vercel-ai-gateway-provider'); // phpcs:ignore Generic.Files.LineLength
        } else {
            $description = 'Access hundreds of text, image, and video AI models through 1 API key. Get unified billing, observability, and no markup from list price.'; // phpcs:ignore Generic.Files.LineLength
        }

        // In case we're in WordPress context and the plugin is symlinked, try the symlinked path.
        $logoPath = dirname(__DIR__, 2) . '/assets/vercel-logo.png';
        if (
            defined('WP_PLUGIN_DIR') &&
            file_exists(WP_PLUGIN_DIR . '/vercel-ai-gateway-provider/assets/vercel-logo.png')
        ) {
            $logoPath = WP_PLUGIN_DIR . '/vercel-ai-gateway-provider/assets/vercel-logo.png';
        }

        return new ProviderMetadata(
            'ai_gateway',
            'Vercel AI Gateway',
            ProviderTypeEnum::cloud(),
            // phpcs:ignore Generic.Files.LineLength
            'https://vercel.com/d?to=%2F%5Bteam%5D%2F%7E%2Fai%2Fapi-keys&title=Get%20AI%20Gateway%20API%20key%20for%20your%20WordPress%20site',
            RequestAuthenticationMethod::apiKey(),
            $description,
            $logoPath
        );
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
