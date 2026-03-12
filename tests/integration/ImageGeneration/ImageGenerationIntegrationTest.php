<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Integration\ImageGeneration;

use PHPUnit\Framework\TestCase;
use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;
use Vercel\AiGatewayProvider\Tests\Traits\IntegrationTestTrait;
use WordPress\AiClient\AiClient;
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
            'Google (Imagen)' => ['imagen-4.0-generate-001'],
            'Google (Gemini)' => ['gemini-3.1-flash-image-preview'],
            'OpenAI (GPT)' => ['gpt-image-1'],
            'xAI (Grok)' => ['grok-imagine-image'],
        ];
    }

    protected function assertTokenUsage(string $modelId, TokenUsage $tokenUsage): void
    {
        if (str_starts_with($modelId, 'imagen-') || str_starts_with($modelId, 'grok-')) {
            $message = 'Google (Imagen) and xAI (Grok) do not return token usage data for image generation.';
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
        $this->assertCount(1, $candidates);

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
        $isGemini = str_starts_with($modelId, 'gemini-');
        $candidateCount = $isGemini ? 1 : 2;

        $result = AiClient::prompt('A blue square on a white background.')
            ->usingModel(AiGatewayProvider::model($modelId))
            ->asOutputMediaAspectRatio('1:1')
            ->usingCandidateCount($candidateCount)
            ->generateImageResult();

        $candidates = $result->getCandidates();
        $this->assertCount($candidateCount, $candidates);

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
