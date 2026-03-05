# AI Gateway Provider (PHP) - AGENTS.md

This project is a PHP provider package for the [Vercel AI Gateway](https://vercel.com/ai-gateway), built on top of the [`wordpress/php-ai-client` SDK](https://github.com/WordPress/php-ai-client). It registers as a single provider (`ai_gateway`) that proxies generative AI requests to any model available through the gateway (Anthropic, Google, OpenAI, xAI, and others).

It works both as a standalone Composer package and as a WordPress plugin. Both are managed in this repository. The WordPress plugin effectively just bundles the underlying package and loads it in a WordPress compatible way.

## Critical Code Requirements

- All code must be backward compatible with PHP 7.4. Worth a note: A few PHP 8+ functions are available via polyfills and are therefore safe to use here. See `vendor/wordpress/php-ai-client/src/polyfills.php` for the full list.
- All code in the `src` directory must be WordPress agnostic, i.e. it must work in any PHP project.
- In rare exceptions, it may be necessary to use a WordPress function, e.g. `__()` for translating a user-facing string. If so, this MUST happen conditionally, only if e.g. `function_exists('__')`, and an alternative non-WordPress way has to be included too.

## Model ID Conventions

The AI Gateway is a single "provider" in the PHP AI Client SDK, but it proxies models from many underlying providers (Anthropic, Google, OpenAI, etc.). This creates two kinds of model ID:

- **Gateway model ID** (aka full ID): includes the provider prefix, e.g. `anthropic/claude-sonnet-4.6`, `google/gemini-2.5-flash`. This is what the gateway API expects in the `ai-language-model-id` header.
- **Flat model ID**: the part after the slash, e.g. `claude-sonnet-4.6`, `gemini-2.5-flash`. This is what the PHP AI Client SDK uses and expects (it does not support slashes in model IDs).

`AiGatewayModelMetadataDirectory` strips the provider prefix from `specification.modelId` returned by the `/config` endpoint and stores a mapping (`$gatewayModelIdMap`) so `AiGatewayProvider::createModel()` can resolve flat IDs back to full gateway IDs at request time.

## Workflow Commands

- `composer phpcs` — Run PHP_CodeSniffer to check code style
- `composer phpcbf` — Auto-fix code style issues
- `composer phpstan` — Run PHPStan static analysis

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

<!-- opensrc:start -->

## Source Code Reference

Source code for dependencies is available in `opensrc/` for deeper understanding of implementation details.

See `opensrc/sources.json` for the list of available packages and their versions.

Use this source code when you need to understand how a package works internally, not just its types/interface.

### Fetching Additional Source Code

To fetch source code for a package or repository you need to understand, run:

```bash
npx opensrc <package>           # npm package (e.g., npx opensrc zod)
npx opensrc pypi:<package>      # Python package (e.g., npx opensrc pypi:requests)
npx opensrc crates:<package>    # Rust crate (e.g., npx opensrc crates:serde)
npx opensrc <owner>/<repo>      # GitHub repo (e.g., npx opensrc vercel/ai)
```

<!-- opensrc:end -->
