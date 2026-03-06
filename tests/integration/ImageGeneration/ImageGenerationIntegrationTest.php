<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Integration\ImageGeneration;

use PHPUnit\Framework\TestCase;
use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;
use Vercel\AiGatewayProvider\Tests\Traits\IntegrationTestTrait;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Results\DTO\TokenUsage;

/**
 * @group integration
 * @group image-generation
 * @coversNothing
 */
class ImageGenerationIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireApiKey('AI_GATEWAY_API_KEY');
    }

    /**
     * @return array<string, array{string}>
     */
    public function provideModels(): array
    {
        return [
            'Google' => ['imagen-4.0-generate-001'],
            'OpenAI' => ['gpt-image-1'],
            'xAI'    => ['grok-imagine-image'],
        ];
    }

    protected function assertTokenUsage(string $modelId, TokenUsage $tokenUsage): void
    {
        if (str_starts_with($modelId, 'imagen-') || str_starts_with($modelId, 'grok-')) {
            // Google's Imagen models and xAI's Grok Imagine Image model don't return token usage data.
            $message = 'Google and xAI do not return token usage data for image generation. Maybe that just changed?';
            $this->assertSame(0, $tokenUsage->getPromptTokens(), $message);
            $this->assertSame(0, $tokenUsage->getCompletionTokens(), $message);
        } else {
            $this->assertGreaterThan(0, $tokenUsage->getPromptTokens());
            $this->assertGreaterThan(0, $tokenUsage->getCompletionTokens());
        }
    }

    /**
     * @dataProvider provideModels
     */
    public function testBasicImageGeneration(string $modelId): void
    {
        $result = AiClient::prompt('A red circle on a white background.')
            ->usingModel(AiGatewayProvider::model($modelId))
            ->generateImageResult();

        $candidates = $result->getCandidates();
        $this->assertNotEmpty($candidates);

        $parts = $candidates[0]->getMessage()->getParts();
        $this->assertNotEmpty($parts);

        $file = $parts[0]->getFile();
        $this->assertNotNull($file);
        $this->assertSame('image/png', $file->getMimeType());

        $this->saveGeneratedFile($file, "basic-{$modelId}");

        $this->assertTokenUsage($modelId, $result->getTokenUsage());
    }

    /**
     * @dataProvider provideModels
     */
    public function testGenerationWithOptions(string $modelId): void
    {
        $config = ModelConfig::fromArray([
            'outputMediaAspectRatio' => '1:1',
        ]);

        $result = AiClient::prompt('A blue square on a white background.')
            ->usingModel(AiGatewayProvider::model($modelId))
            ->usingModelConfig($config)
            ->usingCandidateCount(2)
            ->generateImageResult();

        $candidates = $result->getCandidates();
        $this->assertCount(2, $candidates);

        foreach ($candidates as $index => $candidate) {
            $parts = $candidate->getMessage()->getParts();
            $this->assertNotEmpty($parts);

            $file = $parts[0]->getFile();
            $this->assertNotNull($file);
            $this->assertSame('image/png', $file->getMimeType());

            $this->saveGeneratedFile($file, "options-{$modelId}-{$index}");
        }

        $this->assertTokenUsage($modelId, $result->getTokenUsage());
    }
}
