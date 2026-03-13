<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use Vercel\AiGatewayProvider\Authentication\AiGatewayRequestAuthentication;
use Vercel\AiGatewayProvider\Models\AiGatewayTextGenerationModel;
use Vercel\AiGatewayProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessagePartChannelEnum;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/**
 * @group text-generation
 */
class AiGatewayTextGenerationModelTest extends TestCase
{
    private const GATEWAY_MODEL_ID = 'anthropic/claude-sonnet-4-6';
    private const API_KEY = 'test-api-key-123';

    private function createModel(MockHttpTransporter $transporter): AiGatewayTextGenerationModel
    {
        $metadata = new ModelMetadata(
            'claude-sonnet-4-6',
            'Claude Sonnet',
            [],
            []
        );
        $providerMetadata = new ProviderMetadata(
            'ai_gateway',
            'AI Gateway',
            ProviderTypeEnum::cloud()
        );

        $model = new AiGatewayTextGenerationModel(
            $metadata,
            $providerMetadata,
            self::GATEWAY_MODEL_ID
        );

        $model->setHttpTransporter($transporter);
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication(self::API_KEY));

        return $model;
    }

    private function createMockResponse(): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    'type' => 'text',
                    'text' => 'Hello from the model.',
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [
                    'promptTokens' => 10,
                    'completionTokens' => 5,
                ],
            ])
        );
    }

    private function createSimplePrompt(): array
    {
        return [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Hello')]
            ),
        ];
    }

    public function testGetRequestAuthenticationReturnsAiGatewayType(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $auth = $model->getRequestAuthentication();

        $this->assertInstanceOf(AiGatewayRequestAuthentication::class, $auth);
    }

    public function testRequestIncludesRequiredHeaders(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $model->generateTextResult($this->createSimplePrompt());

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('3', $request->getHeaderAsString('ai-language-model-specification-version'));
        $this->assertSame(self::GATEWAY_MODEL_ID, $request->getHeaderAsString('ai-language-model-id'));
        $this->assertSame('false', $request->getHeaderAsString('ai-language-model-streaming'));
        $this->assertSame('application/json', $request->getHeaderAsString('Content-Type'));
    }

    public function testRequestBodyIncludesSystemInstruction(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $config = ModelConfig::fromArray([
            'systemInstruction' => 'You are a helpful assistant.',
        ]);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertNotNull($body);

        $systemMessage = $body['prompt'][0];
        $this->assertSame('system', $systemMessage['role']);
        $this->assertSame(
            [['type' => 'text', 'text' => 'You are a helpful assistant.']],
            $systemMessage['content']
        );
    }

    public function testRequestBodyIncludesConfigOptions(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $config = ModelConfig::fromArray([
            'temperature' => 0.7,
            'maxTokens' => 1024,
            'topP' => 0.9,
            'topK' => 40,
            'stopSequences' => ['END', 'STOP'],
            'frequencyPenalty' => 0.5,
            'presencePenalty' => 0.3,
        ]);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame(0.7, $body['temperature']);
        $this->assertSame(1024, $body['maxTokens']);
        $this->assertSame(0.9, $body['topP']);
        $this->assertSame(40, $body['topK']);
        $this->assertSame(['END', 'STOP'], $body['stopSequences']);
        $this->assertSame(0.5, $body['frequencyPenalty']);
        $this->assertSame(0.3, $body['presencePenalty']);
    }

    public function testRequestBodyIncludesMultiTurnMessages(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $prompt = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('What is PHP?')]
            ),
            new Message(
                MessageRoleEnum::model(),
                [new MessagePart('PHP is a scripting language.')]
            ),
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Tell me more.')]
            ),
        ];

        $model->generateTextResult($prompt);

        $body = $transporter->getLastRequest()->getData();
        $messages = $body['prompt'];

        $this->assertCount(3, $messages);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('user', $messages[2]['role']);
        $this->assertSame('What is PHP?', $messages[0]['content'][0]['text']);
        $this->assertSame('PHP is a scripting language.', $messages[1]['content'][0]['text']);
        $this->assertSame('Tell me more.', $messages[2]['content'][0]['text']);
    }

    public function testRequestBodyIncludesImageContent(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $base64Data = base64_encode('fake-image-data');
        $imageFile = new File($base64Data, 'image/png');

        $prompt = [
            new Message(
                MessageRoleEnum::user(),
                [
                    new MessagePart('Describe this image.'),
                    new MessagePart($imageFile),
                ]
            ),
        ];

        $model->generateTextResult($prompt);

        $body = $transporter->getLastRequest()->getData();
        $content = $body['prompt'][0]['content'];

        $this->assertSame('text', $content[0]['type']);
        $this->assertSame('Describe this image.', $content[0]['text']);

        $this->assertSame('file', $content[1]['type']);
        $this->assertSame('data:image/png;base64,' . $base64Data, $content[1]['data']);
        $this->assertSame('image/png', $content[1]['mediaType']);
    }

    public function testRequestBodyIncludesFunctionDeclarations(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $config = ModelConfig::fromArray([]);
        $config->setFunctionDeclarations([
            new FunctionDeclaration(
                'get_weather',
                'Gets the weather for a location.',
                [
                    'type' => 'object',
                    'properties' => [
                        'location' => ['type' => 'string'],
                    ],
                    'required' => ['location'],
                ]
            ),
        ]);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertArrayHasKey('tools', $body);
        $this->assertCount(1, $body['tools']);

        $tool = $body['tools'][0];
        $this->assertSame('function', $tool['type']);
        $this->assertSame('get_weather', $tool['name']);
        $this->assertSame('Gets the weather for a location.', $tool['description']);
        $this->assertSame(
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']], 'required' => ['location']],
            $tool['inputSchema']
        );
    }

    public function testRequestBodyIncludesFunctionCallAndResponseParts(): void
    {
        $mockResponse = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    'type' => 'text',
                    'text' => 'The weather is sunny.',
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [
                    'promptTokens' => 20,
                    'completionTokens' => 10,
                ],
            ])
        );

        $transporter = new MockHttpTransporter($mockResponse);
        $model = $this->createModel($transporter);

        $prompt = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('What is the weather?')]
            ),
            new Message(
                MessageRoleEnum::model(),
                [
                    new MessagePart(
                        new FunctionCall('call_123', 'get_weather', ['location' => 'Paris'])
                    ),
                ]
            ),
            new Message(
                MessageRoleEnum::user(),
                [
                    new MessagePart(
                        new FunctionResponse('call_123', 'get_weather', ['temp' => 22])
                    ),
                ]
            ),
        ];

        $model->generateTextResult($prompt);

        $body = $transporter->getLastRequest()->getData();
        $messages = $body['prompt'];

        $this->assertCount(3, $messages);

        $assistantContent = $messages[1]['content'][0];
        $this->assertSame('tool-call', $assistantContent['type']);
        $this->assertSame('call_123', $assistantContent['toolCallId']);
        $this->assertSame('get_weather', $assistantContent['toolName']);
        $this->assertSame(['location' => 'Paris'], $assistantContent['input']);

        $this->assertSame('tool', $messages[2]['role']);
        $toolContent = $messages[2]['content'][0];
        $this->assertSame('tool-result', $toolContent['type']);
        $this->assertSame('call_123', $toolContent['toolCallId']);
        $this->assertSame('get_weather', $toolContent['toolName']);
        $this->assertSame(['type' => 'json', 'value' => ['temp' => 22]], $toolContent['output']);
    }

    public function testRequestBodyIncludesJsonResponseFormat(): void
    {
        $transporter = new MockHttpTransporter(new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    'type' => 'text',
                    'text' => '{"name":"test"}',
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        ));
        $model = $this->createModel($transporter);

        $schema = [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
            'required' => ['name'],
        ];
        $config = ModelConfig::fromArray([]);
        $config->setOutputSchema($schema);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertArrayHasKey('responseFormat', $body);
        $this->assertSame('json', $body['responseFormat']['type']);
        $this->assertSame($schema, $body['responseFormat']['schema']);
    }

    public function testRequestBodyPassesThroughCustomOptions(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $config = ModelConfig::fromArray([
            'customOptions' => [
                'seed' => 42,
                'logitBias' => ['hello' => 1.0],
            ],
        ]);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertArrayNotHasKey('seed', $body);
        $this->assertArrayNotHasKey('logitBias', $body);
        $this->assertSame(
            ['anthropic' => ['seed' => 42, 'logitBias' => ['hello' => 1.0]]],
            $body['providerOptions']
        );
    }

    public function testRequestAuthenticationHeadersAreIncluded(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $model->generateTextResult($this->createSimplePrompt());

        $request = $transporter->getLastRequest();
        $this->assertSame(
            'Bearer ' . self::API_KEY,
            $request->getHeaderAsString('Authorization')
        );
        $this->assertSame(
            '0.0.1',
            $request->getHeaderAsString('ai-gateway-protocol-version')
        );
        $this->assertSame(
            'api-key',
            $request->getHeaderAsString('ai-gateway-auth-method')
        );
    }

    public function testParseSimpleTextResponse(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    ['type' => 'text', 'text' => 'Hello, world!'],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => ['promptTokens' => 5, 'completionTokens' => 3],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $this->assertSame('Hello, world!', $result->toText());
    }

    public function testParseResponseWithToolCallContent(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'tool-call',
                        'toolCallId' => 'call_abc',
                        'toolName' => 'get_weather',
                        'input' => json_encode(['location' => 'Berlin']),
                    ],
                ],
                'finishReason' => ['unified' => 'tool-calls'],
                'usage' => ['promptTokens' => 10, 'completionTokens' => 8],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertCount(1, $parts);

        $functionCall = $parts[0]->getFunctionCall();
        $this->assertNotNull($functionCall);
        $this->assertSame('call_abc', $functionCall->getId());
        $this->assertSame('get_weather', $functionCall->getName());
        $this->assertSame(['location' => 'Berlin'], $functionCall->getArgs());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function dataProviderFinishReasons(): array
    {
        return [
            'stop' => ['stop', FinishReasonEnum::STOP],
            'length' => ['length', FinishReasonEnum::LENGTH],
            'content-filter' => ['content-filter', FinishReasonEnum::CONTENT_FILTER],
            'tool-calls' => ['tool-calls', FinishReasonEnum::TOOL_CALLS],
            'error' => ['error', FinishReasonEnum::ERROR],
            'other' => ['other', FinishReasonEnum::STOP],
        ];
    }

    /**
     * @dataProvider dataProviderFinishReasons
     */
    public function testFinishReasonMapping(string $apiReason, string $expectedValue): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'finishReason' => ['unified' => $apiReason, 'raw' => 'some_raw_reason'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());

        $candidate = $result->getCandidates()[0];
        $this->assertSame($expectedValue, $candidate->getFinishReason()->value);
    }

    public function testUnknownFinishReasonThrowsResponseException(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'finishReason' => ['unified' => 'unknown_reason'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('finishReason');

        $model->generateTextResult($this->createSimplePrompt());
    }

    public function testMissingContentThrowsResponseException(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('content');

        $model->generateTextResult($this->createSimplePrompt());
    }

    public function testMissingResponseBodyThrowsResponseException(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            null
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('response body');

        $model->generateTextResult($this->createSimplePrompt());
    }

    public function testTokenUsageNestedFormat(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => 'hi']],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [
                    'inputTokens' => ['total' => 25],
                    'outputTokens' => ['total' => 12],
                ],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());
        $usage = $result->getTokenUsage();

        $this->assertSame(25, $usage->getPromptTokens());
        $this->assertSame(12, $usage->getCompletionTokens());
        $this->assertSame(37, $usage->getTotalTokens());
    }

    public function testTokenUsageFlatFormat(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => 'hi']],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [
                    'promptTokens' => 30,
                    'completionTokens' => 15,
                ],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());
        $usage = $result->getTokenUsage();

        $this->assertSame(30, $usage->getPromptTokens());
        $this->assertSame(15, $usage->getCompletionTokens());
        $this->assertSame(45, $usage->getTotalTokens());
    }

    public function testJsonSchemaResponseRoundTrip(): void
    {
        $jsonText = '{"city":"Paris","temperature":22,"unit":"celsius"}';
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => $jsonText]],
                'finishReason' => ['unified' => 'stop'],
                'usage' => ['promptTokens' => 10, 'completionTokens' => 8],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $schema = [
            'type' => 'object',
            'properties' => [
                'city' => ['type' => 'string'],
                'temperature' => ['type' => 'number'],
                'unit' => ['type' => 'string'],
            ],
            'required' => ['city', 'temperature', 'unit'],
        ];
        $config = ModelConfig::fromArray([]);
        $config->setOutputSchema($schema);
        $model->setConfig($config);

        $result = $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertArrayHasKey('responseFormat', $body);
        $this->assertSame('json', $body['responseFormat']['type']);
        $this->assertSame($schema, $body['responseFormat']['schema']);

        $text = $result->toText();
        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('city', $decoded);
        $this->assertArrayHasKey('temperature', $decoded);
        $this->assertArrayHasKey('unit', $decoded);
        $this->assertSame('Paris', $decoded['city']);
        $this->assertSame(22, $decoded['temperature']);
        $this->assertSame('celsius', $decoded['unit']);
    }

    public function testFunctionCallingRoundTrip(): void
    {
        $toolCallResponse = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'tool-call',
                        'toolCallId' => 'call_weather_1',
                        'toolName' => 'get_weather',
                        'input' => json_encode(['location' => 'Paris']),
                    ],
                ],
                'finishReason' => ['unified' => 'tool-calls'],
                'usage' => ['promptTokens' => 15, 'completionTokens' => 10],
            ])
        );

        $textResponse = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => 'The weather in Paris is 18°C.']],
                'finishReason' => ['unified' => 'stop'],
                'usage' => ['promptTokens' => 30, 'completionTokens' => 12],
            ])
        );

        $transporter = new MockHttpTransporter($toolCallResponse, $textResponse);
        $model = $this->createModel($transporter);

        $config = ModelConfig::fromArray([]);
        $config->setFunctionDeclarations([
            new FunctionDeclaration(
                'get_weather',
                'Gets the weather for a location.',
                [
                    'type' => 'object',
                    'properties' => [
                        'location' => ['type' => 'string'],
                    ],
                    'required' => ['location'],
                ]
            ),
        ]);
        $model->setConfig($config);

        $userMessage = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('What is the weather in Paris?')]
            ),
        ];
        $result1 = $model->generateTextResult($userMessage);

        $body1 = $transporter->getRequest(0)->getData();
        $this->assertArrayHasKey('tools', $body1);
        $this->assertSame('get_weather', $body1['tools'][0]['name']);

        $parts = $result1->toMessage()->getParts();
        $this->assertCount(1, $parts);
        $functionCall = $parts[0]->getFunctionCall();
        $this->assertInstanceOf(FunctionCall::class, $functionCall);
        $this->assertSame('call_weather_1', $functionCall->getId());
        $this->assertSame('get_weather', $functionCall->getName());
        $this->assertSame(['location' => 'Paris'], $functionCall->getArgs());

        $multiTurnPrompt = [
            $userMessage[0],
            $result1->toMessage(),
            new Message(
                MessageRoleEnum::user(),
                [
                    new MessagePart(
                        new FunctionResponse(
                            'call_weather_1',
                            'get_weather',
                            ['city' => 'Paris', 'temperature' => 18, 'unit' => 'celsius']
                        )
                    ),
                ]
            ),
        ];
        $result2 = $model->generateTextResult($multiTurnPrompt);

        $body2 = $transporter->getRequest(1)->getData();
        $messages = $body2['prompt'];
        $this->assertCount(3, $messages);

        $this->assertSame('user', $messages[0]['role']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertSame('tool-call', $messages[1]['content'][0]['type']);
        $this->assertSame('call_weather_1', $messages[1]['content'][0]['toolCallId']);
        $this->assertSame('get_weather', $messages[1]['content'][0]['toolName']);

        $this->assertSame('tool', $messages[2]['role']);
        $this->assertSame('tool-result', $messages[2]['content'][0]['type']);
        $this->assertSame('call_weather_1', $messages[2]['content'][0]['toolCallId']);

        $this->assertSame('The weather in Paris is 18°C.', $result2->toText());
    }

    private function createModelWithGatewayId(MockHttpTransporter $transporter, string $gatewayModelId): AiGatewayTextGenerationModel
    {
        $metadata = new ModelMetadata(
            'test-model',
            'Test Model',
            [],
            []
        );
        $providerMetadata = new ProviderMetadata(
            'ai_gateway',
            'AI Gateway',
            ProviderTypeEnum::cloud()
        );

        $model = new AiGatewayTextGenerationModel(
            $metadata,
            $providerMetadata,
            $gatewayModelId
        );

        $model->setHttpTransporter($transporter);
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication(self::API_KEY));

        return $model;
    }

    public function testRequestBodyIncludesResponseModalitiesForMultimodalOutput(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash-preview-image');

        $config = ModelConfig::fromArray([
            'outputModalities' => ['image', 'text'],
        ]);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertArrayHasKey('providerOptions', $body);
        $this->assertSame(
            ['google' => ['responseModalities' => ['IMAGE', 'TEXT']]],
            $body['providerOptions']
        );
    }

    public function testRequestBodyIncludesImageConfigWithAspectRatio(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash-preview-image');

        $config = ModelConfig::fromArray([
            'outputModalities' => ['image', 'text'],
            'outputMediaAspectRatio' => '16:9',
        ]);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertArrayHasKey('providerOptions', $body);
        $this->assertSame(
            ['IMAGE', 'TEXT'],
            $body['providerOptions']['google']['responseModalities']
        );
        $this->assertSame(
            ['aspectRatio' => '16:9'],
            $body['providerOptions']['google']['imageConfig']
        );
    }

    public function testRequestBodyIncludesImageConfigFromOrientationFallback(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash-preview-image');

        $config = ModelConfig::fromArray([
            'outputModalities' => ['image', 'text'],
            'outputMediaOrientation' => 'landscape',
        ]);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame(
            ['aspectRatio' => '16:9'],
            $body['providerOptions']['google']['imageConfig']
        );
    }

    public function testRequestBodyOmitsImageConfigWithoutImageModality(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash-preview-image');

        $config = ModelConfig::fromArray([
            'outputModalities' => ['text', 'audio'],
            'outputMediaAspectRatio' => '16:9',
        ]);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertArrayHasKey('providerOptions', $body);
        $this->assertArrayNotHasKey('imageConfig', $body['providerOptions']['google']);
    }

    public function testRequestBodyOmitsImageConfigWhenNotSet(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash-preview-image');

        $config = ModelConfig::fromArray([
            'outputModalities' => ['image', 'text'],
        ]);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertArrayHasKey('providerOptions', $body);
        $this->assertArrayNotHasKey('imageConfig', $body['providerOptions']['google']);
    }

    public function testRequestBodyOmitsResponseModalitiesForTextOnly(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash-preview-image');

        $config = ModelConfig::fromArray([
            'outputModalities' => ['text'],
        ]);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertInstanceOf(\stdClass::class, $body['providerOptions']);
    }

    public function testRequestBodyOmitsResponseModalitiesWhenNull(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertInstanceOf(\stdClass::class, $body['providerOptions']);
    }

    public function testParseResponseWithFileContentPart(): void
    {
        $base64Data = base64_encode('fake-image-data');
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'file',
                        'data' => 'data:image/png;base64,' . $base64Data,
                        'mediaType' => 'image/png',
                    ],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => ['promptTokens' => 10, 'completionTokens' => 5],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertCount(1, $parts);

        $file = $parts[0]->getFile();
        $this->assertNotNull($file);
        $this->assertSame('image/png', $file->getMimeType());
    }

    public function testParseResponseWithMixedTextAndFileContentParts(): void
    {
        $base64Data = base64_encode('fake-image-data');
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    ['type' => 'text', 'text' => 'Here is the image:'],
                    [
                        'type' => 'file',
                        'data' => 'data:image/png;base64,' . $base64Data,
                        'mediaType' => 'image/png',
                    ],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => ['promptTokens' => 10, 'completionTokens' => 5],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertCount(2, $parts);

        $this->assertSame('Here is the image:', $parts[0]->getText());

        $file = $parts[1]->getFile();
        $this->assertNotNull($file);
        $this->assertSame('image/png', $file->getMimeType());
    }

    public function testTokenUsageMissingReturnsZeros(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => 'hi']],
                'finishReason' => ['unified' => 'stop'],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());
        $usage = $result->getTokenUsage();

        $this->assertSame(0, $usage->getPromptTokens());
        $this->assertSame(0, $usage->getCompletionTokens());
        $this->assertSame(0, $usage->getTotalTokens());
    }

    public function testTokenUsageNestedFormatWithReasoningTokens(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => 'hi']],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [
                    'inputTokens' => ['total' => 50],
                    'outputTokens' => ['total' => 200, 'reasoning' => 150],
                ],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());
        $usage = $result->getTokenUsage();

        $this->assertSame(50, $usage->getPromptTokens());
        $this->assertSame(200, $usage->getCompletionTokens());
        $this->assertSame(250, $usage->getTotalTokens());
        $this->assertSame(150, $usage->getThoughtTokens());
    }

    public function testTokenUsageNestedFormatWithoutReasoningTokens(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => 'hi']],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [
                    'inputTokens' => ['total' => 25],
                    'outputTokens' => ['total' => 12],
                ],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());
        $usage = $result->getTokenUsage();

        $this->assertSame(25, $usage->getPromptTokens());
        $this->assertSame(12, $usage->getCompletionTokens());
        $this->assertSame(37, $usage->getTotalTokens());
        $this->assertNull($usage->getThoughtTokens());
    }

    public function testTokenUsageFlatFormatReturnsNullThoughtTokens(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => 'hi']],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [
                    'promptTokens' => 30,
                    'completionTokens' => 15,
                ],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());
        $usage = $result->getTokenUsage();

        $this->assertSame(30, $usage->getPromptTokens());
        $this->assertSame(15, $usage->getCompletionTokens());
        $this->assertSame(45, $usage->getTotalTokens());
        $this->assertNull($usage->getThoughtTokens());
    }

    public function testProviderOptionsKeyMergesIntoBodyProviderOptions(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $config = ModelConfig::fromArray([
            'customOptions' => [
                'providerOptions' => [
                    'anthropic' => ['thinking' => true],
                ],
            ],
        ]);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame(
            ['anthropic' => ['thinking' => true]],
            $body['providerOptions']
        );
    }

    public function testProviderNameKeyGoesToProviderOptions(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $config = ModelConfig::fromArray([
            'customOptions' => [
                'anthropic' => ['thinking' => true, 'maxBudget' => 500],
            ],
        ]);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame(
            ['anthropic' => ['thinking' => true, 'maxBudget' => 500]],
            $body['providerOptions']
        );
    }

    public function testProviderNameKeyMergesWithModalities(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId(
            $transporter,
            'google/gemini-2.5-flash-preview-image'
        );

        $config = ModelConfig::fromArray([
            'outputModalities' => ['image', 'text'],
            'customOptions' => [
                'google' => ['safetySettings' => ['threshold' => 'high']],
            ],
        ]);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertArrayHasKey('providerOptions', $body);
        $this->assertSame(['IMAGE', 'TEXT'], $body['providerOptions']['google']['responseModalities']);
        $this->assertSame(
            ['threshold' => 'high'],
            $body['providerOptions']['google']['safetySettings']
        );
    }

    public function testProviderNameKeyConflictWithModalitiesThrows(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId(
            $transporter,
            'google/gemini-2.5-flash-preview-image'
        );

        $config = ModelConfig::fromArray([
            'outputModalities' => ['image', 'text'],
            'customOptions' => [
                'google' => ['responseModalities' => ['AUDIO']],
            ],
        ]);
        $model->setConfig($config);

        $this->expectException(InvalidArgumentException::class);
        $model->generateTextResult($this->createSimplePrompt());
    }

    public function testParseReasoningContentPartWithAnthropicSignature(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'text' => 'Let me think about this...',
                        'providerMetadata' => [
                            'anthropic' => ['signature' => 'sig-anthropic-abc'],
                        ],
                    ],
                    ['type' => 'text', 'text' => 'The answer is 42.'],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => ['promptTokens' => 10, 'completionTokens' => 20],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertCount(2, $parts);

        $this->assertTrue($parts[0]->getChannel()->isThought());
        $this->assertSame('Let me think about this...', $parts[0]->getText());
        $this->assertSame('sig-anthropic-abc', $parts[0]->getThoughtSignature());

        $this->assertTrue($parts[1]->getChannel()->isContent());
        $this->assertSame('The answer is 42.', $parts[1]->getText());
        $this->assertNull($parts[1]->getThoughtSignature());
    }

    public function testParseReasoningContentPartWithoutSignature(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'text' => 'Thinking without signature...',
                    ],
                    ['type' => 'text', 'text' => 'Done.'],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModelWithGatewayId($transporter, 'deepseek/deepseek-r1');

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertCount(2, $parts);

        $this->assertTrue($parts[0]->getChannel()->isThought());
        $this->assertSame('Thinking without signature...', $parts[0]->getText());
        $this->assertNull($parts[0]->getThoughtSignature());
    }

    public function testExtractThoughtSignatureGoogle(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'text' => 'Reasoning...',
                        'providerMetadata' => [
                            'google' => ['thoughtSignature' => 'google-sig-xyz'],
                        ],
                    ],
                    ['type' => 'text', 'text' => 'Result.'],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash');

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertSame('google-sig-xyz', $parts[0]->getThoughtSignature());
    }

    public function testExtractThoughtSignatureGoogleVertexFallback(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'text' => 'Reasoning...',
                        'providerMetadata' => [
                            'vertex' => ['thoughtSignature' => 'vertex-sig-abc'],
                        ],
                    ],
                    ['type' => 'text', 'text' => 'Result.'],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash');

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertSame('vertex-sig-abc', $parts[0]->getThoughtSignature());
    }

    public function testExtractThoughtSignatureOpenAI(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'text' => 'Thinking...',
                        'providerMetadata' => [
                            'openai' => ['reasoningEncryptedContent' => 'openai-enc-123'],
                        ],
                    ],
                    ['type' => 'text', 'text' => 'Answer.'],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModelWithGatewayId($transporter, 'openai/o3-mini');

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertSame('openai-enc-123', $parts[0]->getThoughtSignature());
    }

    public function testExtractThoughtSignatureXAI(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'text' => 'xAI thinking...',
                        'providerMetadata' => [
                            'xai' => ['reasoningEncryptedContent' => 'xai-enc-456'],
                        ],
                    ],
                    ['type' => 'text', 'text' => 'xAI answer.'],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModelWithGatewayId($transporter, 'xai/grok-3');

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertSame('xai-enc-456', $parts[0]->getThoughtSignature());
    }

    public function testExtractThoughtSignatureDefaultProvider(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'text' => 'Unknown provider thinking...',
                        'providerMetadata' => [
                            'someprovider' => ['signature' => 'default-sig-789'],
                        ],
                    ],
                    ['type' => 'text', 'text' => 'Response.'],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModelWithGatewayId($transporter, 'someprovider/some-model');

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertSame('default-sig-789', $parts[0]->getThoughtSignature());
    }

    public function testExtractThoughtSignatureDeepSeekNoMetadata(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'text' => 'DeepSeek thinking...',
                    ],
                    ['type' => 'text', 'text' => 'DeepSeek answer.'],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModelWithGatewayId($transporter, 'deepseek/deepseek-r1');

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertCount(2, $parts);
        $this->assertTrue($parts[0]->getChannel()->isThought());
        $this->assertNull($parts[0]->getThoughtSignature());
        $this->assertTrue($parts[1]->getChannel()->isContent());
        $this->assertNull($parts[1]->getThoughtSignature());
    }

    public function testThoughtSignatureOnTextPartForGoogle(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Text with signature.',
                        'providerMetadata' => [
                            'google' => ['thoughtSignature' => 'google-text-sig'],
                        ],
                    ],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash');

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertCount(1, $parts);
        $this->assertTrue($parts[0]->getChannel()->isContent());
        $this->assertSame('Text with signature.', $parts[0]->getText());
        $this->assertSame('google-text-sig', $parts[0]->getThoughtSignature());
    }

    public function testThoughtSignatureOnToolCallPart(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'tool-call',
                        'toolCallId' => 'call_1',
                        'toolName' => 'get_weather',
                        'input' => json_encode(['location' => 'Paris']),
                        'providerMetadata' => [
                            'google' => ['thoughtSignature' => 'google-tool-sig'],
                        ],
                    ],
                ],
                'finishReason' => ['unified' => 'tool-calls'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash');

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertCount(1, $parts);
        $this->assertNotNull($parts[0]->getFunctionCall());
        $this->assertSame('google-tool-sig', $parts[0]->getThoughtSignature());
    }

    public function testThoughtSignatureOnFilePart(): void
    {
        $base64Data = base64_encode('fake-image-data');
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'file',
                        'data' => 'data:image/png;base64,' . $base64Data,
                        'mediaType' => 'image/png',
                        'providerMetadata' => [
                            'google' => ['thoughtSignature' => 'google-file-sig'],
                        ],
                    ],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash');

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertCount(1, $parts);
        $this->assertNotNull($parts[0]->getFile());
        $this->assertSame('google-file-sig', $parts[0]->getThoughtSignature());
    }

    public function testReasoningOnlyResponseIsParsed(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'text' => 'Just thinking, no answer yet.',
                    ],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertCount(1, $parts);
        $this->assertTrue($parts[0]->getChannel()->isThought());
        $this->assertSame('Just thinking, no answer yet.', $parts[0]->getText());
    }

    public function testReasoningPartWithEmptyText(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'providerMetadata' => [
                            'anthropic' => ['signature' => 'sig-empty'],
                        ],
                    ],
                    ['type' => 'text', 'text' => 'Answer.'],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());

        $parts = $result->toMessage()->getParts();
        $this->assertCount(2, $parts);
        $this->assertTrue($parts[0]->getChannel()->isThought());
        $this->assertSame('', $parts[0]->getText());
        $this->assertSame('sig-empty', $parts[0]->getThoughtSignature());
    }

    public function testRequestBodyEmitsReasoningPartForThoughtChannelAnthropic(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $prompt = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('What is 2+2?')]
            ),
            new Message(
                MessageRoleEnum::model(),
                [
                    new MessagePart(
                        'Let me think...',
                        MessagePartChannelEnum::thought(),
                        'sig-anthropic-abc'
                    ),
                    new MessagePart('The answer is 4.'),
                ]
            ),
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Thanks')]
            ),
        ];

        $model->generateTextResult($prompt);

        $body = $transporter->getLastRequest()->getData();
        $assistantContent = $body['prompt'][1]['content'];

        $this->assertSame('reasoning', $assistantContent[0]['type']);
        $this->assertSame('Let me think...', $assistantContent[0]['text']);
        $this->assertSame(
            ['anthropic' => ['signature' => 'sig-anthropic-abc']],
            $assistantContent[0]['providerOptions']
        );

        $this->assertSame('text', $assistantContent[1]['type']);
        $this->assertSame('The answer is 4.', $assistantContent[1]['text']);
        $this->assertArrayNotHasKey('providerOptions', $assistantContent[1]);
    }

    public function testRequestBodyEmitsReasoningPartForThoughtChannelGoogle(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash');

        $prompt = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Explain')]
            ),
            new Message(
                MessageRoleEnum::model(),
                [
                    new MessagePart(
                        'Reasoning...',
                        MessagePartChannelEnum::thought(),
                        'google-sig-xyz'
                    ),
                    new MessagePart('Result.'),
                ]
            ),
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('OK')]
            ),
        ];

        $model->generateTextResult($prompt);

        $body = $transporter->getLastRequest()->getData();
        $assistantContent = $body['prompt'][1]['content'];

        $this->assertSame('reasoning', $assistantContent[0]['type']);
        $this->assertSame(
            ['google' => ['thoughtSignature' => 'google-sig-xyz']],
            $assistantContent[0]['providerOptions']
        );
    }

    public function testRequestBodyEmitsReasoningPartForThoughtChannelOpenAI(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId($transporter, 'openai/o3-mini');

        $prompt = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Think')]
            ),
            new Message(
                MessageRoleEnum::model(),
                [
                    new MessagePart(
                        'Thinking...',
                        MessagePartChannelEnum::thought(),
                        'openai-enc-123'
                    ),
                    new MessagePart('Done.'),
                ]
            ),
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('OK')]
            ),
        ];

        $model->generateTextResult($prompt);

        $body = $transporter->getLastRequest()->getData();
        $assistantContent = $body['prompt'][1]['content'];

        $this->assertSame('reasoning', $assistantContent[0]['type']);
        $this->assertSame(
            ['openai' => ['reasoningEncryptedContent' => 'openai-enc-123']],
            $assistantContent[0]['providerOptions']
        );
    }

    public function testRequestBodyEmitsReasoningPartForThoughtChannelXAI(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId($transporter, 'xai/grok-3');

        $prompt = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Think')]
            ),
            new Message(
                MessageRoleEnum::model(),
                [
                    new MessagePart(
                        'xAI thinking...',
                        MessagePartChannelEnum::thought(),
                        'xai-enc-456'
                    ),
                    new MessagePart('xAI answer.'),
                ]
            ),
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('OK')]
            ),
        ];

        $model->generateTextResult($prompt);

        $body = $transporter->getLastRequest()->getData();
        $assistantContent = $body['prompt'][1]['content'];

        $this->assertSame('reasoning', $assistantContent[0]['type']);
        $this->assertSame(
            ['xai' => ['reasoningEncryptedContent' => 'xai-enc-456']],
            $assistantContent[0]['providerOptions']
        );
    }

    public function testRequestBodyEmitsReasoningPartForThoughtChannelDefaultProvider(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId($transporter, 'someprovider/some-model');

        $prompt = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Think')]
            ),
            new Message(
                MessageRoleEnum::model(),
                [
                    new MessagePart(
                        'Thinking...',
                        MessagePartChannelEnum::thought(),
                        'default-sig-789'
                    ),
                    new MessagePart('Answer.'),
                ]
            ),
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('OK')]
            ),
        ];

        $model->generateTextResult($prompt);

        $body = $transporter->getLastRequest()->getData();
        $assistantContent = $body['prompt'][1]['content'];

        $this->assertSame('reasoning', $assistantContent[0]['type']);
        $this->assertSame(
            ['someprovider' => ['signature' => 'default-sig-789']],
            $assistantContent[0]['providerOptions']
        );
    }

    public function testRequestBodyEmitsReasoningPartWithoutSignature(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId($transporter, 'deepseek/deepseek-r1');

        $prompt = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Think')]
            ),
            new Message(
                MessageRoleEnum::model(),
                [
                    new MessagePart(
                        'DeepSeek thinking...',
                        MessagePartChannelEnum::thought()
                    ),
                    new MessagePart('DeepSeek answer.'),
                ]
            ),
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('OK')]
            ),
        ];

        $model->generateTextResult($prompt);

        $body = $transporter->getLastRequest()->getData();
        $assistantContent = $body['prompt'][1]['content'];

        $this->assertSame('reasoning', $assistantContent[0]['type']);
        $this->assertSame('DeepSeek thinking...', $assistantContent[0]['text']);
        $this->assertArrayNotHasKey('providerOptions', $assistantContent[0]);

        $this->assertSame('text', $assistantContent[1]['type']);
        $this->assertArrayNotHasKey('providerOptions', $assistantContent[1]);
    }

    public function testRequestBodyIncludesSignatureOnNonReasoningTextPart(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash');

        $prompt = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Explain')]
            ),
            new Message(
                MessageRoleEnum::model(),
                [
                    new MessagePart(
                        'Text with signature.',
                        null,
                        'google-text-sig'
                    ),
                ]
            ),
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('OK')]
            ),
        ];

        $model->generateTextResult($prompt);

        $body = $transporter->getLastRequest()->getData();
        $assistantContent = $body['prompt'][1]['content'];

        $this->assertSame('text', $assistantContent[0]['type']);
        $this->assertSame('Text with signature.', $assistantContent[0]['text']);
        $this->assertSame(
            ['google' => ['thoughtSignature' => 'google-text-sig']],
            $assistantContent[0]['providerOptions']
        );
    }

    public function testRequestBodyIncludesSignatureOnToolCallPart(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash');

        $prompt = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Get weather')]
            ),
            new Message(
                MessageRoleEnum::model(),
                [
                    new MessagePart(
                        new FunctionCall('call_1', 'get_weather', ['location' => 'Paris']),
                        null,
                        'google-tool-sig'
                    ),
                ]
            ),
            new Message(
                MessageRoleEnum::user(),
                [
                    new MessagePart(
                        new FunctionResponse('call_1', 'get_weather', ['temp' => 22])
                    ),
                ]
            ),
        ];

        $model->generateTextResult($prompt);

        $body = $transporter->getLastRequest()->getData();
        $assistantContent = $body['prompt'][1]['content'];

        $this->assertSame('tool-call', $assistantContent[0]['type']);
        $this->assertSame('call_1', $assistantContent[0]['toolCallId']);
        $this->assertSame(
            ['google' => ['thoughtSignature' => 'google-tool-sig']],
            $assistantContent[0]['providerOptions']
        );
    }

    public function testRequestBodyIncludesSignatureOnFilePart(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash');

        $base64Data = base64_encode('fake-image-data');
        $imageFile = new File($base64Data, 'image/png');

        $prompt = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Show me')]
            ),
            new Message(
                MessageRoleEnum::model(),
                [
                    new MessagePart(
                        $imageFile,
                        null,
                        'google-file-sig'
                    ),
                ]
            ),
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('OK')]
            ),
        ];

        $model->generateTextResult($prompt);

        $body = $transporter->getLastRequest()->getData();
        $assistantContent = $body['prompt'][1]['content'];

        $this->assertSame('file', $assistantContent[0]['type']);
        $this->assertSame(
            ['google' => ['thoughtSignature' => 'google-file-sig']],
            $assistantContent[0]['providerOptions']
        );
    }

    public function testRoundTripReasoningPartAnthropicSignature(): void
    {
        $responseWithReasoning = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'text' => 'Let me think...',
                        'providerMetadata' => [
                            'anthropic' => ['signature' => 'sig-round-trip'],
                        ],
                    ],
                    ['type' => 'text', 'text' => 'The answer is 4.'],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => ['promptTokens' => 10, 'completionTokens' => 20],
            ])
        );

        $finalResponse = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => 'You are welcome.']],
                'finishReason' => ['unified' => 'stop'],
                'usage' => ['promptTokens' => 30, 'completionTokens' => 5],
            ])
        );

        $transporter = new MockHttpTransporter($responseWithReasoning, $finalResponse);
        $model = $this->createModel($transporter);

        $userMessage = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('What is 2+2?')]
            ),
        ];
        $result1 = $model->generateTextResult($userMessage);

        $parts = $result1->toMessage()->getParts();
        $this->assertCount(2, $parts);
        $this->assertTrue($parts[0]->getChannel()->isThought());
        $this->assertSame('sig-round-trip', $parts[0]->getThoughtSignature());

        $multiTurnPrompt = [
            $userMessage[0],
            $result1->toMessage(),
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Thanks')]
            ),
        ];
        $model->generateTextResult($multiTurnPrompt);

        $body = $transporter->getRequest(1)->getData();
        $assistantContent = $body['prompt'][1]['content'];

        $this->assertSame('reasoning', $assistantContent[0]['type']);
        $this->assertSame('Let me think...', $assistantContent[0]['text']);
        $this->assertSame(
            ['anthropic' => ['signature' => 'sig-round-trip']],
            $assistantContent[0]['providerOptions']
        );

        $this->assertSame('text', $assistantContent[1]['type']);
        $this->assertSame('The answer is 4.', $assistantContent[1]['text']);
        $this->assertArrayNotHasKey('providerOptions', $assistantContent[1]);
    }

    public function testRoundTripReasoningPartGoogleSignature(): void
    {
        $responseWithReasoning = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'text' => 'Google reasoning...',
                        'providerMetadata' => [
                            'google' => ['thoughtSignature' => 'google-rt-sig'],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'Google answer.',
                        'providerMetadata' => [
                            'google' => ['thoughtSignature' => 'google-text-rt-sig'],
                        ],
                    ],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );

        $finalResponse = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => 'Done.']],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );

        $transporter = new MockHttpTransporter($responseWithReasoning, $finalResponse);
        $model = $this->createModelWithGatewayId($transporter, 'google/gemini-2.5-flash');

        $userMessage = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Think')]
            ),
        ];
        $result1 = $model->generateTextResult($userMessage);

        $multiTurnPrompt = [
            $userMessage[0],
            $result1->toMessage(),
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('OK')]
            ),
        ];
        $model->generateTextResult($multiTurnPrompt);

        $body = $transporter->getRequest(1)->getData();
        $assistantContent = $body['prompt'][1]['content'];

        $this->assertSame('reasoning', $assistantContent[0]['type']);
        $this->assertSame(
            ['google' => ['thoughtSignature' => 'google-rt-sig']],
            $assistantContent[0]['providerOptions']
        );

        $this->assertSame('text', $assistantContent[1]['type']);
        $this->assertSame(
            ['google' => ['thoughtSignature' => 'google-text-rt-sig']],
            $assistantContent[1]['providerOptions']
        );
    }

    public function testRoundTripReasoningPartOpenAISignature(): void
    {
        $responseWithReasoning = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'text' => 'OpenAI reasoning...',
                        'providerMetadata' => [
                            'openai' => ['reasoningEncryptedContent' => 'openai-rt-enc'],
                        ],
                    ],
                    ['type' => 'text', 'text' => 'OpenAI answer.'],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );

        $finalResponse = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => 'Done.']],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );

        $transporter = new MockHttpTransporter($responseWithReasoning, $finalResponse);
        $model = $this->createModelWithGatewayId($transporter, 'openai/o3-mini');

        $userMessage = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Think')]
            ),
        ];
        $result1 = $model->generateTextResult($userMessage);

        $multiTurnPrompt = [
            $userMessage[0],
            $result1->toMessage(),
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('OK')]
            ),
        ];
        $model->generateTextResult($multiTurnPrompt);

        $body = $transporter->getRequest(1)->getData();
        $assistantContent = $body['prompt'][1]['content'];

        $this->assertSame('reasoning', $assistantContent[0]['type']);
        $this->assertSame(
            ['openai' => ['reasoningEncryptedContent' => 'openai-rt-enc']],
            $assistantContent[0]['providerOptions']
        );
    }

    public function testRoundTripReasoningPartXAISignature(): void
    {
        $responseWithReasoning = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'text' => 'xAI reasoning...',
                        'providerMetadata' => [
                            'xai' => ['reasoningEncryptedContent' => 'xai-rt-enc'],
                        ],
                    ],
                    ['type' => 'text', 'text' => 'xAI answer.'],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );

        $finalResponse = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => 'Done.']],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );

        $transporter = new MockHttpTransporter($responseWithReasoning, $finalResponse);
        $model = $this->createModelWithGatewayId($transporter, 'xai/grok-3');

        $userMessage = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Think')]
            ),
        ];
        $result1 = $model->generateTextResult($userMessage);

        $multiTurnPrompt = [
            $userMessage[0],
            $result1->toMessage(),
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('OK')]
            ),
        ];
        $model->generateTextResult($multiTurnPrompt);

        $body = $transporter->getRequest(1)->getData();
        $assistantContent = $body['prompt'][1]['content'];

        $this->assertSame('reasoning', $assistantContent[0]['type']);
        $this->assertSame(
            ['xai' => ['reasoningEncryptedContent' => 'xai-rt-enc']],
            $assistantContent[0]['providerOptions']
        );
    }

    public function testRoundTripReasoningPartDeepSeekNoSignature(): void
    {
        $responseWithReasoning = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [
                    [
                        'type' => 'reasoning',
                        'text' => 'DeepSeek reasoning...',
                    ],
                    ['type' => 'text', 'text' => 'DeepSeek answer.'],
                ],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );

        $finalResponse = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'content' => [['type' => 'text', 'text' => 'Done.']],
                'finishReason' => ['unified' => 'stop'],
                'usage' => [],
            ])
        );

        $transporter = new MockHttpTransporter($responseWithReasoning, $finalResponse);
        $model = $this->createModelWithGatewayId($transporter, 'deepseek/deepseek-r1');

        $userMessage = [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('Think')]
            ),
        ];
        $result1 = $model->generateTextResult($userMessage);

        $multiTurnPrompt = [
            $userMessage[0],
            $result1->toMessage(),
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('OK')]
            ),
        ];
        $model->generateTextResult($multiTurnPrompt);

        $body = $transporter->getRequest(1)->getData();
        $assistantContent = $body['prompt'][1]['content'];

        $this->assertSame('reasoning', $assistantContent[0]['type']);
        $this->assertSame('DeepSeek reasoning...', $assistantContent[0]['text']);
        $this->assertArrayNotHasKey('providerOptions', $assistantContent[0]);

        $this->assertSame('text', $assistantContent[1]['type']);
        $this->assertArrayNotHasKey('providerOptions', $assistantContent[1]);
    }
}
