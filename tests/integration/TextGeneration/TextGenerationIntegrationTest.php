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
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/**
 * @group integration
 * @group text-generation
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
            'OpenAI'    => ['gpt-5.4-mini'],
            'xAI'       => ['grok-4.1-fast-non-reasoning'],
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
    public function testSimpleTextGeneration(string $modelId): void
    {
        $result = AiClient::prompt('What is the capital of France? Respond with only the city name.')
            ->usingModel(AiGatewayProvider::model($modelId))
            ->generateTextResult();

        $candidates = $result->getCandidates();
        $this->assertCount(1, $candidates);

        $text = $candidates[0]->getMessage()->getParts()[0]->getText();
        $this->assertNotNull($text);
        $this->assertNotEmpty($text);
        $this->assertStringContainsStringIgnoringCase('Paris', $text);

        $this->assertTokenUsage($modelId, $result->getTokenUsage());
    }

    /**
     * @dataProvider provideModels
     */
    public function testWithSystemInstruction(string $modelId): void
    {
        $result = AiClient::prompt('What is 2+2? Respond with only the number.')
            ->usingModel(AiGatewayProvider::model($modelId))
            ->usingSystemInstruction('You are a helpful math tutor.')
            ->generateTextResult();

        $candidates = $result->getCandidates();
        $this->assertCount(1, $candidates);

        $text = $candidates[0]->getMessage()->getParts()[0]->getText();
        $this->assertNotNull($text);
        $this->assertNotEmpty($text);
        $this->assertStringContainsString('4', $text);

        $this->assertTokenUsage($modelId, $result->getTokenUsage());
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

        $this->assertTokenUsage($modelId, $result->getTokenUsage());
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

        $this->assertTokenUsage($modelId, $result->getTokenUsage());
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
        $this->assertCount(1, $candidates);

        $text = $candidates[0]->getMessage()->getParts()[0]->getText();
        $this->assertNotNull($text);
        $this->assertStringContainsString('42', $text);

        $this->assertTokenUsage($modelId, $turn3Result->getTokenUsage());
    }

    /**
     * @dataProvider provideModels
     */
    public function testJsonSchemaResponse(string $modelId): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'actors' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'birthday' => ['type' => 'string'],
                        ],
                        'required' => ['name', 'birthday'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['actors'],
            'additionalProperties' => false,
        ];

        $result = AiClient::prompt(
            'Give me the 6 leading actors in the Star Wars movies with their birthdays.'
        )
            ->usingModel(AiGatewayProvider::model($modelId))
            ->asOutputSchema($schema)
            ->generateTextResult();

        $text = $result->toText();
        $this->assertNotEmpty($text);

        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded, 'Response text is not valid JSON: ' . $text);
        $this->assertArrayHasKey('actors', $decoded);
        $this->assertCount(6, $decoded['actors']);
        foreach ($decoded['actors'] as $actor) {
            $this->assertArrayHasKey('name', $actor);
            $this->assertArrayHasKey('birthday', $actor);
            $this->assertNotEmpty($actor['name']);
            $this->assertNotEmpty($actor['birthday']);
        }

        $this->assertTokenUsage($modelId, $result->getTokenUsage());
    }

    /**
     * @dataProvider provideModels
     */
    public function testFunctionCalling(string $modelId): void
    {
        $model = AiGatewayProvider::model($modelId);

        $declaration = new FunctionDeclaration(
            'get_weather',
            'Gets the current weather for a given location.',
            [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'The city name',
                    ],
                ],
                'required' => ['location'],
                'additionalProperties' => false,
            ]
        );

        $userMessage = new Message(
            MessageRoleEnum::user(),
            [new MessagePart('What is the weather in Austin?')]
        );

        $turn1Result = AiClient::prompt($userMessage)
            ->usingModel($model)
            ->usingFunctionDeclarations($declaration)
            ->generateTextResult();

        $turn1Message = $turn1Result->toMessage();
        $functionCall = null;
        foreach ($turn1Message->getParts() as $part) {
            if ($part->getFunctionCall() !== null) {
                $functionCall = $part->getFunctionCall();
                break;
            }
        }

        $this->assertInstanceOf(FunctionCall::class, $functionCall);
        $this->assertSame('get_weather', $functionCall->getName());
        $args = $functionCall->getArgs();
        $this->assertArrayHasKey('location', $args);
        $this->assertStringContainsStringIgnoringCase('Austin', $args['location']);

        $functionResponse = new FunctionResponse(
            $functionCall->getId(),
            'get_weather',
            ['city' => 'Austin', 'temperature' => 18, 'unit' => 'celsius']
        );

        $turn2Result = AiClient::prompt()
            ->usingModel($model)
            ->usingFunctionDeclarations($declaration)
            ->withHistory($userMessage, $turn1Message)
            ->withFunctionResponse($functionResponse)
            ->generateTextResult();

        $text = $turn2Result->toText();
        $this->assertNotEmpty($text);
        $this->assertStringContainsStringIgnoringCase('Austin', $text);

        $this->assertTokenUsage($modelId, $turn2Result->getTokenUsage());
    }

    /**
     * @return array<string, array{string}>
     */
    public function provideReasoningModels(): array
    {
        return [
            'Anthropic' => ['claude-sonnet-4.6'],
            'Google'    => ['gemini-3-pro-preview'],
            'OpenAI'    => ['o3-mini'],
            'xAI'       => ['grok-3-mini'],
        ];
    }

    /**
     * @dataProvider provideReasoningModels
     */
    public function testWithReasoning(string $modelId): void
    {
        $model = AiGatewayProvider::model($modelId);

        $providerOptions = [];
        switch ($modelId) {
            case 'claude-sonnet-4.6':
                $providerOptions = ['anthropic' => ['thinking' => ['type' => 'adaptive'], 'effort' => 'high']];
                break;
            case 'gemini-3-pro-preview':
                $providerOptions = ['google' => ['thinkingConfig' => ['includeThoughts' => true]]];
                break;
            case 'o3-mini':
                $providerOptions = ['openai' => ['reasoningEffort' => 'medium']];
                break;
            case 'grok-3-mini':
                // TODO: For some reason, despite the model reasoning, the response does not include reasoning parts.
                $providerOptions = ['xai' => ['reasoning' => [
                    'effort' => 'low',
                ]]];
                break;
        }

        $modelConfig = new ModelConfig();
        $modelConfig->setCustomOption('providerOptions', $providerOptions);

        $userMessage = new Message(
            MessageRoleEnum::user(),
            [new MessagePart(
                'A farmer has 15 apples. He gives 3 to each of his 4 neighbors. '
                . 'How many apples does he have left? Respond with only the final number.'
            )]
        );

        $turn1Result = AiClient::prompt($userMessage)
            ->usingModel($model)
            ->usingModelConfig($modelConfig)
            ->generateTextResult();

        $turn1Message = $turn1Result->toMessage();

        $hasThoughtPart = false;
        foreach ($turn1Message->getParts() as $part) {
            if ($part->getChannel()->isThought()) {
                $hasThoughtPart = true;
                break;
            }
        }
        $this->assertTrue($hasThoughtPart, 'Response should contain at least one thought-channel part.');

        $tokenUsage = $turn1Result->getTokenUsage();
        $this->assertTokenUsage($modelId, $tokenUsage);
        if ($modelId === 'claude-sonnet-4.6') {
            $this->assertNull($tokenUsage->getThoughtTokens(), 'The Anthropic API does not report thought tokens.');
        } else {
            $this->assertNotNull($tokenUsage->getThoughtTokens(), 'Thought tokens should be reported.');
            $this->assertGreaterThan(0, $tokenUsage->getThoughtTokens());
        }

        $turn2Result = AiClient::prompt()
            ->usingModel($model)
            ->usingModelConfig($modelConfig)
            ->withHistory($userMessage, $turn1Message)
            ->withText('Now double the remaining number of apples. What is the result?')
            ->generateTextResult();

        $this->assertNotEmpty($turn2Result->toText());
        $this->assertTokenUsage($modelId, $turn2Result->getTokenUsage());
    }
}
