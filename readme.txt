=== Substack Importer ===
Contributors: wordpressdotorg
Tags: importer, substack
Requires at least: 5.2
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 1.1.2
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

== Changelog ==

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

== Frequently Asked Questions ==

= After about 30 seconds, the import stops and I am seeing a blank screen. What happened? =
When trying to import a large number of posts and images, timeouts can occur. To solve this, you can try to run the import
several times until all content has been imported.
