<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use Vercel\AiGatewayProvider\Authentication\AiGatewayRequestAuthentication;
use Vercel\AiGatewayProvider\Models\AiGatewayImageGenerationModel;
use Vercel\AiGatewayProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * @group image-generation
 */
class AiGatewayImageGenerationModelTest extends TestCase
{
    private const GATEWAY_MODEL_ID = 'openai/gpt-image-1';
    private const API_KEY = 'test-api-key-123';

    private function createModel(MockHttpTransporter $transporter): AiGatewayImageGenerationModel
    {
        $metadata = new ModelMetadata(
            'gpt-image-1',
            'GPT Image 1',
            [],
            []
        );
        $providerMetadata = new ProviderMetadata(
            'ai_gateway',
            'AI Gateway',
            ProviderTypeEnum::cloud()
        );

        $model = new AiGatewayImageGenerationModel(
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
                'images' => [base64_encode('fake-image-data')],
            ])
        );
    }

    private function createSimplePrompt(): array
    {
        return [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart('A cat sitting on a windowsill')]
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

        $model->generateImageResult($this->createSimplePrompt());

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('3', $request->getHeaderAsString('ai-image-model-specification-version'));
        $this->assertSame(self::GATEWAY_MODEL_ID, $request->getHeaderAsString('ai-model-id'));
        $this->assertSame('application/json', $request->getHeaderAsString('Content-Type'));
    }

    public function testRequestBodyContainsPromptAndDefaults(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertNotNull($body);
        $this->assertSame('A cat sitting on a windowsill', $body['prompt']);
        $this->assertSame(1, $body['n']);
        $this->assertIsObject($body['providerOptions']);
    }

    public function testSingleImageResponseParsing(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $result = $model->generateImageResult($this->createSimplePrompt());

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $candidates = $result->getCandidates();
        $this->assertCount(1, $candidates);

        $parts = $candidates[0]->getMessage()->getParts();
        $this->assertCount(1, $parts);

        $file = $parts[0]->getFile();
        $this->assertNotNull($file);
        $this->assertSame('image/png', $file->getMimeType());
    }

    public function testResponseIdParsedWhenPresent(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'images' => [base64_encode('fake-image-data')],
                'providerMetadata' => [
                    'gateway' => ['generationId' => 'resp-img-456'],
                ],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateImageResult($this->createSimplePrompt());

        $this->assertSame('resp-img-456', $result->getId());
    }

    public function testResponseIdDefaultsToEmptyStringWhenMissing(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $result = $model->generateImageResult($this->createSimplePrompt());

        $this->assertSame('', $result->getId());
    }

    public function testMultipleImageResponseParsing(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'images' => [
                    base64_encode('fake-image-data-1'),
                    base64_encode('fake-image-data-2'),
                    base64_encode('fake-image-data-3'),
                ],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateImageResult($this->createSimplePrompt());

        $candidates = $result->getCandidates();
        $this->assertCount(3, $candidates);

        foreach ($candidates as $candidate) {
            $parts = $candidate->getMessage()->getParts();
            $this->assertCount(1, $parts);
            $file = $parts[0]->getFile();
            $this->assertNotNull($file);
            $this->assertSame('image/png', $file->getMimeType());
        }
    }

    public function testMissingImagesThrowsResponseException(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode(['someOtherKey' => 'value'])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('images');

        $model->generateImageResult($this->createSimplePrompt());
    }



    public function testCandidateCountMapsToNInBody(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'candidateCount' => 3,
        ]));

        $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame(3, $body['n']);
    }

    public function testAspectRatioIncludedInBody(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'outputMediaAspectRatio' => '16:9',
        ]));

        $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame('16:9', $body['aspectRatio']);
    }

    public function testOrientationLandscapeMapsToAspectRatio(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'outputMediaOrientation' => 'landscape',
        ]));

        $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame('16:9', $body['aspectRatio']);
    }

    public function testOrientationPortraitMapsToAspectRatio(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'outputMediaOrientation' => 'portrait',
        ]));

        $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame('9:16', $body['aspectRatio']);
    }

    public function testOrientationSquareMapsToAspectRatio(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'outputMediaOrientation' => 'square',
        ]));

        $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame('1:1', $body['aspectRatio']);
    }

    public function testExplicitAspectRatioOverridesOrientation(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'outputMediaAspectRatio' => '4:3',
            'outputMediaOrientation' => 'landscape',
        ]));

        $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame('4:3', $body['aspectRatio']);
    }

    public function testMultipleCandidateResponseParsing(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'images' => [
                    base64_encode('fake-image-data-1'),
                    base64_encode('fake-image-data-2'),
                    base64_encode('fake-image-data-3'),
                ],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'candidateCount' => 3,
        ]));

        $result = $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame(3, $body['n']);

        $candidates = $result->getCandidates();
        $this->assertCount(3, $candidates);

        foreach ($candidates as $candidate) {
            $parts = $candidate->getMessage()->getParts();
            $this->assertCount(1, $parts);
            $file = $parts[0]->getFile();
            $this->assertNotNull($file);
            $this->assertSame('image/png', $file->getMimeType());
        }
    }

    public function testSeedExtractedAsTopLevelField(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'customOptions' => ['seed' => 42],
        ]));

        $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame(42, $body['seed']);
        $this->assertInstanceOf(\stdClass::class, $body['providerOptions']);
    }

    public function testNonSeedCustomOptionsInProviderOptions(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'customOptions' => ['style' => 'vivid'],
        ]));

        $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertArrayNotHasKey('style', $body);
        $this->assertSame(['openai' => ['style' => 'vivid']], $body['providerOptions']);
    }

    public function testMixedCustomOptionsSplitting(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'customOptions' => ['seed' => 42, 'style' => 'vivid'],
        ]));

        $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame(42, $body['seed']);
        $this->assertSame(['openai' => ['style' => 'vivid']], $body['providerOptions']);
    }

    public function testNoCustomOptionsDefaultsEmptyProviderOptions(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertInstanceOf(\stdClass::class, $body['providerOptions']);
    }

    public function testTokenUsageParsedWhenPresent(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'images' => [base64_encode('fake-image-data')],
                'usage' => ['inputTokens' => 10, 'outputTokens' => 0, 'totalTokens' => 10],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateImageResult($this->createSimplePrompt());
        $usage = $result->getTokenUsage();

        $this->assertSame(10, $usage->getPromptTokens());
        $this->assertSame(0, $usage->getCompletionTokens());
        $this->assertSame(10, $usage->getTotalTokens());
    }

    public function testTokenUsageDefaultsWhenAbsent(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);

        $result = $model->generateImageResult($this->createSimplePrompt());
        $usage = $result->getTokenUsage();

        $this->assertSame(0, $usage->getPromptTokens());
        $this->assertSame(0, $usage->getCompletionTokens());
        $this->assertSame(0, $usage->getTotalTokens());
    }

    public function testTokenUsageTotalCalculatedFromParts(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode([
                'images' => [base64_encode('fake-image-data')],
                'usage' => ['inputTokens' => 10, 'outputTokens' => 5],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateImageResult($this->createSimplePrompt());
        $usage = $result->getTokenUsage();

        $this->assertSame(10, $usage->getPromptTokens());
        $this->assertSame(5, $usage->getCompletionTokens());
        $this->assertSame(15, $usage->getTotalTokens());
    }

    public function testProviderOptionsKeyMergesIntoProviderOptions(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'customOptions' => [
                'providerOptions' => [
                    'openai' => ['quality' => 'hd'],
                ],
            ],
        ]));

        $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame(['openai' => ['quality' => 'hd']], $body['providerOptions']);
    }

    public function testProviderNameKeyHandling(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'customOptions' => [
                'openai' => ['quality' => 'hd', 'style' => 'vivid'],
            ],
        ]));

        $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame(
            ['openai' => ['quality' => 'hd', 'style' => 'vivid']],
            $body['providerOptions']
        );
    }

    public function testGatewayKeyHandling(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'customOptions' => [
                'gateway' => ['cacheControl' => true],
            ],
        ]));

        $model->generateImageResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame(
            ['gateway' => ['cacheControl' => true]],
            $body['providerOptions']
        );
    }

    public function testConflictingProviderOptionsThrows(): void
    {
        $transporter = new MockHttpTransporter($this->createMockResponse());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'customOptions' => [
                'style' => 'vivid',
                'openai' => ['style' => 'natural'],
            ],
        ]));

        $this->expectException(InvalidArgumentException::class);
        $model->generateImageResult($this->createSimplePrompt());
    }
}
