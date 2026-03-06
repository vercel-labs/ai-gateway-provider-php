<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use Vercel\AiGatewayProvider\Authentication\AiGatewayRequestAuthentication;
use Vercel\AiGatewayProvider\Models\AiGatewayTextAndImageGenerationModel;
use Vercel\AiGatewayProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * @group text-generation
 * @group image-generation
 */
class AiGatewayTextAndImageGenerationModelTest extends TestCase
{
    private const GATEWAY_MODEL_ID = 'google/gemini-2.5-flash-preview-image';
    private const API_KEY = 'test-api-key-123';

    private function createModel(MockHttpTransporter $transporter): AiGatewayTextAndImageGenerationModel
    {
        $metadata = new ModelMetadata(
            'gemini-2.5-flash-preview-image',
            'Gemini 2.5 Flash Preview Image',
            [],
            []
        );
        $providerMetadata = new ProviderMetadata(
            'ai_gateway',
            'AI Gateway',
            ProviderTypeEnum::cloud()
        );

        $model = new AiGatewayTextAndImageGenerationModel(
            $metadata,
            $providerMetadata,
            self::GATEWAY_MODEL_ID
        );

        $model->setHttpTransporter($transporter);
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication(self::API_KEY));

        return $model;
    }

    private function createMockTextResponse(): Response
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

    private function createMockImageResponse(): Response
    {
        $base64Data = base64_encode('fake-image-data');
        return new Response(
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
                'usage' => [
                    'promptTokens' => 15,
                    'completionTokens' => 20,
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

    public function testImplementsBothInterfaces(): void
    {
        $transporter = new MockHttpTransporter($this->createMockTextResponse());
        $model = $this->createModel($transporter);

        $this->assertInstanceOf(TextGenerationModelInterface::class, $model);
        $this->assertInstanceOf(ImageGenerationModelInterface::class, $model);
    }

    public function testGetRequestAuthenticationReturnsAiGatewayType(): void
    {
        $transporter = new MockHttpTransporter($this->createMockTextResponse());
        $model = $this->createModel($transporter);

        $auth = $model->getRequestAuthentication();

        $this->assertInstanceOf(AiGatewayRequestAuthentication::class, $auth);
    }

    public function testGenerateTextResultSendsToLanguageModelEndpoint(): void
    {
        $transporter = new MockHttpTransporter($this->createMockTextResponse());
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $this->assertSame('Hello from the model.', $result->toText());

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertStringContainsString('language-model', $request->getUri());
        $this->assertSame('3', $request->getHeaderAsString('ai-language-model-specification-version'));
        $this->assertSame(self::GATEWAY_MODEL_ID, $request->getHeaderAsString('ai-language-model-id'));
    }

    public function testGenerateImageResultSendsToLanguageModelEndpoint(): void
    {
        $transporter = new MockHttpTransporter($this->createMockImageResponse());
        $model = $this->createModel($transporter);

        $result = $model->generateImageResult($this->createSimplePrompt());

        $this->assertInstanceOf(GenerativeAiResult::class, $result);

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertStringContainsString('language-model', $request->getUri());
        $this->assertStringNotContainsString('image-model', $request->getUri());
        $this->assertSame(self::GATEWAY_MODEL_ID, $request->getHeaderAsString('ai-language-model-id'));
    }

    public function testConfigIsForwardedToInnerModel(): void
    {
        $transporter = new MockHttpTransporter($this->createMockTextResponse());
        $model = $this->createModel($transporter);

        $config = ModelConfig::fromArray([
            'temperature' => 0.7,
            'maxTokens' => 1024,
        ]);
        $model->setConfig($config);

        $model->generateTextResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame(0.7, $body['temperature']);
        $this->assertSame(1024, $body['maxTokens']);
    }

    public function testOutputModalitiesForwardedAsProviderOptions(): void
    {
        $transporter = new MockHttpTransporter($this->createMockImageResponse());
        $model = $this->createModel($transporter);

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

    public function testTokenUsageParsedFromResponse(): void
    {
        $transporter = new MockHttpTransporter($this->createMockTextResponse());
        $model = $this->createModel($transporter);

        $result = $model->generateTextResult($this->createSimplePrompt());

        $usage = $result->getTokenUsage();
        $this->assertSame(10, $usage->getPromptTokens());
        $this->assertSame(5, $usage->getCompletionTokens());
        $this->assertSame(15, $usage->getTotalTokens());
    }
}
