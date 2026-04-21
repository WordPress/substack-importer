=== Substack Importer ===
Contributors: wordpressdotorg
Tags: importer, substack
Requires at least: 5.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The Substack Importer allows you to import content from a Substack newsletter into your WordPress site.

== Description ==

The Substack Importer will import content from an export file downloaded from your Substack newsletter.

The following content will be imported:

 - Posts and images.
 - Podcasts.
 - Comments (only for publicly accessible posts).
 - Author information.

In the future, we plan to improve the importer by:

 - Mailing lists.
 - Enhancing the performance of processing export files with many posts and media.

== Installation ==

This plugin depends on the [WordPress Importer](https://wordpress.org/plugins/wordpress-importer) plugin which needs to be installed first.

To install the Substack Importer:

1. Upload the `substack-importer` directory to the `/wp-content/plugins/` directory
1. Activate the plugin through the 'Plugins' menu in WordPress

== Development ==

For running unit tests and contributing to the plugin, see the [README on GitHub](https://github.com/wordpress/substack-importer#development).

Tests can be run with wp-env or with any local WordPress setup paired with a Docker MySQL container. Run `composer install` first, then `vendor/bin/phpunit`.

== Changelog ==

= 1.2.0 =
* Compatibility: the plugin now requires PHP 7.4 or higher.
* Enhancement: added new pre-import options for forcing Draft status, choosing publish date mode, setting the first image as Featured Image, and applying a global Category/Tag.
* Enhancement: improved import behavior handling for featured image assignment and post metadata processing during import.
* Enhancement: added `substack_importer_paywall_marker_text` filter to customize paywall marker text.
* Enhancement: added `substack_importer_paywall_content` filter to override paywall block conversion.
* Enhancement: added `substack_importer_post_content_after_conversion` filter to modify content after Gutenberg conversion.
* Enhancement: added `substack_importer_raw_content` filter to modify raw HTML before Gutenberg conversion.
* Enhancement: added `substack_importer_subtitle` filter to customize or skip the subtitle heading.
* Enhancement: added `substack_importer_post_meta` filter to modify post metadata before processing.
* Enhancement: added `substack_importer_converted_node` filter to customize individual block conversions.
* Enhancement: added `substack_importer_image_result` filter to modify image block attributes.
* Enhancement: added `substack_importer_embed_result` filter to modify embed block results after conversion.
* Enhancement: added `substack_importer_pre_embed_conversion` filter to short-circuit embed conversion before default handling.
* Enhancement: added `substack_importer_audio_block` filter to customize the podcast audio block.
* Enhancement: added `substack_importer_before_post` action that fires before each post is processed.
* Enhancement: added `substack_importer_after_post` action that fires after each post is added to the WXR.

= 1.1.2 =
* Enhancement: support captions for images.
* Enhancement: support TikTok embeds
* Compatibility: the plugin now requires PHP 7.2 or higher.
* Fix: convert preformatted content to verse block.
* Fix: twitter conversion bug.

= 1.1.1 =
* Tested up to WordPress 6.7
* Fix: null checking

= 1.1.0 =
* Update `wxr-generator` to latest version. Fixes a bug where imports could error out due to a misformed timezone identifier.

= 1.0.9 =
* Use subtitle as post excerpt if not empty
* Testing the plugin up to WordPress 6.4.2
* Fix PHPCS error and cleanup composer.lock

= 1.0.8 =
* Removed the subscription input from post content

= 1.0.7 =
* Convert the paywall div to a paragraph

= 1.0.6 =
* Testing the plugin up to WordPress 6.2

= 1.0.5 =
* Add support for WordPress 6.1

= 1.0.4 =
* Fix Soundcloud embeds

= 1.0.3 =
* Identify authors for draft posts as "Draft Posts"

= 1.0.2 =
* Republishing to fix a CI error.

= 1.0.1 =
* Remove unnecessary load_meta_data line.
* Fix embeds not displaying properly on website.

= 1.0.0 =
* Add post meta for paid content.
* Convert Instagram embed to a link.
* Add the subtitle as a H2 at the beginning of the post.
* Set the correct comment_status for posts.

= 0.1.0 =
* Refactored the importer.
* Add support for authors.
* Add support for comments.
* Conversion of content to Gutenberg blocks.
* Convert the export to WXR and use the WordPress Importer plugin to import the WXR.
* Add progress indicator
* Add support for attachments.

= 0.1 =
Early proof-of-concept version.

== Hooks ==

The Substack Importer provides filters and actions at key stages of the content conversion pipeline.

= Post-level Filters =

== substack_importer_post_meta ==

Filter the post metadata loaded from the Substack API before it is used for author, comments, and other post data.

Parameters:
* `$post_meta` (array|null) - The post metadata from the Substack API response.
* `$post` (array) - The raw Substack post data from the CSV.
* `$id` (int) - The Substack post ID.

== substack_importer_raw_content ==

Filter the raw HTML content before Gutenberg conversion. Runs after the subtitle has been prepended (if present). Useful for cleaning up Substack-specific HTML, adding custom elements, or stripping unwanted markup.

Parameters:
* `$html_body` (string) - The raw HTML content from the Substack export.
* `$post` (array) - The raw Substack post data from the CSV.
* `$post_meta` (array|null) - The post metadata from the Substack API response.

== substack_importer_subtitle ==

Filter the subtitle HTML before it is prepended to the post content. Return an empty string to skip the subtitle entirely.

Parameters:
* `$heading` (string) - The subtitle HTML (default: an h2 element).
* `$post` (array) - The raw Substack post data.

== substack_importer_post_content_after_conversion ==

Filter the post content after Gutenberg conversion but before it is added to the WXR. Useful for wrapping paywalled content in custom blocks (e.g., membership plugins).

Parameters:
* `$post_content` (string) - The converted Gutenberg block content.
* `$post` (array) - The original Substack post data.
* `$post_meta` (array|null) - Additional post metadata from Substack API.

== substack_importer_post_data ==

Filter the final post data array before it is added to the WXR.

Parameters:
* `$post_data` (array) - The post data.
* `$post` (array) - The original Substack post data.

= Content Conversion Filters =

== substack_importer_converted_node ==

Filter the result of a single node conversion to a Gutenberg block. Allows modification of the block name and attributes. Return a null block_name to skip the node.

Parameters:
* `$block_data` (array) - Array with 'block_name' and 'block_attributes' keys.
* `$node` (DOMElement) - The converted DOM node.
* `$node_name` (string) - The original HTML tag name (e.g. 'p', 'div', 'h2').

== substack_importer_image_result ==

Filter the image node conversion result. Useful for adjusting image sizes, captions, or link destinations.

Parameters:
* `$result` (array) - Array with 'block_attributes' and 'node' keys.
* `$image_data` (array|null) - The decoded image data from the Substack data-attrs attribute.

== substack_importer_pre_embed_conversion ==

Short-circuit the embed node conversion before default handling. Return a non-null array to skip the built-in switch statement entirely. Useful for handling unsupported embed types or overriding the default conversion for a specific provider.

Parameters:
* `$pre_result` (array|null) - Return non-null to short-circuit. Expected keys: 'node', 'block_attributes', 'block_name'.
* `$node` (DOMElement) - The embed DOM node before conversion.
* `$parent` (DOMElement) - The parent DOM element.
* `$first_class` (string) - The CSS class identifying the embed type (e.g. 'youtube-wrap', 'tweet').

== substack_importer_embed_result ==

Filter the embed node conversion result after the default conversion. Useful for modifying embed URLs, adding custom attributes, or changing how embeds are represented.

Parameters:
* `$output` (array) - Array with 'block_name', 'block_attributes', and 'node' keys.
* `$first_class` (string) - The CSS class identifying the embed type.

== substack_importer_audio_block ==

Filter the Gutenberg audio block HTML for podcast posts.

Parameters:
* `$block` (string) - The Gutenberg audio block HTML.
* `$audio_url` (string) - The URL of the podcast audio file.

= Paywall Filters =

== substack_importer_paywall_marker_text ==

Filter the paywall marker text that appears in the imported content.

Parameters:
* `$marker_text` (string) - The default paywall marker text.
* `$node` (DOMElement) - The paywall node being converted.
* `$parent` (DOMElement) - The parent element.

== substack_importer_paywall_content ==

Filter the entire paywall conversion result. Return a non-null value to override the default conversion.

Parameters:
* `$result` (array|null) - The conversion result, null to use default.
* `$node` (DOMElement) - The paywall node being converted.
* `$parent` (DOMElement) - The parent element.

= Actions =

== substack_importer_before_post ==

Fires before a single Substack post is processed and converted. Useful for setting up state or performing actions before conversion begins.

Parameters:
* `$post` (array) - The raw Substack post data from the CSV.
* `$post_meta` (array|null) - The post metadata from the Substack API response.
* `$id` (int) - The Substack post ID.

== substack_importer_after_post ==

Fires after a single Substack post has been converted and added to the WXR. Useful for logging, progress tracking, or performing cleanup after each post.

Parameters:
* `$post_data` (array) - The final post data that was added to the WXR.
* `$post` (array) - The raw Substack post data from the CSV.
* `$post_meta` (array|null) - The post metadata from the Substack API response.
* `$id` (int) - The Substack post ID.

== Frequently Asked Questions ==

= After about 30 seconds, the import stops and I am seeing a blank screen. What happened? =
When trying to import a large number of posts and images, timeouts can occur. To solve this, you can try to run the import
several times until all content has been imported.
