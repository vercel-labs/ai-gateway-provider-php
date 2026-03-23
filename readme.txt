=== Vercel AI Gateway Provider ===

Contributors: vercellabs, flixos90
Tested up to: 7.0
Stable tag:   1.0.0-alpha
License:      MIT
License URI:  https://opensource.org/license/mit
Tags:         ai, vercel, artificial-intelligence, llm, connector

Vercel AI Gateway provider for the PHP AI Client SDK. Works as a Composer package and WordPress plugin.

== Description ==

This plugin provides the Vercel AI Gateway integration for the PHP AI Client SDK. It enables WordPress sites to use hundreds of generative AI models from over 20 different providers, including the popular Claude, Gemini, GPT, and Grok models.

= Features =

* Access models from over 20 providers with just a single API key
* Generate text, images, code, and more, including multi-turn conversations
* Build agents using tool calls
* Smart fallbacks, e.g. when an underlying provider is experiencing temporary downtime
* Comprehensive support for the majority of AI model features included

Available models are dynamically discovered from the AI Gateway API - you get access to new models as soon as they're available.

= Providers and Models =

The AI Gateway gives you access to more than 100 models from over 20 providers, including:

- Amazon (Nova models)
- Anthropic (Claude models)
- Black Forest Labs (Flux models)
- Google (Gemini, Imagen, and Veo models)
- MiniMax (MiniMax models)
- Mistral (Mistral and Devstral models)
- Moonshot AI (Kimi models)
- OpenAI (GPT and Dall-E models)
- Perplexity (Sonar models)
- xAI (Grok models)
- Z.ai (GLM models)

You can access all of these and many more directly through the AI Gateway, drastically simplifying your setup. For a full list of models, [browse the official AI Gateway models list](https://vercel.com/ai-gateway/models).

= External Services =

This plugin connects to the AI Gateway API for inference.

See the [Vercel AI Product Terms](https://vercel.com/legal/ai-product-terms) and the [Vercel Privacy Policy](https://vercel.com/legal/privacy-policy).

== Installation ==

= Installation from within WordPress =

1. Visit **Plugins > Add New**.
2. Search for **Vercel AI Gateway**, then install and activate the AI Gateway Provider plugin.
3. Visit **Settings > Connectors** and paste your AI Gateway API key.

= Manual installation =

1. Upload the entire `vercel-ai-gateway-provider` folder to the `/wp-content/plugins/` directory.
2. Visit **Plugins**, and activate the Vercel AI Gateway Provider plugin.
3. Visit **Settings > Connectors** and paste your AI Gateway API key.

== Frequently Asked Questions ==

= How do I get an AI Gateway API key? =

[Get an AI Gateway API key here.](https://vercel.com/d?to=%2F%5Bteam%5D%2F%7E%2Fai%2Fapi-keys&title=Get%20AI%20Gateway%20API%20key%20for%20your%20WordPress%20site)

== Changelog ==

= 1.0.0 =

* Initial release
