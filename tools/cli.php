<?php
/**
 * CLI script for interacting with the AI Gateway Provider.
 *
 * This script allows users to send prompts to the AI and receive responses.
 * It supports named arguments for provider and model selection.
 *
 * Usage:
 *   php tools/cli.php 'When was Vercel founded?' --modelId=gemini-3.1-flash-lite-preview
 *   php tools/cli.php 'How many R are in "strawberry"?' --modelId=claude-4-6-sonnet --temperature=0.2
 *   php tools/cli.php 'write a 3-verse kids poem about a Cavalier King Charles Spaniel, accompanied by illustrations' --outputModalities='["text","image"]'
 *
 * For large prompts (e.g., with images), use stdin or file input:
 *   cat prompt.json | php tools/cli.php - --modelId=gpt-5.3-codex
 *   php tools/cli.php @prompt.json --modelId=claude-4-6-opus
 */

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;
use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use FelixArntz\TerminalImage\TerminalImage;

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Prints the output to stdout.
 *
 * @param string $output The output to print.
 */
function printOutput(string $output): void
{
    echo $output . PHP_EOL;
}

/**
 * Logs an informational message to stderr.
 *
 * @param string $message The message to log.
 */
function logInfo(string $message): void
{
    fwrite(STDERR, '[INFO] ' . $message . PHP_EOL);
}

/**
 * Logs a warning message to stderr.
 *
 * @param string $message The message to log.
 */
function logWarning(string $message): void
{
    fwrite(STDERR, '[WARNING] ' . $message . PHP_EOL);
}

/**
 * Logs an error message to stderr and terminates the script.
 *
 * @param string $message The message to log.
 * @param int    $exit_code The exit code to use.
 */
function logError(string $message, int $exit_code = 1): void
{
    fwrite(STDERR, '[ERROR] ' . $message . PHP_EOL);
    exit($exit_code);
}

/**
 * Saves an image file from an image result to the tools/output directory.
 *
 * @param File $imageFile The file DTO returned by $result->toFile().
 * @return string The absolute path to the saved image file.
 */
function saveImageFile(File $imageFile): string
{
    $base64 = $imageFile->getBase64Data();
    if (!$base64) {
        logError('Image result does not contain inline base64 data.');
    }

    $extension = $imageFile->getMimeTypeObject()->toExtension();

    $outputDir = __DIR__ . '/output';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    $now = microtime(true);
    $filename = 'image-' . date('Y-m-d-His', (int) $now) . '-' . sprintf('%03d', ($now - floor($now)) * 1000) . '.' . $extension;
    $outputPath = $outputDir . '/' . $filename;
    $imageBuffer = base64_decode($base64);
    $bytes = file_put_contents($outputPath, $imageBuffer);
    if ($bytes === false) {
        logError("Failed to write image to {$outputPath}.");
    }

    printOutput(TerminalImage::buffer($imageBuffer));

    return $outputPath;
}

/**
 * Saves a video file from a video result to the tools/output directory, or returns the remote URL.
 *
 * @param File $videoFile The file DTO returned by $result->toFile().
 * @return string The absolute path to the saved video file, or the remote URL if the file is remote.
 */
function saveVideoFile(File $videoFile): string
{
    if ($videoFile->isRemote()) {
        $url = $videoFile->getUrl();
        if (!$url) {
            logError('Video result is marked as remote but does not contain a URL.');
        }
        return $url;
    }

    $base64 = $videoFile->getBase64Data();
    if (!$base64) {
        logError('Video result does not contain inline base64 data.');
    }

    $extension = $videoFile->getMimeTypeObject()->toExtension();

    $outputDir = __DIR__ . '/output';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
    }

    $now = microtime(true);
    $filename = 'video-' . date('Y-m-d-His', (int) $now) . '-' . sprintf('%03d', ($now - floor($now)) * 1000) . '.' . $extension;
    $outputPath = $outputDir . '/' . $filename;
    $videoBuffer = base64_decode($base64);
    $bytes = file_put_contents($outputPath, $videoBuffer);
    if ($bytes === false) {
        logError("Failed to write video to {$outputPath}.");
    }

    return $outputPath;
}

// Read .env file for credentials.
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $dotenv = new Dotenv();
    // Enable putenv() so getenv() works (used by ProviderRegistry for API keys).
    $dotenv->usePutenv(true);
    $dotenv->load($envFile);
}

// Register the AI Gateway provider.
$registry = AiClient::defaultRegistry();
if (!$registry->hasProvider(AiGatewayProvider::class)) {
    $registry->registerProvider(AiGatewayProvider::class);
}

// --- Argument parsing ---

$positional_args = [];
$named_args      = [];

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if (str_starts_with($arg, '--')) {
        $parts = explode('=', substr($arg, 2), 2);
        $key   = $parts[0];
        $value = $parts[1] ?? true;
        if (empty($key)) {
            logWarning("Ignoring invalid named argument: {$arg}");
            continue;
        }
        $named_args[$key] = $value;
    } else {
        $positional_args[] = $arg;
    }
}

// --- Input validation ---

if (empty($positional_args[0])) {
    logError('Missing required positional argument "prompt input".');
}

// Prompt input. Allow complex input as a JSON string.
// Use "-" to read from stdin, or "@/path/to/file" to read from a file.
$promptInput = $positional_args[0];
if ($promptInput === '-') {
    $promptInput = file_get_contents('php://stdin');
    if ($promptInput === false) {
        logError('Failed to read prompt from stdin.');
    }
} elseif (str_starts_with($promptInput, '@')) {
    $filePath = substr($promptInput, 1);
    if (!file_exists($filePath)) {
        logError("Prompt file not found: {$filePath}");
    }
    $promptInput = file_get_contents($filePath);
    if ($promptInput === false) {
        logError("Failed to read prompt from file: {$filePath}");
    }
}
if (str_starts_with($promptInput, '{') || str_starts_with($promptInput, '[')) {
    $decodedInput = json_decode($promptInput, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $promptInput = $decodedInput;
    }
}

// Provider ID, model ID, and output format.
$providerId = $named_args['providerId'] ?? 'ai_gateway';
$modelId = $named_args['modelId'] ?? null;
$modelPreference = $named_args['modelPreference'] ?? null;
$outputFormat = $named_args['outputFormat'] ?? 'message-text';

// Any model configuration options.
$schema = ModelConfig::getJsonSchema()['properties'];
$model_config_data = [];
foreach ($named_args as $key => $value) {
    if (!isset($schema[$key])) {
        continue;
    }

    $property_schema = $schema[$key];
    $type = $property_schema['type'] ?? null;

    $processed_value = $value;
    if ($type === 'array' || $type === 'object') {
        $decoded = json_decode((string) $value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            logWarning("Invalid JSON for argument --{$key}: " . json_last_error_msg());
            continue;
        }
        $processed_value = $decoded;
    } elseif ($type === 'integer') {
        $processed_value = (int) $value;
    } elseif ($type === 'number') {
        $processed_value = (float) $value;
    } elseif ($type === 'boolean') {
        $processed_value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if (null === $processed_value) {
            logWarning("Invalid boolean for argument --{$key}: {$value}");
            continue;
        }
    }

    $model_config_data[$key] = $processed_value;
}

// --- Main logic ---

try {
    $modelConfig = ModelConfig::fromArray($model_config_data);

    $promptBuilder = AiClient::prompt($promptInput);
    $promptBuilder = $promptBuilder->usingModelConfig($modelConfig);
    if ($providerId && $modelId) {
        $providerClassName = AiClient::defaultRegistry()->getProviderClassName($providerId);
        $promptBuilder = $promptBuilder->usingModel($providerClassName::model($modelId));
    } elseif ($providerId) {
        $promptBuilder = $promptBuilder->usingProvider($providerId);
    }
    if ($modelPreference) {
        $modelPreference = array_map(
            static function ($item) {
                $item = trim($item);
                if (str_contains($item, '::')) {
                    return explode('::', $item, 2);
                }
                return $item;
            },
            explode(',', $modelPreference)
        );
        $promptBuilder = $promptBuilder->usingModelPreference(...$modelPreference);
    }
} catch (InvalidArgumentException $e) {
    logError('Invalid arguments while trying to set up prompt builder: ' . $e->getMessage());
} catch (ResponseException $e) {
    logError('Request failed while trying to set up prompt builder: ' . $e->getMessage());
}

try {
    if ($outputFormat === 'image' || $outputFormat === 'image-json' || $outputFormat === 'image-base64') {
        $result = $promptBuilder->generateImageResult();
    } elseif ($outputFormat === 'video' || $outputFormat === 'video-json' || $outputFormat === 'video-base64') {
        $result = $promptBuilder->generateVideoResult();
    } else {
        $result = $promptBuilder->generateTextResult();
    }
} catch (InvalidArgumentException $e) {
    logError('Invalid arguments while trying to generate text result: ' . $e->getMessage());
} catch (ResponseException $e) {
    logError('Request failed while trying to generate text result: ' . $e->getMessage());
}

logInfo("Using provider ID: \"{$result->getProviderMetadata()->getId()}\"");
logInfo("Using model ID: \"{$result->getModelMetadata()->getId()}\"");

switch ($outputFormat) {
    case 'result-json':
        printOutput(json_encode($result, JSON_PRETTY_PRINT));
        break;
    case 'candidates-json':
        printOutput(json_encode($result->getCandidates(), JSON_PRETTY_PRINT));
        break;
    case 'image':
        $imageFilePath = saveImageFile($result->toFile());
        logInfo("Image saved to: {$imageFilePath}");
        break;
    case 'image-json':
        printOutput(json_encode($result->toFile(), JSON_PRETTY_PRINT));
        break;
    case 'image-base64':
        printOutput($result->toFile()->getBase64Data());
        break;
    case 'video':
        $videoFile = $result->toFile();
        $videoLocation = saveVideoFile($videoFile);
        if ($videoFile->isRemote()) {
            logInfo("Video URL: {$videoLocation}");
        } else {
            logInfo("Video saved to: {$videoLocation}");
        }
        break;
    case 'video-json':
        printOutput(json_encode($result->toFile(), JSON_PRETTY_PRINT));
        break;
    case 'video-base64':
        printOutput($result->toFile()->getBase64Data());
        break;
    case 'message-text':
    default:
        $message = $result->toMessage();
        foreach ($message->getParts() as $part) {
            $channel = $part->getChannel();
            if ($channel->isThought()) {
                continue;
            }
            $text = $part->getText();
            if ($text !== null) {
                printOutput($text);
            }
            $file = $part->getFile();
            if ($file !== null) {
                $filePath = saveImageFile($file);
                logInfo("File part saved to: {$filePath}");
            }
        }
}
