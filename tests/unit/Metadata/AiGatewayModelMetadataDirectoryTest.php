<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use Vercel\AiGatewayProvider\Authentication\AiGatewayRequestAuthentication;
use Vercel\AiGatewayProvider\Metadata\AiGatewayModelMetadataDirectory;
use Vercel\AiGatewayProvider\Tests\Mocks\MockHttpTransporter;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

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

    public function testGeminiImageModelGetsExpandedOutputModalities(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([
            $this->makeLanguageModel([
                'id' => 'google/gemini-2.5-flash-preview-image',
                'name' => 'Gemini 2.5 Flash Preview Image',
                'specification' => ['modelId' => 'google/gemini-2.5-flash-preview-image'],
            ]),
        ]));

        $models = $directory->listModelMetadata();

        $this->assertCount(1, $models);
        $outputModalitiesOption = $this->findOutputModalitiesOption($models[0]);
        $this->assertNotNull($outputModalitiesOption);

        $values = $outputModalitiesOption->getSupportedValues();
        $this->assertCount(3, $values);
        $this->assertTrue($outputModalitiesOption->isSupportedValue([ModalityEnum::text()]));
        $this->assertTrue($outputModalitiesOption->isSupportedValue([ModalityEnum::image()]));
        $this->assertTrue($outputModalitiesOption->isSupportedValue([ModalityEnum::image(), ModalityEnum::text()]));
    }

    public function testGeminiImageModelWithMiddlePatternGetsExpandedOutputModalities(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([
            $this->makeLanguageModel([
                'id' => 'google/gemini-2.0-flash-image-generation',
                'name' => 'Gemini 2.0 Flash Image Generation',
                'specification' => ['modelId' => 'google/gemini-2.0-flash-image-generation'],
            ]),
        ]));

        $models = $directory->listModelMetadata();

        $this->assertCount(1, $models);
        $outputModalitiesOption = $this->findOutputModalitiesOption($models[0]);
        $this->assertNotNull($outputModalitiesOption);

        $values = $outputModalitiesOption->getSupportedValues();
        $this->assertCount(3, $values);
    }

    public function testRegularGeminiModelKeepsTextOnlyOutputModalities(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([
            $this->makeLanguageModel([
                'id' => 'google/gemini-2.0-flash',
                'name' => 'Gemini 2.0 Flash',
                'specification' => ['modelId' => 'google/gemini-2.0-flash'],
            ]),
        ]));

        $models = $directory->listModelMetadata();

        $this->assertCount(1, $models);
        $outputModalitiesOption = $this->findOutputModalitiesOption($models[0]);
        $this->assertNotNull($outputModalitiesOption);

        $values = $outputModalitiesOption->getSupportedValues();
        $this->assertCount(1, $values);
        $this->assertTrue($outputModalitiesOption->isSupportedValue([ModalityEnum::text()]));
        $this->assertFalse($outputModalitiesOption->isSupportedValue([ModalityEnum::image()]));
    }

    public function testNonGoogleModelKeepsTextOnlyOutputModalities(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([
            $this->makeLanguageModel([
                'id' => 'anthropic/claude-sonnet-4-6',
                'specification' => ['modelId' => 'anthropic/claude-sonnet-4-6'],
            ]),
        ]));

        $models = $directory->listModelMetadata();

        $this->assertCount(1, $models);
        $outputModalitiesOption = $this->findOutputModalitiesOption($models[0]);
        $this->assertNotNull($outputModalitiesOption);

        $values = $outputModalitiesOption->getSupportedValues();
        $this->assertCount(1, $values);
        $this->assertTrue($outputModalitiesOption->isSupportedValue([ModalityEnum::text()]));
    }

    public function testGeminiImageModelGetsBothCapabilities(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([
            $this->makeLanguageModel([
                'id' => 'google/gemini-2.5-flash-preview-image',
                'name' => 'Gemini 2.5 Flash Preview Image',
                'specification' => ['modelId' => 'google/gemini-2.5-flash-preview-image'],
            ]),
        ]));

        $models = $directory->listModelMetadata();

        $this->assertCount(1, $models);
        $capabilities = $models[0]->getSupportedCapabilities();
        $this->assertCount(2, $capabilities);

        $capabilityValues = array_map(function ($c) {
            return $c->value;
        }, $capabilities);
        $this->assertContains(CapabilityEnum::textGeneration()->value, $capabilityValues);
        $this->assertContains(CapabilityEnum::imageGeneration()->value, $capabilityValues);
    }

    public function testGeminiImageModelGetsImageSpecificOptions(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([
            $this->makeLanguageModel([
                'id' => 'google/gemini-2.5-flash-preview-image',
                'name' => 'Gemini 2.5 Flash Preview Image',
                'specification' => ['modelId' => 'google/gemini-2.5-flash-preview-image'],
            ]),
        ]));

        $models = $directory->listModelMetadata();

        $this->assertCount(1, $models);
        $optionNames = array_map(function (SupportedOption $option) {
            return $option->getName()->value;
        }, $models[0]->getSupportedOptions());

        $this->assertContains(OptionEnum::candidateCount()->value, $optionNames);
        $this->assertContains(OptionEnum::outputFileType()->value, $optionNames);
        $this->assertContains(OptionEnum::outputMediaOrientation()->value, $optionNames);
        $this->assertContains(OptionEnum::outputMediaAspectRatio()->value, $optionNames);
    }

    public function testRegularLanguageModelHasOnlyTextGenerationCapability(): void
    {
        $directory = $this->createDirectory($this->createConfigResponse([
            $this->makeLanguageModel([
                'id' => 'anthropic/claude-sonnet-4-6',
                'specification' => ['modelId' => 'anthropic/claude-sonnet-4-6'],
            ]),
        ]));

        $models = $directory->listModelMetadata();

        $this->assertCount(1, $models);
        $capabilities = $models[0]->getSupportedCapabilities();
        $this->assertCount(1, $capabilities);
        $this->assertTrue($capabilities[0]->isTextGeneration());
    }

    private function findOutputModalitiesOption(ModelMetadata $model): ?SupportedOption
    {
        foreach ($model->getSupportedOptions() as $option) {
            if ($option->getName()->value === OptionEnum::outputModalities()->value) {
                return $option;
            }
        }
        return null;
    }
}
