# AI Gateway Provider (PHP) - AGENTS.md

This project is a PHP provider package for the [Vercel AI Gateway](https://vercel.com/ai-gateway), built on top of the [`wordpress/php-ai-client` SDK](https://github.com/WordPress/php-ai-client). It registers as a single provider (`ai_gateway`) that proxies generative AI requests to any model available through the gateway (Anthropic, Google, OpenAI, xAI, and others).

It works both as a standalone Composer package and as a WordPress plugin. Both are managed in this repository. The WordPress plugin effectively just bundles the underlying package and loads it in a WordPress compatible way.

**Related project:** See the [AI SDK WordPress plugin](https://github.com/vercel-labs/ai-sdk-wp) for a WordPress plugin that makes the AI SDK usable in WordPress client-side code. You can combine the two plugins to use the AI Gateway through the AI SDK in WordPress.

## Critical Code Requirements

- All code must be backward compatible with PHP 7.4. Worth a note: A few PHP 8+ functions are available via polyfills and are therefore safe to use here. See `vendor/wordpress/php-ai-client/src/polyfills.php` for the full list.
- All code in the `src` directory must be WordPress agnostic, i.e. it must work in any PHP project.
- In rare exceptions, it may be necessary to use a WordPress function, e.g. `__()` for translating a user-facing string. If so, this MUST happen conditionally, only if e.g. `function_exists('__')`, and an alternative non-WordPress way has to be included too.

## Model ID Conventions

The AI Gateway is a single "provider" in the PHP AI Client SDK, but it proxies models from many underlying providers (Anthropic, Google, OpenAI, etc.). This creates two kinds of model ID:

- **Gateway model ID** (aka full ID): includes the provider prefix, e.g. `anthropic/claude-sonnet-4.6`, `google/gemini-2.5-flash`. This is what the gateway API expects in the `ai-language-model-id` header.
- **Flat model ID**: the part after the slash, e.g. `claude-sonnet-4.6`, `gemini-2.5-flash`. This is what the PHP AI Client SDK uses and expects (it does not support slashes in model IDs).

`AiGatewayModelMetadataDirectory` strips the provider prefix from `specification.modelId` returned by the `/config` endpoint and stores a mapping (`$gatewayModelIdMap`) so `AiGatewayProvider::createModel()` can resolve flat IDs back to full gateway IDs at request time.

## No Streaming Support

The PHP AI Client SDK does not support streaming yet. Therefore, we are unable to support streaming in this provider. This will only become relevant at some point in the future, once the PHP AI Client SDK adds support for it.

## Workflow Commands

- `composer phpcs` — Run PHP_CodeSniffer to check code style
- `composer phpcbf` — Auto-fix code style issues
- `composer phpstan` — Run PHPStan static analysis
- `composer test` — Alias for `test:unit`
- `composer test:unit` — Run unit tests (fast, no network, no API keys needed)
- `composer test:integration` — Run integration tests against real AI Gateway models

## End-to-End Testing

End-to-end testing against actual AI models can be done via the CLI tool at `tools/cli.php`. The first positional argument is the prompt (use `-` for stdin, `@path` for file input). Named arguments use `--key=value` syntax.

Examples:

```bash
php tools/cli.php 'When was Vercel founded?' --modelId=gemini-3.1-flash-lite-preview
php tools/cli.php 'How many R are in "strawberry"?' --modelId=claude-4-6-sonnet --temperature=0.2
php tools/cli.php 'write a 3-verse kids poem about a Cavalier King Charles Spaniel, accompanied by illustrations' --outputModalities='["text","image"]'
```

The `--outputFormat` argument controls output format: `message-text` (default), `result-json`, `candidates-json`, `image` (saves to `tools/output/`), `image-json`, `image-base64`.

Every property of the `ModelConfig` class from the `wordpress/php-ai-client` package is supported as a named argument. Complex types can be passed as JSON strings. See `vendor/wordpress/php-ai-client/src/Providers/Models/DTO/ModelConfig.php` for the full list of supported properties.
