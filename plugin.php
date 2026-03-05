<?php

/**
 * Plugin Name: AI Gateway Provider
 * Plugin URI: https://github.com/vercel-labs/ai-gateway-provider-php
 * Description: Vercel AI Gateway provider for the PHP AI Client SDK. Works as a Composer package and WordPress plugin.
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Version: 1.0.0
 * Author: Vercel
 * Author URI: https://vercel.com
 * License: MIT
 * License URI: https://opensource.org/license/mit
 * Text Domain: ai-gateway-provider
 *
 * @package Vercel\AiGatewayProvider
 */

declare(strict_types=1);

namespace Vercel\AiGatewayProvider;

use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;
use WordPress\AiClient\AiClient;

if (!defined('ABSPATH')) {
    return;
}

require_once __DIR__ . '/src/autoload.php';

/**
 * Registers the AI Gateway Provider with the AI Client.
 *
 * @since 1.0.0
 *
 * @return void
 */
function register_provider(): void
{
    if (!class_exists(AiClient::class)) {
        return;
    }

    $registry = AiClient::defaultRegistry();

    if ($registry->hasProvider(AiGatewayProvider::class)) {
        return;
    }

    $registry->registerProvider(AiGatewayProvider::class);
}

add_action('init', __NAMESPACE__ . '\\register_provider', 5);
