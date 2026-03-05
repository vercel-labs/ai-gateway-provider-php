<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Unit\Authentication;

use PHPUnit\Framework\TestCase;
use Vercel\AiGatewayProvider\Authentication\AiGatewayRequestAuthentication;
use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;

class AiGatewayRequestAuthenticationTest extends TestCase
{
    public function testAuthenticateRequestAddsAuthorizationBearerHeader(): void
    {
        $auth = new AiGatewayRequestAuthentication('test-api-key');
        $request = new Request(HttpMethodEnum::POST(), 'https://example.com/api');

        $result = $auth->authenticateRequest($request);

        $this->assertSame(
            'Bearer test-api-key',
            $result->getHeaderAsString('Authorization')
        );
    }

    public function testAuthenticateRequestAddsProtocolVersionHeader(): void
    {
        $auth = new AiGatewayRequestAuthentication('test-api-key');
        $request = new Request(HttpMethodEnum::POST(), 'https://example.com/api');

        $result = $auth->authenticateRequest($request);

        $this->assertSame(
            '0.0.1',
            $result->getHeaderAsString('ai-gateway-protocol-version')
        );
    }

    public function testAuthenticateRequestAddsAuthMethodHeader(): void
    {
        $auth = new AiGatewayRequestAuthentication('test-api-key');
        $request = new Request(HttpMethodEnum::POST(), 'https://example.com/api');

        $result = $auth->authenticateRequest($request);

        $this->assertSame(
            'api-key',
            $result->getHeaderAsString('ai-gateway-auth-method')
        );
    }

    public function testAuthenticateRequestAddsUserAgentHeader(): void
    {
        $auth = new AiGatewayRequestAuthentication('test-api-key');
        $request = new Request(HttpMethodEnum::POST(), 'https://example.com/api');

        $result = $auth->authenticateRequest($request);

        $this->assertStringContainsString(
            'ai-gateway-provider-php/' . AiGatewayProvider::VERSION,
            $result->getHeaderAsString('User-Agent')
        );
    }
}
