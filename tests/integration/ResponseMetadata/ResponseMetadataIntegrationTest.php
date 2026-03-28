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

    public function testTextGenerationResponseMetadata(): void
    {
        $result = AiClient::prompt('Say "hello".')
            ->usingModel(AiGatewayProvider::model('claude-haiku-4.5'))
            ->generateTextResult();

        $this->assertNotEmpty($result->getId(), 'Text generation result should have a non-empty response ID.');

        $candidates = $result->getCandidates();
        $this->assertCount(1, $candidates);
        $this->assertTrue($candidates[0]->getFinishReason()->isStop());

        $additionalData = $result->getAdditionalData();
        $this->assertNotEmpty($additionalData, 'Additional data should not be empty.');
        $this->assertIsArray($additionalData);
        $this->assertArrayHasKey('providerMetadata', $additionalData);
        $this->assertIsArray($additionalData['providerMetadata']);

        $providerMetadata = $additionalData['providerMetadata'];
        $this->assertArrayHasKey('gateway', $providerMetadata);
        $this->assertIsArray($providerMetadata['gateway']);
        $this->assertArrayHasKey('anthropic', $providerMetadata);
        $this->assertIsArray($providerMetadata['anthropic']);
    }

    public function testImageGenerationResponseMetadata(): void
    {
        $result = AiClient::prompt('A red circle on a white background.')
            ->usingModel(AiGatewayProvider::model('gpt-image-1'))
            ->generateImageResult();

        $this->assertNotEmpty($result->getId(), 'Image generation result should have a non-empty response ID.');

        $candidates = $result->getCandidates();
        $this->assertCount(1, $candidates);
        $this->assertTrue($candidates[0]->getFinishReason()->isStop());

        $additionalData = $result->getAdditionalData();
        $this->assertNotEmpty($additionalData, 'Additional data should not be empty.');
        $this->assertIsArray($additionalData);
        $this->assertArrayHasKey('providerMetadata', $additionalData);
        $this->assertIsArray($additionalData['providerMetadata']);

        $providerMetadata = $additionalData['providerMetadata'];
        $this->assertArrayHasKey('gateway', $providerMetadata);
        $this->assertIsArray($providerMetadata['gateway']);
        $this->assertArrayHasKey('openai', $providerMetadata);
        $this->assertIsArray($providerMetadata['openai']);
    }
}
