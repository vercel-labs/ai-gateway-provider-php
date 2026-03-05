<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Traits;

trait IntegrationTestTrait
{
    protected function requireApiKey(string $envVar): void
    {
        $value = getenv($envVar);
        if ($value === false || $value === '') {
            $value = $_ENV[$envVar] ?? '';
        }

        if ($value === '') {
            $this->markTestSkipped(
                sprintf('Skipping: environment variable "%s" is not set.', $envVar)
            );
        }
    }
}
