=== FeedAgentX – ChatGPT Business Hub & Ads Product Feed and Pixel for WooCommerce ===
Contributors: feedagentx
Tags: chatgpt, chatgpt ads, woocommerce product feed, openai, agentic commerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn your WooCommerce catalog into a checked product feed in OpenAI's format for ChatGPT Business Hub and ChatGPT Ads, plus a free OpenAI pixel.

== Description ==

This plugin connects your store to [FeedAgentX](https://www.feedagentx.com), a separate service that reads your catalog, builds a product feed in OpenAI's format, checks every product against its rules and gives each ChatGPT channel its own private feed URL.

* **ChatGPT Business Hub product feed.** [Business Hub](https://chatgpt.com/business-hub) is where a business claims and manages its ChatGPT business profile. Once OpenAI has verified your identity, Business Hub can download your FeedAgentX feed URL on your schedule.
* **ChatGPT Ads product feed.** Product ads in OpenAI Ads Manager use a feed in the same format, from a separate FeedAgentX feed URL. Paid plans can also send title, price and availability changes through OpenAI's Ads API, which OpenAI enables per ad account.
* **Free OpenAI pixel for ChatGPT Ads.** OpenAI's Measurement Pixel and Conversions API for WooCommerce, without code and without a FeedAgentX account.

**How it works**

1. Install the plugin and copy the API token from **FeedAgentX → Product Feed**.
2. Add your store URL and the token to a FeedAgentX account and wait for the first sync.
3. Fix the [validation issues](https://www.feedagentx.com/docs/product-validation/) FeedAgentX finds and choose the products for each channel.
4. Create the [feed URL](https://www.feedagentx.com/docs/hosted-feed/) for Business Hub, Ads or both, and add it in Business Hub or OpenAI Ads Manager with its username and password.
5. Optional: turn on the [pixel](https://www.feedagentx.com/docs/chatgpt-ads-pixel/) under **FeedAgentX → ChatGPT Ads Pixel** and send a test purchase.

**What you need**

* Product feed: a FeedAgentX account. The Free plan is enough to start.
* Business Hub: a profile with identity verification approved by OpenAI; no OpenAI Ads account. See [how to list your business on ChatGPT](https://www.feedagentx.com/blog/add-business-to-chatgpt/).
* ChatGPT Ads and the pixel: an OpenAI Ads account.

**Plans**

* **Free:** 500 products, 1 store, one ChatGPT channel per store (Business Hub or Ads, your choice).
* **Starter, $9/month:** 5,000 products, both channels, Ads API updates.
* **Growth, $19/month:** 25,000 products, 3 stores, both channels, Ads API updates.

Paid plans start with a 14-day free trial; the pixel is always free. See [pricing](https://www.feedagentx.com/pricing/) and [plans and limits](https://www.feedagentx.com/docs/plans-and-limits/#channels).

**What it does not do**

FeedAgentX does not create or verify your Business Hub profile, add the feed for you, create campaigns or enable checkout in ChatGPT. OpenAI decides what ChatGPT shows: no placement, recommendation or sale is guaranteed.

FeedAgentX is an independent service. It is not an official OpenAI or WooCommerce product. ChatGPT and OpenAI are trademarks of OpenAI.

== Installation ==

Install and activate the plugin (WooCommerce 5.0+ must be active), then follow "How it works" in the description or the [Get started guide](https://www.feedagentx.com/docs/getting-started/). Give OpenAI the feed URL from FeedAgentX, not the plugin's WordPress endpoints.

== Frequently Asked Questions ==

= How do I connect WooCommerce to ChatGPT? =
This plugin covers three parts, usable separately: a Business Hub product feed, a ChatGPT Ads product feed and OpenAI's pixel for Ads conversions. See [how to connect WooCommerce to ChatGPT](https://www.feedagentx.com/blog/woocommerce-chatgpt-setup-guide/) for each route.

= How do I add my WooCommerce products to ChatGPT Business Hub? =
Once OpenAI has verified your profile, create the feed URL in FeedAgentX under **ChatGPT channels → Business Hub**, then add it in Business Hub as a **Hosted URL** with its username and password. No OpenAI Ads account is needed. In our 13 Nov 2026 test, Business Hub processed every row of such a feed and offered Daily, Weekly or Every two weeks refresh. OpenAI says connecting a feed doesn't guarantee that ChatGPT recommends your products. [Full steps](https://www.feedagentx.com/docs/chatgpt-business-hub/).

= Is this a ChatGPT shopping / agentic commerce plugin? Does it enable checkout in ChatGPT? =
It covers the product feed, which OpenAI's Agentic Commerce docs describe as structured catalog data for product discovery. It doesn't enable checkout, which OpenAI says needs a separately enabled integration; shoppers buy on your store.

= Can I use my Google Shopping feed for ChatGPT? =
OpenAI's specification has its own required columns, such as `url`, `image_url` and `seller_name`, where Google Merchant Center uses `link` and `image_link`. It doesn't describe a Google-format feed, and we haven't tested one. FeedAgentX builds the feed in OpenAI's format from WooCommerce.

= Is there an OpenAI pixel for WooCommerce? How do I track ChatGPT Ads conversions? =
Yes: this plugin adds OpenAI's Measurement Pixel for free. Create a pixel and a Conversions API key in the conversions tab of OpenAI Ads Manager and paste both under **FeedAgentX → ChatGPT Ads Pixel**. It tracks page views, product views, add to cart, checkout and purchase, and sends paid orders from your server through the Conversions API with the same event ID, so a sale is counted once. Email, phone and name are hashed first; WP Consent API banners are followed.

= Can I add the OpenAI pixel with Google Tag Manager? =
OpenAI says yes, if it loads the pixel snippet on the right pages and doesn't block or reorder the initialization and event calls. With this plugin you don't need it. Load the pixel one way only: adding it again through Tag Manager, a theme or another plugin can send events twice. [Details](https://www.feedagentx.com/blog/chatgpt-ads-conversion-tracking-woocommerce/#google-tag-manager).

= Does it work outside the US? =
Only partly confirmed. OpenAI's specification says the standard upload currently targets the US; Business Hub asks which countries and currencies you sell in. In our test it processed a non-US store's feed, but we haven't confirmed that shoppers there see it. For ChatGPT Ads, OpenAI decides where product ads are available.

== Screenshots ==

1. Send one checked catalog to ChatGPT Business Hub, ChatGPT Ads, or both.
2. The synced catalog: each product's channel state and validation issues.
3. The Business Hub feed URL, with its own username and a masked password.
4. The ChatGPT Ads hosted feed and the state of its import.
5. Pixel settings: Pixel ID, Conversions API key and consent.
6. A test purchase and the log of server-side purchases.
7. Connect the store: the Store URL and API token.
8. Diagnostics: versions, totals and endpoints.

== External services ==

The plugin can use two external services. Each is off until you set it up.

**1. FeedAgentX.com (product feed).** The plugin makes no outbound calls for the feed. It exposes a token-protected REST API that FeedAgentX.com reads once an administrator adds the API token to a FeedAgentX account. It reads product data (titles, descriptions, prices, stock, SKUs, brands, categories, image URLs, attributes, variations), store details (site name, home URL, timezone, currency, store and target countries, policy links, return settings), feed defaults, catalog totals and software versions. No customer, order or payment data. [Privacy](https://www.feedagentx.com/privacy/), [Terms](https://www.feedagentx.com/terms/).

**2. OpenAI (pixel).** Nothing is sent until an administrator turns on the pixel and saves a Pixel ID, or without marketing consent where it is required. None of it goes to FeedAgentX.

* **Browser:** OpenAI's script from `https://bzrcdn.openai.com/sdk/oaiq.min.js` sends the page address, product views, cart additions, checkout starts and purchases with products (IDs, names, quantities, prices), currency and order value, and sets the cookies `__oppref` (30 days) and `__obref` (365 days). With customer matching on, the order confirmation page also sends SHA-256 hashes of the billing email, phone, name and customer ID, and the billing city, region, postcode and country.
* **Server**, if server purchases are on: each paid storefront order goes to `https://bzr.openai.com/v1/events` with its event ID, payment time, checkout URL, value, currency, products, the customer's IP address and user agent, both cookie values and, with matching on, the same hashes and address fields. The admin test purchase sends one sample event with `validate_only`, without customer data.

Payment details are never sent.

[OpenAI Privacy Policy](https://openai.com/policies/privacy-policy/), [Terms of Use](https://openai.com/policies/terms-of-use/).

Suggested privacy-policy text is in **Settings → Privacy → Policy Guide**. Logs in `wp-content/uploads/feedagentx-logs/` hold route names, errors and the IP of refused requests, never tokens, for 14 days by default. On Nginx, deny that path in your server block.

== Changelog ==

= 2.5.1 =
* Fix: a sync no longer stops when another plugin or the theme fails while a product is read (e.g. a pricing plugin that needs a cart). A field that still fails uses the value saved on the product.
* FeedAgentX learns which plugin or theme failed.

= 2.5.0 =
* New plugin name.
* ChatGPT Business Hub next to ChatGPT Ads, with its own feed URL.
* A dismissible WordPress.org review request and a support forum link.

Older versions: see changelog.txt.

== Upgrade Notice ==

= 2.5.1 =
Fixes syncs that stopped with "internal_error" when another plugin failed while products were read.
