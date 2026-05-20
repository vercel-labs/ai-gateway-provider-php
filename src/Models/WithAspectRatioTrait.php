<?php

/**
 * Trait Vercel\AiGatewayProvider\Models\WithAspectRatioTrait
 *
 * @since 1.0.0
 *
 * @package Vercel\AiGatewayProvider
 */

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Models;

use WordPress\AiClient\Providers\Models\DTO\ModelConfig;

/**
 * Trait for resolving aspect ratio from model config.
 *
 * @since 1.0.0
 */
trait WithAspectRatioTrait
{
    /**
     * Resolves the aspect ratio from the config.
     *
     * Explicit aspect ratio takes priority over orientation mapping.
     *
     * @since 1.0.0
     *
     * @param ModelConfig $config The model config.
     * @return string|null The aspect ratio string, or null if not set.
     */
    private function resolveAspectRatio(ModelConfig $config): ?string
    {
        $aspectRatio = $config->getOutputMediaAspectRatio();
        if ($aspectRatio !== null) {
            return $aspectRatio;
        }

        $orientation = $config->getOutputMediaOrientation();
        if ($orientation === null) {
            return null;
        }

        if ($orientation->isLandscape()) {
            return '16:9';
        }
        if ($orientation->isPortrait()) {
            return '9:16';
        }
        return '1:1';
    }
}
