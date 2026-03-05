<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Integration\TextGeneration;

use PHPUnit\Framework\TestCase;
use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;
use Vercel\AiGatewayProvider\Tests\Traits\IntegrationTestTrait;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;

/**
 * @group integration
 * @coversNothing
 */
class TextGenerationIntegrationTest extends TestCase
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
            'Anthropic' => ['claude-haiku-4.5'],
            'Google'    => ['gemini-3.1-flash-lite-preview'],
            'OpenAI'    => ['gpt-5-mini'],
            'xAI'       => ['grok-4.1-fast-non-reasoning'],
        ];
    }

    /**
     * @dataProvider provideModels
     */
    public function testSimpleTextGeneration(string $modelId): void
    {
        $result = AiClient::prompt('What is the capital of France? Respond with only the city name.')
            ->usingModel(AiGatewayProvider::model($modelId))
            ->generateTextResult();

        $candidates = $result->getCandidates();
        $this->assertNotEmpty($candidates);

        $text = $candidates[0]->getMessage()->getParts()[0]->getText();
        $this->assertNotNull($text);
        $this->assertNotEmpty($text);
        $this->assertStringContainsStringIgnoringCase('Paris', $text);

        $tokenUsage = $result->getTokenUsage();
        $this->assertGreaterThan(0, $tokenUsage->getPromptTokens());
        $this->assertGreaterThan(0, $tokenUsage->getCompletionTokens());
    }

    /**
     * @dataProvider provideModels
     */
    public function testGenerationWithOptions(string $modelId): void
    {
        $result = AiClient::prompt('Write a single short sentence about the color blue.')
            ->usingModel(AiGatewayProvider::model($modelId))
            ->usingTemperature(0.0)
            ->usingMaxTokens(50)
            ->generateTextResult();

        $this->assertCount(1, $result->getCandidates());
        $this->assertNotEmpty($result->toText());

        $tokenUsage = $result->getTokenUsage();
        $this->assertGreaterThan(0, $tokenUsage->getPromptTokens());
        $this->assertGreaterThan(0, $tokenUsage->getCompletionTokens());
    }

    /**
     * @dataProvider provideModels
     */
    public function testImageBasedGeneration(string $modelId): void
    {
        $imagePath = dirname(__DIR__, 2) . '/data/cavalier-king-charles-spaniel.jpg';

        $result = AiClient::prompt()
            ->usingModel(AiGatewayProvider::model($modelId))
            ->withFile($imagePath)
            ->withText('What is the breed of the dog shown in this image?')
            ->generateTextResult();

        $this->assertCount(1, $result->getCandidates());

        $text = $result->toText();
        $this->assertNotNull($text);
        $this->assertNotEmpty($text);
        $this->assertStringContainsStringIgnoringCase('Spaniel', $text);
    }

    /**
     * @dataProvider provideModels
     */
    public function testMultiTurnConversation(string $modelId): void
    {
        $model = AiGatewayProvider::model($modelId);

        $initialMessage = new Message(
            MessageRoleEnum::user(),
            [new MessagePart('Remember this number: 42. Respond with "OK, I will remember 42."')]
        );

        $turn1Result = AiClient::prompt($initialMessage)
            ->usingModel($model)
            ->generateTextResult();

        $turn1Response = $turn1Result->toMessage();

        $secondMessage = new Message(
            MessageRoleEnum::user(),
            [new MessagePart('What is the square root of 144? Respond with only the number.')]
        );

        $turn2Result = AiClient::prompt()
            ->usingModel($model)
            ->withHistory(
                $initialMessage,
                $turn1Response
            )
            ->withText($secondMessage->getParts()[0]->getText())
            ->generateTextResult();

        $turn2Response = $turn2Result->toMessage();

        $turn3Result = AiClient::prompt()
            ->usingModel($model)
            ->withHistory(
                $initialMessage,
                $turn1Response,
                $secondMessage,
                $turn2Response
            )
            ->withText('What was the number I asked you to remember earlier? Respond with only the number.')
            ->generateTextResult();

        $candidates = $turn3Result->getCandidates();
        $this->assertNotEmpty($candidates);

        $text = $candidates[0]->getMessage()->getParts()[0]->getText();
        $this->assertNotNull($text);
        $this->assertStringContainsString('42', $text);
    }
}
