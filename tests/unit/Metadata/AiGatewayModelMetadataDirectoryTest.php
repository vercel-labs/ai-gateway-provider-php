<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use Vercel\AiGatewayProvider\Authentication\AiGatewayRequestAuthentication;
use Vercel\AiGatewayProvider\Metadata\AiGatewayModelMetadataDirectory;
use Vercel\AiGatewayProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Response;

class AiGatewayModelMetadataDirectoryTest extends TestCase
{
    private function createDirectory(Response $response): AiGatewayModelMetadataDirectory
    {
        $directory = new AiGatewayModelMetadataDirectory();
        $directory->setHttpTransporter(new MockHttpTransporter($response));
        $directory->setRequestAuthentication(new ApiKeyRequestAuthentication('test-key'));
        return $directory;
    }

    private function createConfigResponse(array $models): Response
    {
        $body = json_encode(['models' => $models]);
        return new Response(200, ['Content-Type' => ['application/json']], $body);
    }

    private function makeLanguageModel(array $overrides = []): array
    {
        return array_merge(
            [
                'id' => 'anthropic/claude-sonnet-4-6',
                'name' => 'Claude Sonnet 4.6',
                'modelType' => 'language',
                'specification' => ['modelId' => 'anthropic/claude-sonnet-4-6'],
            ],
            $overrides
        );
    }

    private function makeImageModel(array $overrides = []): array
    {
        return array_merge(
            [
                'id' => 'openai/gpt-image-1',
                'name' => 'GPT Image 1',
                'modelType' => 'image',
                'specification' => ['modelId' => 'openai/gpt-image-1'],
            ],
            $overrides
        );
    }

    public function testGetRequestAuthenticationReturnsAiGatewayType(): void
    {
        $directory = $this->createDirectory(
            new Response(200, [], null)
        );

        $auth = $directory->getRequestAuthentication();

        $this->assertInstanceOf(AiGatewayRequestAuthentication::class, $auth);
    }

    public function testListModelMetadataParsesMultipleModels(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([
            $this->makeLanguageModel([
                'id' => 'anthropic/claude-sonnet-4-6',
                'name' => 'Claude Sonnet 4.6',
                'specification' => ['modelId' => 'anthropic/claude-sonnet-4-6'],
            ]),
            $this->makeLanguageModel([
                'id' => 'openai/gpt-4o',
                'name' => 'GPT-4o',
                'specification' => ['modelId' => 'openai/gpt-4o'],
            ]),
        ]));

        $models = $directory->listModelMetadata();

        $this->assertCount(2, $models);
        $ids = array_map(function ($m) {
            return $m->getId();
        }, $models);
        $this->assertContains('claude-sonnet-4-6', $ids);
        $this->assertContains('gpt-4o', $ids);
    }

    public function testListModelMetadataIncludesLanguageAndImageModels(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([
            $this->makeLanguageModel([
                'id' => 'anthropic/claude-sonnet-4-6',
                'specification' => ['modelId' => 'anthropic/claude-sonnet-4-6'],
            ]),
            $this->makeImageModel([
                'id' => 'openai/gpt-image-1',
                'name' => 'GPT Image 1',
                'specification' => ['modelId' => 'openai/gpt-image-1'],
            ]),
        ]));

        $models = $directory->listModelMetadata();

        $this->assertCount(2, $models);
        $ids = array_map(function ($m) {
            return $m->getId();
        }, $models);
        $this->assertContains('claude-sonnet-4-6', $ids);
        $this->assertContains('gpt-image-1', $ids);
    }

    public function testListModelMetadataFiltersUnsupportedModelTypes(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([
            $this->makeLanguageModel([
                'id' => 'anthropic/claude-sonnet-4-6',
                'specification' => ['modelId' => 'anthropic/claude-sonnet-4-6'],
            ]),
            [
                'id' => 'some-provider/video-model',
                'name' => 'Video Model',
                'modelType' => 'video',
                'specification' => ['modelId' => 'some-provider/video-model'],
            ],
        ]));

        $models = $directory->listModelMetadata();

        $this->assertCount(1, $models);
        $this->assertSame('claude-sonnet-4-6', $models[0]->getId());
    }

    public function testListModelMetadataIncludesImageModelsWithCorrectCapability(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([
            $this->makeImageModel([
                'id' => 'openai/gpt-image-1',
                'name' => 'GPT Image 1',
                'specification' => ['modelId' => 'openai/gpt-image-1'],
            ]),
        ]));

        $models = $directory->listModelMetadata();

        $this->assertCount(1, $models);
        $capabilities = $models[0]->getSupportedCapabilities();
        $this->assertCount(1, $capabilities);
        $this->assertTrue($capabilities[0]->isImageGeneration());
    }

    public function testListModelMetadataSkipsModelsWithoutModelId(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([
            $this->makeLanguageModel([
                'id' => 'anthropic/claude-sonnet-4-6',
                'specification' => ['modelId' => 'anthropic/claude-sonnet-4-6'],
            ]),
            [
                'id' => 'broken-model',
                'name' => 'Broken',
                'modelType' => 'language',
                'specification' => [],
            ],
        ]));

        $models = $directory->listModelMetadata();

        $this->assertCount(1, $models);
        $this->assertSame('claude-sonnet-4-6', $models[0]->getId());
    }

    public function testListModelMetadataSortsPriorityProvidersFirst(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([
            $this->makeLanguageModel([
                'id' => 'mistral/mistral-large',
                'name' => 'Mistral Large',
                'specification' => ['modelId' => 'mistral/mistral-large'],
            ]),
            $this->makeLanguageModel([
                'id' => 'anthropic/claude-sonnet-4-6',
                'name' => 'Claude Sonnet 4.6',
                'specification' => ['modelId' => 'anthropic/claude-sonnet-4-6'],
            ]),
            $this->makeLanguageModel([
                'id' => 'xai/grok-3',
                'name' => 'Grok 3',
                'specification' => ['modelId' => 'xai/grok-3'],
            ]),
        ]));

        $models = $directory->listModelMetadata();
        $ids = array_map(function ($m) {
            return $m->getId();
        }, $models);

        $this->assertSame(['claude-sonnet-4-6', 'grok-3', 'mistral-large'], $ids);
    }

    public function testGetGatewayModelIdReturnsCorrectId(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([
            $this->makeLanguageModel([
                'id' => 'anthropic/claude-sonnet-4-6',
                'specification' => ['modelId' => 'anthropic/claude-sonnet-4-6'],
            ]),
        ]));

        $directory->listModelMetadata();

        $this->assertSame(
            'anthropic/claude-sonnet-4-6',
            $directory->getGatewayModelId('claude-sonnet-4-6')
        );
    }

    public function testGetGatewayModelIdThrowsForUnknownModel(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([]));

        $directory->listModelMetadata();

        $this->expectException(InvalidArgumentException::class);
        $directory->getGatewayModelId('nonexistent-model');
    }

    public function testListModelMetadataReturnsEmptyForNullBody(): void
    {
        $directory = $this->createDirectory(
            new Response(200, [], null)
        );

        $models = $directory->listModelMetadata();

        $this->assertSame([], $models);
    }
}
