# AI Gateway Provider

Vercel AI Gateway provider for the [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK. Works as a Composer package and WordPress plugin.

## Installation

### As a Composer Package

```bash
composer require vercel/ai-gateway-provider
```

### As a WordPress Plugin

Make sure you're using WordPress 7.0 or higher.

1. Download the plugin files in an `ai-gateway-provider` folder.
2. Upload the entire `ai-gateway-provider` folder to the `/wp-content/plugins/` directory.
3. Visit **Plugins**, and activate the AI Gateway Provider plugin.
4. Visit **Settings > Connectors** and paste your AI Gateway API key.

## Usage

### With WordPress

The provider automatically registers itself with the PHP AI Client on the `init` hook. Simply configure the API key under **Settings > Connectors**, and you can start using all the models via the AI Gateway.

```php
// Use the provider
$result = wp_ai_client_prompt('Hello, world!')
    ->using_provider('ai_gateway')
    ->generate_text_result();
```

### As a Standalone Package

```php
use WordPress\AiClient\AiClient;
use Vercel\AiGatewayProvider\Provider\AiGatewayProvider;

// Register the provider
$registry = AiClient::defaultRegistry();
$registry->registerProvider(AiGatewayProvider::class);

// Set your API key
putenv('AI_GATEWAY_API_KEY=your-api-key');

// Generate text
$result = AiClient::prompt('Explain quantum computing')
    ->usingProvider('ai_gateway')
    ->generateTextResult();

echo $result->toText();
```

## License

MIT
