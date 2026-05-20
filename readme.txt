=== Vercel AI Gateway Provider ===

Contributors: vercellabs, flixos90
Tested up to: 7.0
Stable tag:   1.0.0-RC.1
License:      MIT
License URI:  https://opensource.org/license/mit
Tags:         ai, vercel, artificial-intelligence, llm, connector

The Vercel AI Gateway connector offers access to hundreds of text, image, and video AI models through 1 API key.

== Description ==

This plugin allows your WordPress site to connect to the Vercel AI Gateway. It enables your WordPress site to use hundreds of generative AI models from over 40 different providers, to generate text, images, video, and more.

The Vercel AI Gateway connector is built on top of WordPress's built-in AI client and integrates seamlessly with its connector API. This way it unlocks using AI powered plugins on your site.

= Features =

* Access hundreds of models from over 40 providers with 1 API key
* Generate text, images, video, and more, including multi-turn conversations
* Unified billing and observability across your entire AI stack, with text, image, and video models
* Automatic fallbacks during provider outages so your app stays up even when a model goes down
* Pay exactly what providers charge with no platform fees

Available models are dynamically discovered from the AI Gateway API - you get access to new models as soon as they're available.

= Providers and Models =

The AI Gateway gives you access to more than 100 models from over 40 providers, including:

- Amazon (Nova models)
- Anthropic (Claude models)
- Black Forest Labs (Flux models)
- Google (Gemini, Imagen, and Veo models)
- KlingAI (Kling models)
- MiniMax (MiniMax models)
- Mistral (Mistral and Devstral models)
- Moonshot AI (Kimi models)
- OpenAI (GPT and Dall-E models)
- Perplexity (Sonar models)
- xAI (Grok models)
- Z.ai (GLM models)

You can access all of these and many more directly through the AI Gateway, drastically simplifying your setup. For a full list of models, [browse the official AI Gateway models list](https://vercel.com/ai-gateway/models).

= Usage =

Once you install the Vercel AI Gateway Provider plugin on your WordPress site, any AI feature that uses the WordPress built-in AI client can use it, typically through other plugins.

If you want to write your own plugin that uses it, you can do so through the WordPress built-in AI client as well. Here's a code example:

`
$result = wp_ai_client_prompt( 'Hello, world!' )
    ->using_provider( 'ai_gateway' )
    ->generate_text_result();
`

Note however, that the usage of `using_provider` will make the prompt only work if the individual WordPress site has the Vercel AI Gateway Provider plugin active and configured. You should therefore only use it if you want to enforce usage of the AI Gateway.

Otherwise, for broader ecosystem compatibility, it is recommended that you don't specify a provider. You can optionally specify model preferences, for example like this:

`
$result = wp_ai_client_prompt( 'Hello, world!' )
    ->using_model_preference( 'claude-opus-4.7', 'gemini-3.1-pro-preview', 'gpt-5.4' )
    ->generate_text_result();
`

In this case, the first relevant model encountered on the site will be used, and if the site has the Vercel AI Gateway Provider plugin active and configured, it will rely on the AI Gateway.

See [the official docs](https://vercel.com/docs/ai-gateway/ecosystem/framework-integrations/wordpress) for more examples.

= External Services =

This plugin connects to the AI Gateway API for inference.

See the [Vercel AI Product Terms](https://vercel.com/legal/ai-product-terms) and the [Vercel Privacy Policy](https://vercel.com/legal/privacy-policy).

= Contributing =

Contributions to the plugin are welcome in the project's [GitHub repository](https://github.com/vercel-labs/ai-gateway-provider-php).

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

= Where should I submit my support request? =

For regular support requests, please use the [wordpress.org support forums](https://wordpress.org/support/plugin/vercel-ai-gateway-provider). If you have a technical issue with the plugin where you already have more insight on how to fix it, you can also [open an issue on GitHub instead](https://github.com/vercel-labs/ai-gateway-provider-php/issues).

= How can I contribute to the plugin? =

If you have ideas to improve the plugin or to solve a bug, feel free to raise an issue or submit a pull request in the [GitHub repository for the plugin](https://github.com/vercel-labs/ai-gateway-provider-php).

You can also contribute to the plugin by translating it. Simply visit [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/vercel-ai-gateway-provider) to get started.

== Changelog ==

See the [GitHub releases page](https://github.com/vercel-labs/ai-gateway-provider-php/releases).
