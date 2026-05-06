<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use Vercel\AiGatewayProvider\Authentication\AiGatewayRequestAuthentication;
use Vercel\AiGatewayProvider\Models\AiGatewayVideoGenerationModel;
use Vercel\AiGatewayProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Files\DTO\File;
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
 * @group video-generation
 */
class AiGatewayVideoGenerationModelTest extends TestCase
{
    private const GATEWAY_MODEL_ID = 'google/veo-3.0-fast-generate-001';
    private const API_KEY = 'test-api-key-123';

    private function createModel(MockHttpTransporter $transporter): AiGatewayVideoGenerationModel
    {
        $metadata = new ModelMetadata(
            'veo-3.0-fast-generate-001',
            'Veo 3.0 Fast',
            [],
            []
        );
        $providerMetadata = new ProviderMetadata(
            'ai_gateway',
            'AI Gateway',
            ProviderTypeEnum::cloud()
        );

        $model = new AiGatewayVideoGenerationModel(
            $metadata,
            $providerMetadata,
            self::GATEWAY_MODEL_ID
        );

        $model->setHttpTransporter($transporter);
        $model->setRequestAuthentication(new ApiKeyRequestAuthentication(self::API_KEY));

        return $model;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sseBody(array $payload): string
    {
        return 'data: ' . json_encode($payload) . "\n\n";
    }

    private function createMockBase64Response(): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            $this->sseBody([
                'type' => 'result',
                'videos' => [
                    [
                        'type' => 'base64',
                        'data' => base64_encode('fake-video-data'),
                        'mediaType' => 'video/mp4',
                    ],
                ],
            ])
        );
    }

    /**
     * @param string $text
     * @return list<Message>
     */
    private function createSimplePrompt(string $text = 'A cat playing piano'): array
    {
        return [
            new Message(
                MessageRoleEnum::user(),
                [new MessagePart($text)]
            ),
        ];
    }

    public function testGetRequestAuthenticationReturnsAiGatewayType(): void
    {
        $transporter = new MockHttpTransporter($this->createMockBase64Response());
        $model = $this->createModel($transporter);

        $auth = $model->getRequestAuthentication();

        $this->assertInstanceOf(AiGatewayRequestAuthentication::class, $auth);
    }

    public function testRequestIncludesRequiredHeaders(): void
    {
        $transporter = new MockHttpTransporter($this->createMockBase64Response());
        $model = $this->createModel($transporter);

        $model->generateVideoResult($this->createSimplePrompt());

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertSame('4', $request->getHeaderAsString('ai-video-model-specification-version'));
        $this->assertSame(self::GATEWAY_MODEL_ID, $request->getHeaderAsString('ai-model-id'));
        $this->assertSame('application/json', $request->getHeaderAsString('Content-Type'));
        $this->assertSame('text/event-stream', $request->getHeaderAsString('Accept'));
    }

    public function testRequestUriPointsToVideoModelEndpoint(): void
    {
        $transporter = new MockHttpTransporter($this->createMockBase64Response());
        $model = $this->createModel($transporter);

        $model->generateVideoResult($this->createSimplePrompt());

        $request = $transporter->getLastRequest();
        $this->assertNotNull($request);
        $this->assertStringEndsWith('/video-model', $request->getUri());
    }

    public function testRequestBodyContainsPromptAndDefaults(): void
    {
        $transporter = new MockHttpTransporter($this->createMockBase64Response());
        $model = $this->createModel($transporter);

        $model->generateVideoResult($this->createSimplePrompt('A cat playing piano'));

        $body = $transporter->getLastRequest()->getData();
        $this->assertNotNull($body);
        $this->assertSame('A cat playing piano', $body['prompt']);
        $this->assertSame(1, $body['n']);
        $this->assertIsObject($body['providerOptions']);
        $this->assertArrayNotHasKey('image', $body);
    }

    public function testCandidateCountMapsToNInBody(): void
    {
        $transporter = new MockHttpTransporter($this->createMockBase64Response());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'candidateCount' => 2,
        ]));

        $model->generateVideoResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame(2, $body['n']);
    }

    public function testAspectRatioIncludedInBody(): void
    {
        $transporter = new MockHttpTransporter($this->createMockBase64Response());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'outputMediaAspectRatio' => '16:9',
        ]));

        $model->generateVideoResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame('16:9', $body['aspectRatio']);
    }

    public function testOrientationPortraitMapsToAspectRatio(): void
    {
        $transporter = new MockHttpTransporter($this->createMockBase64Response());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'outputMediaOrientation' => 'portrait',
        ]));

        $model->generateVideoResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame('9:16', $body['aspectRatio']);
    }

    public function testKnownTopLevelOptionsLiftedFromCustomOptions(): void
    {
        $transporter = new MockHttpTransporter($this->createMockBase64Response());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'customOptions' => [
                'duration' => 5,
                'fps' => 24,
                'resolution' => '1920x1080',
                'seed' => 42,
            ],
        ]));

        $model->generateVideoResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame(5, $body['duration']);
        $this->assertSame(24, $body['fps']);
        $this->assertSame('1920x1080', $body['resolution']);
        $this->assertSame(42, $body['seed']);
        $this->assertInstanceOf(\stdClass::class, $body['providerOptions']);
    }

    public function testNonTopLevelCustomOptionsGoIntoProviderOptions(): void
    {
        $transporter = new MockHttpTransporter($this->createMockBase64Response());
        $model = $this->createModel($transporter);
        $model->setConfig(ModelConfig::fromArray([
            'customOptions' => ['motionStrength' => 0.8],
        ]));

        $model->generateVideoResult($this->createSimplePrompt());

        $body = $transporter->getLastRequest()->getData();
        $this->assertArrayNotHasKey('motionStrength', $body);
        $this->assertSame(['google' => ['motionStrength' => 0.8]], $body['providerOptions']);
    }

    public function testImagePartFromPromptIsSentAsImageField(): void
    {
        $transporter = new MockHttpTransporter($this->createMockBase64Response());
        $model = $this->createModel($transporter);

        $imageBase64 = base64_encode('fake-image-bytes');
        $imageFile = new File($imageBase64, 'image/png');
        $prompt = [
            new Message(
                MessageRoleEnum::user(),
                [
                    new MessagePart('Make this image animate'),
                    new MessagePart($imageFile),
                ]
            ),
        ];

        $model->generateVideoResult($prompt);

        $body = $transporter->getLastRequest()->getData();
        $this->assertSame('Make this image animate', $body['prompt']);
        $this->assertArrayHasKey('image', $body);
        $this->assertSame('file', $body['image']['type']);
        $this->assertSame('image/png', $body['image']['mediaType']);
        $this->assertSame($imageBase64, $body['image']['data']);
    }

    public function testRemoteImagePartFromPromptIsSentAsUrl(): void
    {
        $transporter = new MockHttpTransporter($this->createMockBase64Response());
        $model = $this->createModel($transporter);

        $imageFile = new File('https://example.com/source.png', 'image/png');
        $prompt = [
            new Message(
                MessageRoleEnum::user(),
                [
                    new MessagePart('Animate this'),
                    new MessagePart($imageFile),
                ]
            ),
        ];

        $model->generateVideoResult($prompt);

        $body = $transporter->getLastRequest()->getData();
        $this->assertArrayHasKey('image', $body);
        $this->assertSame('url', $body['image']['type']);
        $this->assertSame('https://example.com/source.png', $body['image']['url']);
    }

    public function testBase64VideoResponseParsing(): void
    {
        $transporter = new MockHttpTransporter($this->createMockBase64Response());
        $model = $this->createModel($transporter);

        $result = $model->generateVideoResult($this->createSimplePrompt());

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $candidates = $result->getCandidates();
        $this->assertCount(1, $candidates);

        $parts = $candidates[0]->getMessage()->getParts();
        $this->assertCount(1, $parts);

        $file = $parts[0]->getFile();
        $this->assertNotNull($file);
        $this->assertTrue($file->isInline());
        $this->assertSame('video/mp4', $file->getMimeType());
    }

    public function testUrlVideoResponseParsing(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            $this->sseBody([
                'type' => 'result',
                'videos' => [
                    [
                        'type' => 'url',
                        'url' => 'https://example.com/generated.mp4',
                        'mediaType' => 'video/mp4',
                    ],
                ],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateVideoResult($this->createSimplePrompt());

        $candidates = $result->getCandidates();
        $this->assertCount(1, $candidates);

        $file = $candidates[0]->getMessage()->getParts()[0]->getFile();
        $this->assertNotNull($file);
        $this->assertTrue($file->isRemote());
        $this->assertSame('https://example.com/generated.mp4', $file->getUrl());
        $this->assertSame('video/mp4', $file->getMimeType());
    }

    public function testMultipleVideosResponseParsing(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            $this->sseBody([
                'type' => 'result',
                'videos' => [
                    ['type' => 'base64', 'data' => base64_encode('v1'), 'mediaType' => 'video/mp4'],
                    ['type' => 'base64', 'data' => base64_encode('v2'), 'mediaType' => 'video/webm'],
                ],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateVideoResult($this->createSimplePrompt());

        $candidates = $result->getCandidates();
        $this->assertCount(2, $candidates);
        $this->assertSame(
            'video/mp4',
            $candidates[0]->getMessage()->getParts()[0]->getFile()->getMimeType()
        );
        $this->assertSame(
            'video/webm',
            $candidates[1]->getMessage()->getParts()[0]->getFile()->getMimeType()
        );
    }

    public function testResponseIdParsedFromGenerationId(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            $this->sseBody([
                'type' => 'result',
                'videos' => [
                    ['type' => 'base64', 'data' => base64_encode('v'), 'mediaType' => 'video/mp4'],
                ],
                'providerMetadata' => [
                    'gateway' => ['generationId' => 'gen-123'],
                ],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateVideoResult($this->createSimplePrompt());

        $this->assertSame('gen-123', $result->getId());
    }

    public function testResponseIdDefaultsToEmptyStringWhenMissing(): void
    {
        $transporter = new MockHttpTransporter($this->createMockBase64Response());
        $model = $this->createModel($transporter);

        $result = $model->generateVideoResult($this->createSimplePrompt());

        $this->assertSame('', $result->getId());
    }

    public function testAdditionalDataExcludesTypeAndVideos(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            $this->sseBody([
                'type' => 'result',
                'videos' => [
                    ['type' => 'base64', 'data' => base64_encode('v'), 'mediaType' => 'video/mp4'],
                ],
                'warnings' => [['type' => 'other', 'message' => 'A warning']],
                'providerMetadata' => [
                    'gateway' => ['generationId' => 'gen'],
                ],
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateVideoResult($this->createSimplePrompt());

        $additional = $result->getAdditionalData();
        $this->assertArrayNotHasKey('type', $additional);
        $this->assertArrayNotHasKey('videos', $additional);
        $this->assertArrayHasKey('warnings', $additional);
        $this->assertArrayHasKey('providerMetadata', $additional);
    }

    public function testErrorEventThrowsResponseException(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            $this->sseBody([
                'type' => 'error',
                'message' => 'Provider quota exceeded',
                'errorType' => 'quota',
                'statusCode' => 429,
                'param' => null,
            ])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('Provider quota exceeded');

        $model->generateVideoResult($this->createSimplePrompt());
    }

    public function testEmptyBodyThrowsResponseException(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            ''
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $this->expectException(ResponseException::class);

        $model->generateVideoResult($this->createSimplePrompt());
    }

    public function testNonDataLineBodyThrowsResponseException(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            "event: ping\nretry: 5000\n\n"
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $this->expectException(ResponseException::class);

        $model->generateVideoResult($this->createSimplePrompt());
    }

    public function testMissingVideosThrowsResponseException(): void
    {
        $response = new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            $this->sseBody(['type' => 'result'])
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('videos');

        $model->generateVideoResult($this->createSimplePrompt());
    }

    public function testCrlfNewlinesAreSupported(): void
    {
        $payload = json_encode([
            'type' => 'result',
            'videos' => [
                ['type' => 'base64', 'data' => base64_encode('v'), 'mediaType' => 'video/mp4'],
            ],
        ]);
        $response = new Response(
            200,
            ['Content-Type' => 'text/event-stream'],
            "data: {$payload}\r\n\r\n"
        );
        $transporter = new MockHttpTransporter($response);
        $model = $this->createModel($transporter);

        $result = $model->generateVideoResult($this->createSimplePrompt());

        $this->assertCount(1, $result->getCandidates());
    }
}
