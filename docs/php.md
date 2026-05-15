# Using the AI Gateway Provider in PHP

```php
use WordPress\AiClient\AiClient;
use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;

AiClient::defaultRegistry()->registerProvider(AiGatewayProvider::class);
putenv('AI_GATEWAY_API_KEY=your-api-key');

$text = AiClient::prompt('Hello, world!')
    ->usingProvider('ai_gateway')
    ->generateText();
```

This package is built on top of the [PHP AI Client SDK](https://github.com/WordPress/php-ai-client), a framework-agnostic PHP client for generative AI providers. Once you have installed it from Packagist (`composer require vercel-labs/ai-gateway-provider`), it registers the Vercel AI Gateway as a provider so you can call any model the gateway exposes through the SDK's standard prompt builder. See the [SDK repository](https://github.com/WordPress/php-ai-client) for comprehensive docs on the underlying SDK.

All examples below assume the provider has been registered and `AI_GATEWAY_API_KEY` is set, as shown above.

## Selecting a model

```php
$text = AiClient::prompt('Explain quantum computing in one paragraph.')
    ->usingProvider('ai_gateway')
    ->usingModelPreference('claude-sonnet-4.7', 'gemini-3-flash-preview')
    ->generateText();
```

By default, the provider exposes flat model IDs such as `claude-sonnet-4.7` for compatibility with the PHP AI Client SDK and WordPress environments. If your runtime supports slash-delimited model IDs and you need to distinguish gateway models that share the same flat ID, set `AI_GATEWAY_USE_FULL_MODEL_IDS=true` before the provider metadata is loaded.

## System instruction and generation parameters

```php
$text = AiClient::prompt('How many R are in "strawberry"?')
    ->usingProvider('ai_gateway')
    ->usingModelPreference('claude-sonnet-4.7')
    ->usingSystemInstruction('You are a careful, precise assistant. Think step by step.')
    ->usingTemperature(0.2)
    ->usingMaxTokens(200)
    ->generateText();
```

## Multi-turn conversation

```php
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\MessagePart;

$history = [
    new UserMessage([new MessagePart('My name is Ada.')]),
    new ModelMessage([new MessagePart('Nice to meet you, Ada.')]),
];

$text = AiClient::prompt('What did I say my name was?')
    ->usingProvider('ai_gateway')
    ->withHistory(...$history)
    ->generateText();
```

## Vision (image input)

```php
$text = AiClient::prompt('Describe what you see in this image.')
    ->usingProvider('ai_gateway')
    ->usingModelPreference('gemini-3-flash-preview')
    ->withFile('https://example.com/photo.jpg')
    ->generateText();
```

You can also pass a local path, a base64 string, or a data URI to `withFile()`.

## Structured JSON output

```php
$schema = [
    'type' => 'object',
    'properties' => [
        'title' => ['type' => 'string'],
        'keywords' => [
            'type' => 'array',
            'items' => ['type' => 'string'],
        ],
    ],
    'required' => ['title', 'keywords'],
];

$text = AiClient::prompt('Suggest a title and 5 SEO keywords for a post about urban gardening.')
    ->usingProvider('ai_gateway')
    ->asJsonResponse($schema)
    ->generateText();

$data = json_decode($text, true);
```

## Image generation

```php
$file = AiClient::prompt('A watercolor painting of a Cavalier King Charles Spaniel in a sunlit garden.')
    ->usingProvider('ai_gateway')
    ->usingModelPreference('gpt-image-2')
    ->asOutputMediaAspectRatio('16:9')
    ->generateImage();

file_put_contents(__DIR__ . '/spaniel.png', base64_decode($file->getBase64Data()));
```

### Image editing

```php
$inputDataUri = 'data:image/png;base64,' . base64_encode(file_get_contents(__DIR__ . '/spaniel.png'));

$file = AiClient::prompt('Repaint this scene at sunset, keeping the dog and pose unchanged.')
    ->usingProvider('ai_gateway')
    ->usingModelPreference('gemini-3.1-flash-image-preview')
    ->withFile($inputDataUri)
    ->asOutputMediaAspectRatio('16:9')
    ->generateImage();

file_put_contents(__DIR__ . '/spaniel-sunset.png', base64_decode($file->getBase64Data()));
```

## Video generation

```php
$file = AiClient::prompt('A drone shot flying over a misty mountain range at sunrise.')
    ->usingProvider('ai_gateway')
    ->usingModelPreference('grok-imagine-video')
    ->generateVideo();
```

## Multi-modal output (text + image)

```php
use WordPress\AiClient\Messages\Enums\ModalityEnum;

$result = AiClient::prompt('Write a 3-verse kids poem about a Cavalier King Charles Spaniel, accompanied by illustrations.')
    ->usingProvider('ai_gateway')
    ->asOutputModalities(ModalityEnum::text(), ModalityEnum::image())
    ->generateResult();
```
