<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Integration\TextAndImageGeneration;

use PHPUnit\Framework\TestCase;
use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;
use Vercel\AiGatewayProvider\Tests\Traits\IntegrationTestTrait;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Results\DTO\TokenUsage;

/**
 * @group integration
 * @group text-and-image-generation
 * @coversNothing
 */
class TextAndImageGenerationIntegrationTest extends TestCase
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
            'Google' => ['gemini-3.1-flash-image-preview'],
        ];
    }

    protected function assertTokenUsage(string $modelId, TokenUsage $tokenUsage): void
    {
        $this->assertGreaterThan(0, $tokenUsage->getPromptTokens());
        $this->assertGreaterThan(0, $tokenUsage->getCompletionTokens());
    }

    /**
     * @dataProvider provideModels
     */
    public function testTextOnlyOutput(string $modelId): void
    {
        $result = AiClient::prompt('What is the capital of France? Respond with only the city name.')
            ->usingModel(AiGatewayProvider::model($modelId))
            ->asOutputModalities(ModalityEnum::text())
            ->generateTextResult();

        $candidates = $result->getCandidates();
        $this->assertCount(1, $candidates);

        $text = $candidates[0]->getMessage()->getParts()[0]->getText();
        $this->assertNotNull($text);
        $this->assertStringContainsStringIgnoringCase('Paris', $text);

        $this->assertTokenUsage($modelId, $result->getTokenUsage());
    }

    /**
     * @dataProvider provideModels
     */
    public function testImageOnlyOutput(string $modelId): void
    {
        $result = AiClient::prompt('Generate an image of a red circle on a white background.')
            ->usingModel(AiGatewayProvider::model($modelId))
            ->asOutputModalities(ModalityEnum::image())
            ->generateTextResult();

        $candidates = $result->getCandidates();
        $this->assertCount(1, $candidates);

        $parts = $candidates[0]->getMessage()->getParts();
        $this->assertNotEmpty($parts);

        $file = $parts[0]->getFile();
        $this->assertNotNull($file);
        $this->assertStringStartsWith('image/', $file->getMimeType());

        $this->saveGeneratedFile($file, "image-only-{$modelId}");

        $this->assertTokenUsage($modelId, $result->getTokenUsage());
    }

    /**
     * @dataProvider provideModels
     */
    public function testImageOnlyOutputWithOptions(string $modelId): void
    {
        $result = AiClient::prompt('Generate an image of a red circle on a white background.')
            ->usingModel(AiGatewayProvider::model($modelId))
            ->asOutputMediaAspectRatio('4:1')
            ->asOutputModalities(ModalityEnum::image())
            ->generateTextResult();

        $candidates = $result->getCandidates();
        $this->assertCount(1, $candidates);

        $parts = $candidates[0]->getMessage()->getParts();
        $this->assertNotEmpty($parts);

        $file = $parts[0]->getFile();
        $this->assertNotNull($file);
        $this->assertStringStartsWith('image/', $file->getMimeType());

        $this->saveGeneratedFile($file, "image-only-options-{$modelId}");

        $this->assertTokenUsage($modelId, $result->getTokenUsage());
    }

    /**
     * @dataProvider provideModels
     */
    public function testMixedTextAndImageOutput(string $modelId): void
    {
        $result = AiClient::prompt('Draw a simple blue square and describe what you drew.')
            ->usingModel(AiGatewayProvider::model($modelId))
            ->asOutputModalities(ModalityEnum::text(), ModalityEnum::image())
            ->generateTextResult();

        $candidates = $result->getCandidates();
        $this->assertCount(1, $candidates);

        $parts = $candidates[0]->getMessage()->getParts();
        $this->assertGreaterThanOrEqual(2, count($parts));

        $hasText = false;
        $hasImage = false;
        foreach ($parts as $part) {
            if ($part->getText() !== null) {
                $hasText = true;
            }
            if ($part->getFile() !== null) {
                $hasImage = true;
                $this->assertStringStartsWith('image/', $part->getFile()->getMimeType());
                $this->saveGeneratedFile($part->getFile(), "mixed-{$modelId}");
            }
        }

        $this->assertTrue($hasText, 'Response should contain at least one text part.');
        $this->assertTrue($hasImage, 'Response should contain at least one image part.');

        $this->assertTokenUsage($modelId, $result->getTokenUsage());
    }

    /**
     * @dataProvider provideModels
     */
    public function testMultiTurnImageEditing(string $modelId): void
    {
        $model = AiGatewayProvider::model($modelId);

        $turn1Message = new Message(
            MessageRoleEnum::user(),
            [new MessagePart('Generate an image of a red circle on a white background.')]
        );

        $turn1Result = AiClient::prompt($turn1Message)
            ->usingModel($model)
            ->asOutputModalities(ModalityEnum::text(), ModalityEnum::image())
            ->generateTextResult();

        $turn1Response = $turn1Result->toMessage();

        $turn1File = null;
        foreach ($turn1Response->getParts() as $part) {
            if ($part->getFile() !== null) {
                $turn1File = $part->getFile();
                break;
            }
        }
        $this->assertNotNull($turn1File, 'First turn should contain an image.');
        $this->assertStringStartsWith('image/', $turn1File->getMimeType());
        $this->saveGeneratedFile($turn1File, "multi-turn-1-{$modelId}");

        $turn2Result = AiClient::prompt()
            ->usingModel($model)
            ->withHistory($turn1Message, $turn1Response)
            ->withText('Change the circle color from red to blue. Keep everything else the same.')
            ->asOutputModalities(ModalityEnum::text(), ModalityEnum::image())
            ->generateTextResult();

        $turn2File = null;
        foreach ($turn2Result->getCandidates()[0]->getMessage()->getParts() as $part) {
            if ($part->getFile() !== null) {
                $turn2File = $part->getFile();
                break;
            }
        }
        $this->assertNotNull($turn2File, 'Second turn should contain an image.');
        $this->assertStringStartsWith('image/', $turn2File->getMimeType());
        $this->saveGeneratedFile($turn2File, "multi-turn-2-{$modelId}");

        $this->assertTokenUsage($modelId, $turn2Result->getTokenUsage());
    }
}
