---
name: use-ai-gateway-provider
description: 'Use the Vercel AI Gateway provider for PHP (the `vercel-labs/ai-gateway-provider` Composer package and the Vercel AI Gateway Provider WordPress plugin) to call generative AI models from PHP. Use when: (1) writing PHP / WordPress code that generates text, images, or video via the AI Gateway, (2) working with the `ai_gateway` provider or the `WordPress/php-ai-client` SDK prompt builder, (3) adding AI features to a WordPress plugin or theme.'
---

# Vercel AI Gateway provider for PHP

A provider for the [`WordPress/php-ai-client`](https://github.com/WordPress/php-ai-client) SDK that routes generative AI requests through the [Vercel AI Gateway](https://vercel.com/ai-gateway). It ships both as a Composer package and as a WordPress plugin.

## Read the bundled docs before writing any code

The package bundles usage documentation that matches the installed version. Read the file for the context you are working in — do not write code against this provider without it.

### Scenario A: If in the context of a WordPress project

Read the docs at `wp-content/plugins/vercel-ai-gateway-provider/docs/wordpress.md`.

If more specific detail knowledge about low-level provider behavior is needed that the docs do not cover, you may inspect the source code in `wp-content/plugins/vercel-ai-gateway-provider/src/`.

If the plugin is not installed yet, install the [Vercel AI Gateway Provider plugin](https://wordpress.org/plugins/vercel-ai-gateway-provider/).

### Scenario B: If in the context of a non-WordPress PHP project

Read the docs at `vendor/vercel-labs/ai-gateway-provider/docs/php.md`.

If more specific detail knowledge about low-level provider behavior is needed that the docs do not cover, you may inspect the source code in `vendor/vercel-labs/ai-gateway-provider/src/`.

If the package is not installed yet, run `composer require vercel-labs/ai-gateway-provider`, then read the docs.

## Do not answer from memory

This provider and the underlying SDK are young and change between releases, and available models change constantly. Whatever you recall about entry points, method names, or model IDs is likely wrong or stale. Ground every API call in the bundled docs, and in the package source when the docs do not cover it.

If neither the docs nor the source support an answer, say so instead of guessing.
