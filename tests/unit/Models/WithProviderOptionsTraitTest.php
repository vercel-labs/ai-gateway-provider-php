<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use Vercel\AiGatewayProvider\Models\WithProviderOptionsTrait;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;

class WithProviderOptionsTraitTest extends TestCase
{
    private function createInstance(string $gatewayModelId = 'anthropic/claude-sonnet-4-6'): object
    {
        return new class ($gatewayModelId) {
            use WithProviderOptionsTrait;

            private string $gatewayModelId;

            public function __construct(string $gatewayModelId)
            {
                $this->gatewayModelId = $gatewayModelId;
            }

            protected function getGatewayModelId(): string
            {
                return $this->gatewayModelId;
            }

            /**
             * @param array<string, mixed> $requestBody
             * @param array<string, mixed> $customOptions
             * @param list<string>         $knownTopLevelOptions
             * @return array<string, mixed>
             */
            public function callAmendProviderOptions(
                array $requestBody,
                array $customOptions,
                array $knownTopLevelOptions = []
            ): array {
                return $this->amendProviderOptions($requestBody, $customOptions, $knownTopLevelOptions);
            }
        };
    }

    public function testEmptyCustomOptionsReturnsUnchanged(): void
    {
        $instance = $this->createInstance();
        $requestBody = [
            'prompt' => 'hello',
            'providerOptions' => ['anthropic' => ['thinking' => true]],
        ];

        $result = $instance->callAmendProviderOptions($requestBody, []);

        $this->assertSame('hello', $result['prompt']);
        $this->assertSame(
            ['anthropic' => ['thinking' => true]],
            $result['providerOptions']
        );
    }

    public function testKnownTopLevelOptionsGoToRequestBody(): void
    {
        $instance = $this->createInstance('openai/gpt-image-1');

        $result = $instance->callAmendProviderOptions(
            ['prompt' => 'hello'],
            ['seed' => 42, 'quality' => 'hd'],
            ['seed']
        );

        $this->assertSame(42, $result['seed']);
        $this->assertSame(['openai' => ['quality' => 'hd']], $result['providerOptions']);
    }

    public function testProviderOptionsKeyMerges(): void
    {
        $instance = $this->createInstance();

        $result = $instance->callAmendProviderOptions(
            [],
            [
                'providerOptions' => [
                    'anthropic' => ['thinking' => true],
                    'gateway' => ['cacheControl' => true],
                ],
            ]
        );

        $this->assertSame(['thinking' => true], $result['providerOptions']['anthropic']);
        $this->assertSame(['cacheControl' => true], $result['providerOptions']['gateway']);
    }

    public function testProviderOptionsKeyConflictThrows(): void
    {
        $instance = $this->createInstance();

        $this->expectException(InvalidArgumentException::class);
        $instance->callAmendProviderOptions(
            ['providerOptions' => ['someKey' => 'existing']],
            ['providerOptions' => ['someKey' => 'new']]
        );
    }

    public function testProviderOptionsKeyWithProviderSubKeyDeepMerge(): void
    {
        $instance = $this->createInstance();

        $result = $instance->callAmendProviderOptions(
            ['providerOptions' => ['anthropic' => ['thinking' => true]]],
            [
                'providerOptions' => [
                    'anthropic' => ['maxBudget' => 500],
                ],
            ]
        );

        $this->assertSame(
            ['thinking' => true, 'maxBudget' => 500],
            $result['providerOptions']['anthropic']
        );
    }

    public function testProviderOptionsKeyWithProviderSubKeyConflictThrows(): void
    {
        $instance = $this->createInstance();

        $this->expectException(InvalidArgumentException::class);
        $instance->callAmendProviderOptions(
            ['providerOptions' => ['anthropic' => ['thinking' => true]]],
            [
                'providerOptions' => [
                    'anthropic' => ['thinking' => false],
                ],
            ]
        );
    }

    public function testProviderNameKeyNestsInProviderOptions(): void
    {
        $instance = $this->createInstance();

        $result = $instance->callAmendProviderOptions(
            [],
            ['anthropic' => ['thinking' => true, 'maxBudget' => 500]]
        );

        $this->assertSame(
            ['anthropic' => ['thinking' => true, 'maxBudget' => 500]],
            $result['providerOptions']
        );
    }

    public function testProviderNameKeyMergesWithExisting(): void
    {
        $instance = $this->createInstance();

        $result = $instance->callAmendProviderOptions(
            ['providerOptions' => ['anthropic' => ['responseModalities' => ['TEXT']]]],
            ['anthropic' => ['thinking' => true]]
        );

        $this->assertSame(
            ['anthropic' => ['responseModalities' => ['TEXT'], 'thinking' => true]],
            $result['providerOptions']
        );
    }

    public function testProviderNameKeyConflictThrows(): void
    {
        $instance = $this->createInstance();

        $this->expectException(InvalidArgumentException::class);
        $instance->callAmendProviderOptions(
            ['providerOptions' => ['anthropic' => ['thinking' => true]]],
            ['anthropic' => ['thinking' => false]]
        );
    }

    public function testGatewayKeyNestsInProviderOptions(): void
    {
        $instance = $this->createInstance();

        $result = $instance->callAmendProviderOptions(
            [],
            ['gateway' => ['cacheControl' => true]]
        );

        $this->assertSame(
            ['gateway' => ['cacheControl' => true]],
            $result['providerOptions']
        );
    }

    public function testGatewayKeyConflictThrows(): void
    {
        $instance = $this->createInstance();

        $this->expectException(InvalidArgumentException::class);
        $instance->callAmendProviderOptions(
            ['providerOptions' => ['gateway' => ['cacheControl' => true]]],
            ['gateway' => ['cacheControl' => false]]
        );
    }

    public function testUnrecognizedKeyNestsUnderProviderName(): void
    {
        $instance = $this->createInstance();

        $result = $instance->callAmendProviderOptions(
            [],
            ['seed' => 42, 'logitBias' => ['hello' => 1.0]]
        );

        $this->assertSame(
            ['anthropic' => ['seed' => 42, 'logitBias' => ['hello' => 1.0]]],
            $result['providerOptions']
        );
    }

    public function testUnrecognizedKeyConflictThrows(): void
    {
        $instance = $this->createInstance();

        $this->expectException(InvalidArgumentException::class);
        $instance->callAmendProviderOptions(
            ['providerOptions' => ['anthropic' => ['seed' => 42]]],
            ['seed' => 99]
        );
    }

    public function testNonArrayProviderNameValueThrows(): void
    {
        $instance = $this->createInstance();

        $this->expectException(InvalidArgumentException::class);
        $instance->callAmendProviderOptions([], ['anthropic' => 'not-an-array']);
    }

    public function testNonArrayGatewayValueThrows(): void
    {
        $instance = $this->createInstance();

        $this->expectException(InvalidArgumentException::class);
        $instance->callAmendProviderOptions([], ['gateway' => 'not-an-array']);
    }

    public function testNonArrayProviderOptionsValueThrows(): void
    {
        $instance = $this->createInstance();

        $this->expectException(InvalidArgumentException::class);
        $instance->callAmendProviderOptions([], ['providerOptions' => 'not-an-array']);
    }

    public function testProviderNameKeyNonArrayExistingThrows(): void
    {
        $instance = $this->createInstance();

        $this->expectException(InvalidArgumentException::class);
        $instance->callAmendProviderOptions(
            ['providerOptions' => ['anthropic' => 'scalar-value']],
            ['anthropic' => ['thinking' => true]]
        );
    }

    public function testEmptyCustomOptionsDefaultsToEmptyObject(): void
    {
        $instance = $this->createInstance();

        $result = $instance->callAmendProviderOptions(['prompt' => 'hello'], []);

        $this->assertSame('hello', $result['prompt']);
        $this->assertInstanceOf(\stdClass::class, $result['providerOptions']);
    }
}
