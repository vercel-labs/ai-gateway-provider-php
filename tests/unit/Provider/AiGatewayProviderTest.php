<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;

class AiGatewayProviderTest extends TestCase
{
    public function testBaseUrl(): void
    {
        $this->assertSame(
            'https://ai-gateway.vercel.sh/v3/ai',
            AiGatewayProvider::url()
        );
    }

    public function testProviderMetadata(): void
    {
        $metadata = AiGatewayProvider::metadata();

        $this->assertSame('ai_gateway', $metadata->getId());
        $this->assertSame('Vercel AI Gateway', $metadata->getName());
        $this->assertTrue($metadata->getType()->is(ProviderTypeEnum::cloud()));
        $this->assertTrue($metadata->getAuthenticationMethod()->is(RequestAuthenticationMethod::apiKey()));
        $this->assertNotNull($metadata->getDescription());
        $this->assertStringContainsString('AI models', $metadata->getDescription());
    }

    public function testProviderMetadataLogoPath(): void
    {
        $metadata = AiGatewayProvider::metadata();

        $logoPath = $metadata->getLogoPath();
        $this->assertNotNull($logoPath);
        $this->assertStringEndsWith('/assets/vercel-logo.png', $logoPath);
    }
}
