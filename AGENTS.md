# AGENTS.md

Instructions for AI coding agents working with this codebase.

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
