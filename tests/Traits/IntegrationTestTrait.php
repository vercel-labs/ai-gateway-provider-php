<?php

declare(strict_types=1);

namespace Vercel\AiGatewayProvider\Tests\Traits;

use WordPress\AiClient\Files\DTO\File;

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

    /**
     * Saves a generated file to the tests/output directory.
     *
     * @param File   $file   The file DTO containing inline base64 data.
     * @param string $prefix Filename prefix (e.g. "basic-generation").
     * @return string The absolute path to the saved file.
     */
    protected function saveGeneratedFile(File $file, string $prefix): string
    {
        $base64 = $file->getBase64Data();
        if (!$base64) {
            $this->fail('File does not contain inline base64 data.');
        }

        $extension = $file->getMimeTypeObject()->toExtension();

        $outputDir = dirname(__DIR__) . '/output';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0777, true);
        }

        $now = microtime(true);
        $timestamp = date('Y-m-d-His', (int) $now) . '-' . sprintf('%03d', ($now - floor($now)) * 1000);
        $filename = $prefix . '-' . $timestamp . '.' . $extension;
        $outputPath = $outputDir . '/' . $filename;

        $bytes = file_put_contents($outputPath, base64_decode($base64));
        if ($bytes === false) {
            $this->fail("Failed to write file to {$outputPath}.");
        }

        return $outputPath;
    }
}
