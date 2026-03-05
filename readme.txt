=== AI Gateway Provider ===

Contributors: wordpressdotorg
Tested up to: 7.0
Stable tag:   1.0.0
License:      MIT
License URI:  https://opensource.org/license/mit
Tags:         ai, gateway, vercel, artificial-intelligence, llm, connector

Vercel AI Gateway provider for the PHP AI Client SDK. Works as both a Composer package and WordPress plugin.

== Description ==

This plugin provides the Vercel AI Gateway integration for the PHP AI Client SDK. It enables WordPress sites to use hundreds of generative AI models from over 30 different providers, including the popular Claude, Gemini, GPT, and Grok models.

**Features:**

* Access models from over 30 providers with just a single API key
* Generate text, images, code, and more, including multi-turn conversations
* Build agents using tool calls
* Comprehensive support for the majority of AI model features included

Available models are dynamically discovered from the AI Gateway API.

== Installation ==

= Installation from within WordPress =

1. Visit **Plugins > Add New**.
2. Search for **AI Gateway**, then install and activate the AI Gateway Provider plugin.
3. Visit **Settings > Connectors** and paste your AI Gateway API key.

= Manual installation =

1. Upload the entire `ai-gateway-provider` folder to the `/wp-content/plugins/` directory.
2. Visit **Plugins**, and activate the AI Gateway Provider plugin.
3. Visit **Settings > Connectors** and paste your AI Gateway API key.

== Frequently Asked Questions ==

= How do I get an AI Gateway API key? =

[Get an AI Gateway API key here.](https://vercel.com/d?to=%2F%5Bteam%5D%2F%7E%2Fai%2Fapi-keys&title=Get%20AI%20Gateway%20API%20Key)

== Changelog ==

= 1.0.0 =

* Initial release
