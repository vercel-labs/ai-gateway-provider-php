# Using the AI Gateway Provider in WordPress

```php
$text = wp_ai_client_prompt( 'Hello, world!' )
    ->using_provider( 'ai_gateway' )
    ->generate_text();
```

Once you have installed the [AI Gateway Provider WordPress plugin](https://wordpress.org/plugins/vercel-ai-gateway-provider/), it registers the AI Gateway as a provider for the WordPress AI Client. The WordPress AI Client wraps the [`WordPress/php-ai-client` SDK](https://github.com/WordPress/php-ai-client) and exposes its prompt builder under the `wp_ai_client_prompt()` entry point, with all methods aliased to snake_case.

To get started, set your API key under **Settings > Connectors**, then use the examples below.

## Selecting a model

```php
$text = wp_ai_client_prompt( 'Explain quantum computing in one paragraph.' )
    ->using_provider( 'ai_gateway' )
    ->using_model_preference( 'claude-sonnet-4.7', 'gemini-3-flash-preview' )
    ->generate_text();
```

## System instruction and generation parameters

```php
$text = wp_ai_client_prompt( 'How many R are in "strawberry"?' )
    ->using_provider( 'ai_gateway' )
    ->using_model_preference( 'claude-sonnet-4.7' )
    ->using_system_instruction( 'You are a careful, precise assistant. Think step by step.' )
    ->using_temperature( 0.2 )
    ->using_max_tokens( 200 )
    ->generate_text();
```

## Multi-turn conversation

```php
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\MessagePart;

$history = [
    new UserMessage( [ new MessagePart( 'My name is Ada.' ) ] ),
    new ModelMessage( [ new MessagePart( 'Nice to meet you, Ada.' ) ] ),
];

$text = wp_ai_client_prompt( 'What did I say my name was?' )
    ->using_provider( 'ai_gateway' )
    ->with_history( ...$history )
    ->generate_text();
```

## Vision (image input)

```php
$text = wp_ai_client_prompt( 'Describe what you see in this image.' )
    ->using_provider( 'ai_gateway' )
    ->using_model_preference( 'gemini-3-flash-preview' )
    ->with_file( 'https://example.com/photo.jpg' )
    ->generate_text();
```

You can also pass a local path, a base64 string, or a data URI to `with_file()`.

## Structured JSON output

```php
$schema = [
    'type'       => 'object',
    'properties' => [
        'title'    => [ 'type' => 'string' ],
        'keywords' => [
            'type'  => 'array',
            'items' => [ 'type' => 'string' ],
        ],
    ],
    'required'   => [ 'title', 'keywords' ],
];

$text = wp_ai_client_prompt( 'Suggest a title and 5 SEO keywords for a post about urban gardening.' )
    ->using_provider( 'ai_gateway' )
    ->as_json_response( $schema )
    ->generate_text();

$data = json_decode( $text, true );
```

## Image generation

```php
$file = wp_ai_client_prompt( 'A watercolor painting of a Cavalier King Charles Spaniel in a sunlit garden.' )
    ->using_provider( 'ai_gateway' )
    ->using_model_preference( 'gpt-image-2' )
    ->as_output_media_aspect_ratio( '16:9' )
    ->generate_image();

file_put_contents( __DIR__ . '/spaniel.png', base64_decode( $file->getBase64Data() ) );
```

### Image editing

```php
$input_data_uri = 'data:image/png;base64,' . base64_encode( file_get_contents( __DIR__ . '/spaniel.png' ) );

$file = wp_ai_client_prompt( 'Repaint this scene at sunset, keeping the dog and pose unchanged.' )
    ->using_provider( 'ai_gateway' )
    ->using_model_preference( 'gemini-3.1-flash-image-preview' )
    ->with_file( $input_data_uri )
    ->as_output_media_aspect_ratio( '16:9' )
    ->generate_image();

file_put_contents( __DIR__ . '/spaniel-sunset.png', base64_decode( $file->getBase64Data() ) );
```

## Video generation

```php
$file = wp_ai_client_prompt( 'A drone shot flying over a misty mountain range at sunrise.' )
    ->using_provider( 'ai_gateway' )
    ->using_model_preference( 'grok-imagine-video' )
    ->generate_video();
```

## Multi-modal output (text + image)

```php
use WordPress\AiClient\Messages\Enums\ModalityEnum;

$result = wp_ai_client_prompt( 'Write a 3-verse kids poem about a Cavalier King Charles Spaniel, accompanied by illustrations.' )
    ->using_provider( 'ai_gateway' )
    ->as_output_modalities( ModalityEnum::text(), ModalityEnum::image() )
    ->generate_result();
```
