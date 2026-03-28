<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Integration\ResponseMetadata;

use PHPUnit\Framework\TestCase;
use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;
use Vercel\AiGatewayProvider\Tests\Traits\IntegrationTestTrait;
use WordPress\AiClient\AiClient;

/**
 * @group integration
 * @group response-metadata
 * @coversNothing
 */
class ResponseMetadataIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireApiKey('AI_GATEWAY_API_KEY');
    }

    public function testTextGenerationResultHasResponseId(): void
    {
        $result = AiClient::prompt('Say "hello".')
            ->usingModel(AiGatewayProvider::model('gemini-3.1-flash-lite-preview'))
            ->generateTextResult();

        $this->assertNotEmpty($result->getId(), 'Text generation result should have a non-empty response ID.');
    }

    public function testImageGenerationResultHasResponseId(): void
    {
        $result = AiClient::prompt('A red circle on a white background.')
            ->usingModel(AiGatewayProvider::model('gpt-image-1'))
            ->generateImageResult();

        $this->assertNotEmpty($result->getId(), 'Image generation result should have a non-empty response ID.');
    }
}
