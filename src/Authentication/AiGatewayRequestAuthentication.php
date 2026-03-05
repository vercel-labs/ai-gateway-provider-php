<?php

/**
 * Class Vercel\AiGatewayProvider\Authentication\AiGatewayRequestAuthentication
 *
 * @since 1.0.0
 *
 * @package Vercel\AiGatewayProvider
 */

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Authentication;

use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;

/**
 * Class for HTTP request authentication for the Vercel AI Gateway.
 *
 * Extends the default API key authentication to include the AI Gateway
 * protocol version and authentication method headers.
 *
 * @since 1.0.0
 */
class AiGatewayRequestAuthentication extends ApiKeyRequestAuthentication
{
    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    public function authenticateRequest(Request $request): Request
    {
        $request = parent::authenticateRequest($request);
        $request = $request->withHeader('ai-gateway-protocol-version', '0.0.1');
        $request = $request->withHeader('ai-gateway-auth-method', 'api-key');

        // Consider whether this is running in a WordPress environment as part of the User-Agent header.
        $sdkIdentifier = function_exists('wp_get_wp_version')
            ? 'wp/ai-gateway-provider-php'
            : 'ai-gateway-provider-php';
        $request = $request->withHeader(
            'User-Agent',
            $sdkIdentifier . '/' . AiGatewayProvider::VERSION
        );

        return $request;
    }
}
