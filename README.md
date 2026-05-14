# Vercel AI Gateway Provider

Vercel AI Gateway provider for the [PHP AI Client](https://github.com/WordPress/php-ai-client) SDK. Works as a [Composer package](https://packagist.org/packages/vercel-labs/ai-gateway-provider) and [WordPress plugin](https://wordpress.org/plugins/vercel-ai-gateway-provider/).

The Vercel AI Gateway allows you to use hundreds of generative AI models from over 20 different providers, to generate text, images, video, and more.

## Installation

### As a Composer Package

Make sure you're using `WordPress/php-ai-client` 1.3.0 or higher.

```bash
composer require vercel-labs/ai-gateway-provider
```

### As a WordPress Plugin

> **This plugin requires WordPress 7.0.**
>
> Since WordPress 7.0 is not yet released, you can currently test this plugin by using the [latest WordPress 7.0 RC](https://wordpress.org/news/2026/05/wordpress-7-0-release-candidate-4/).
> Once WordPress 7.0 stable is out (scheduled for May 20, 2026), you can use it with the regular release.

Make sure you're using WordPress 7.0 or higher.

#### Installation from within WordPress

1. Visit **Plugins > Add New**.
2. Search for **Vercel AI Gateway**, then install and activate the AI Gateway Provider plugin.
3. Visit **Settings > Connectors** and paste your AI Gateway API key.

#### Manual installation

1. Upload the entire `vercel-ai-gateway-provider` folder to the `/wp-content/plugins/` directory.
2. Visit **Plugins**, and activate the Vercel AI Gateway Provider plugin.
3. Visit **Settings > Connectors** and paste your AI Gateway API key.

## Usage

### With WordPress

The provider automatically registers itself with the PHP AI Client on the `init` hook. Simply configure the API key under **Settings > Connectors**, and you can start using all the models via the AI Gateway.

```php
// Use the provider
$result = wp_ai_client_prompt( 'Hello, world!' )
    ->using_provider( 'ai_gateway' )
    ->generate_text_result();
```

See [docs/wordpress.md](docs/wordpress.md) for more examples.

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

See [docs/php.md](docs/php.md) for more examples.

## License

MIT
