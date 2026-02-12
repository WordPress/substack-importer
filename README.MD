[![Tests](https://github.com/wordpress/substack-importer/actions/workflows/test.yml/badge.svg?branch=trunk)](https://github.com/wordpress/substack-importer/actions/workflows/test.yml)
[![Coding Standards](https://github.com/wordpress/substack-importer/actions/workflows/cs.yml/badge.svg?branch=trunk)](https://github.com/wordpress/substack-importer/actions/workflows/cs.yml)

# Substack Importer

The Substack Importer is a WordPress plugin that allows you to import a [Substack](https://substack.com) export into your WordPress site.

## Development

### Prerequisites

Install the Composer dependencies from the plugin directory:

```bash
composer install
```

### Running unit tests

The test suite requires a MySQL database. Pick whichever option fits your setup.

#### Option A: wp-env (recommended)

[wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) handles WordPress, MySQL, and Docker for you.

1. Create a `.wp-env.json` in the plugin root:

```json
{
	"core": null,
	"plugins": [".", "../wxr-generator", "WordPress/wordpress-importer"],
	"port": 1000,
	"testsPort": 1001,
	"config": {
		"WP_DEBUG_DISPLAY": true
	}
}
```

2. Start the environment and run the tests:

```bash
wp-env start
wp-env run tests-cli --env-cwd=wp-content/plugins/substack-importer composer install
wp-env run tests-cli --env-cwd=wp-content/plugins/substack-importer vendor/bin/phpunit
```

#### Option B: Docker MySQL (without wp-env)

If you use a local WordPress setup (Studio, MAMP, Local, etc.) that does not provide MySQL on the command line, you can spin up a throwaway MySQL container.

1. Start MySQL in Docker (use any free port -- here `3307` to avoid conflicts):

```bash
docker run --name wp-tests-mysql \
  -e MYSQL_ROOT_PASSWORD=root \
  -e MYSQL_DATABASE=substack_importer_tests \
  -p 3307:3306 -d mysql:8.0
```

2. Install the WordPress test library (one-time setup):

```bash
bash phpunit/install.sh substack_importer_tests root root 127.0.0.1:3307 latest
```

3. Run the tests:

```bash
vendor/bin/phpunit
```

To stop / start the container between sessions:

```bash
docker stop wp-tests-mysql   # pause
docker start wp-tests-mysql  # resume
docker rm -f wp-tests-mysql  # remove completely
```

### Coding standards

```bash
composer lint            # check
composer format          # auto-fix
```

## Hooks

The Substack Importer provides filters and actions at key stages of the content conversion pipeline, allowing you to customize every aspect of the import.

### Content Conversion Pipeline

```
1. Load post from CSV + metadata
   → filter: substack_importer_post_meta
   → action: substack_importer_before_post
2. Prepend subtitle (if present)
   → filter: substack_importer_subtitle
3. Raw HTML ready
   → filter: substack_importer_raw_content
4. HTML → Gutenberg conversion (per node)
   → filter: substack_importer_converted_node (per node)
   → filter: substack_importer_image_result (images)
   → filter: substack_importer_pre_embed_conversion (embeds, pre)
   → filter: substack_importer_embed_result (embeds, post)
5. Post content after conversion
   → filter: substack_importer_post_content_after_conversion
6. Podcast audio block (if podcast)
   → filter: substack_importer_audio_block
7. Final post data
   → filter: substack_importer_post_data
8. Added to WXR
   → action: substack_importer_after_post
```

---

### Post-level Filters

#### `substack_importer_post_meta`

Filter the post metadata loaded from the Substack API before it is used for author, comments, and other post data.

```php
add_filter( 'substack_importer_post_meta', function( $post_meta, $post, $id ) {
    // Inject custom author information.
    if ( $post_meta ) {
        $post_meta['publishedBylines'] = array(
            array( 'id' => 1, 'name' => 'Custom Author' ),
        );
    }
    return $post_meta;
}, 10, 3 );
```

**Parameters:**
- `$post_meta` (array|null) - The post metadata from the Substack API response.
- `$post` (array) - The raw Substack post data from the CSV.
- `$id` (int) - The Substack post ID.

#### `substack_importer_raw_content`

Filter the raw HTML content before Gutenberg conversion. Runs after the subtitle has been prepended. Useful for cleaning up Substack-specific HTML or injecting content.

```php
add_filter( 'substack_importer_raw_content', function( $html_body, $post, $post_meta ) {
    // Remove all Substack-specific tracking pixels.
    $html_body = preg_replace( '/<img[^>]+class="tracking-pixel"[^>]*>/', '', $html_body );
    return $html_body;
}, 10, 3 );
```

**Parameters:**
- `$html_body` (string) - The raw HTML content from the Substack export.
- `$post` (array) - The raw Substack post data from the CSV.
- `$post_meta` (array|null) - The post metadata from the Substack API response.

#### `substack_importer_subtitle`

Filter the subtitle HTML before it is prepended to the post content. Return an empty string to skip the subtitle.

```php
add_filter( 'substack_importer_subtitle', function( $heading, $post ) {
    // Change subtitle from h2 to h3.
    return sprintf( '<h3>%s</h3>', $post['subtitle'] );
}, 10, 2 );
```

**Parameters:**
- `$heading` (string) - The subtitle HTML (default: an `<h2>` element).
- `$post` (array) - The raw Substack post data containing 'subtitle' and 'html_body'.

#### `substack_importer_post_content_after_conversion`

Filter the post content after Gutenberg conversion but before it is added to the WXR. Useful for wrapping paywalled content in custom blocks.

```php
add_filter( 'substack_importer_post_content_after_conversion', function( $post_content, $post, $post_meta ) {
    $marker = "<!-- wp:paragraph --><p>The content below was originally paywalled.</p>\n<!-- /wp:paragraph -->";
    $parts = explode( $marker, $post_content );

    if ( count( $parts ) > 1 ) {
        return $parts[0] . '<!-- wp:your-plugin/restricted -->' . $parts[1] . '<!-- /wp:your-plugin/restricted -->';
    }

    return $post_content;
}, 10, 3 );
```

**Parameters:**
- `$post_content` (string) - The converted Gutenberg block content.
- `$post` (array) - The original Substack post data.
- `$post_meta` (array|null) - Additional post metadata from Substack API.

#### `substack_importer_post_data`

Filter the final post data array before it is added to the WXR.

**Parameters:**
- `$post_data` (array) - The post data.
- `$post` (array) - The original Substack post data.

---

### Content Conversion Filters

#### `substack_importer_converted_node`

Filter the result of a single node conversion to a Gutenberg block. Return a null `block_name` to skip the node.

```php
add_filter( 'substack_importer_converted_node', function( $block_data, $node, $node_name ) {
    // Convert all h1 headings to h2.
    if ( 'wp:heading' === $block_data['block_name']
        && 1 === ( $block_data['block_attributes']['level'] ?? 0 )
    ) {
        $block_data['block_attributes']['level'] = 2;
    }
    return $block_data;
}, 10, 3 );
```

**Parameters:**
- `$block_data` (array) - Array with `block_name` (string) and `block_attributes` (array).
- `$node` (DOMElement) - The converted DOM node.
- `$node_name` (string) - The original HTML tag name (e.g. 'p', 'div', 'h2').

#### `substack_importer_image_result`

Filter the image node conversion result.

```php
add_filter( 'substack_importer_image_result', function( $result, $image_data ) {
    // Change all images to link to the media file.
    $result['block_attributes']['linkDestination'] = 'media';
    return $result;
}, 10, 2 );
```

**Parameters:**
- `$result` (array) - Array with `block_attributes` and `node` keys.
- `$image_data` (array|null) - The decoded image data from the Substack `data-attrs` attribute.

#### `substack_importer_pre_embed_conversion`

Short-circuit the embed node conversion before default handling. Return a non-null array to skip the built-in conversion entirely. Useful for handling unsupported embed types or overriding specific providers.

```php
add_filter( 'substack_importer_pre_embed_conversion', function( $pre_result, $node, $parent, $first_class ) {
    // Handle a custom embed type not supported by default.
    if ( 'mastodon-wrap' === $first_class ) {
        $data = json_decode( $node->getAttribute( 'data-attrs' ), true );
        $new_node = new DomElement( 'figure' );
        $parent->replaceChild( $new_node, $node );

        return array(
            'node'             => $new_node,
            'block_attributes' => array( 'url' => $data['url'] ),
            'block_name'       => 'wp:embed',
        );
    }
    return $pre_result;
}, 10, 4 );
```

**Parameters:**
- `$pre_result` (array|null) - Return non-null to short-circuit. Expected keys: `node`, `block_attributes`, `block_name`.
- `$node` (DOMElement) - The embed DOM node before conversion.
- `$parent` (DOMElement) - The parent DOM element.
- `$first_class` (string) - The CSS class identifying the embed type (e.g. 'youtube-wrap', 'tweet').

#### `substack_importer_embed_result`

Filter the embed node conversion result after the default conversion.

```php
add_filter( 'substack_importer_embed_result', function( $output, $first_class ) {
    // Add a custom CSS class to YouTube embeds.
    if ( 'youtube-wrap' === $first_class && ! empty( $output['block_attributes'] ) ) {
        $output['block_attributes']['className'] = 'custom-youtube-embed';
    }
    return $output;
}, 10, 2 );
```

**Parameters:**
- `$output` (array) - Array with `block_name`, `block_attributes`, and `node` keys.
- `$first_class` (string) - The CSS class identifying the embed type.

#### `substack_importer_audio_block`

Filter the Gutenberg audio block HTML for podcast posts.

```php
add_filter( 'substack_importer_audio_block', function( $block, $audio_url ) {
    // Use a custom audio player block instead.
    return sprintf(
        '<!-- wp:custom/audio-player {"url":"%s"} --><div class="custom-audio-player" data-src="%s"></div><!-- /wp:custom/audio-player -->',
        $audio_url,
        $audio_url
    );
}, 10, 2 );
```

**Parameters:**
- `$block` (string) - The Gutenberg audio block HTML.
- `$audio_url` (string) - The URL of the podcast audio file.

---

### Paywall Filters

#### `substack_importer_paywall_marker_text`

Filter the paywall marker text that appears in the imported content.

```php
add_filter( 'substack_importer_paywall_marker_text', function( $marker_text, $node, $parent ) {
    return __( 'Premium content below', 'your-textdomain' );
}, 10, 3 );
```

**Parameters:**
- `$marker_text` (string) - The default paywall marker text.
- `$node` (DOMElement) - The paywall node being converted.
- `$parent` (DOMElement) - The parent element.

#### `substack_importer_paywall_content`

Filter the entire paywall conversion result. Return a non-null value to override the default conversion.

```php
add_filter( 'substack_importer_paywall_content', function( $result, $node, $parent ) {
    $new_node = new DOMElement( 'div' );
    $parent->replaceChild( $new_node, $node );
    return array(
        'node'             => $new_node,
        'block_attributes' => array(),
        'block_name'       => 'wp:group',
    );
}, 10, 3 );
```

**Parameters:**
- `$result` (array|null) - The conversion result, null to use default.
- `$node` (DOMElement) - The paywall node being converted.
- `$parent` (DOMElement) - The parent element.

---

### Actions

#### `substack_importer_before_post`

Fires before a single Substack post is processed and converted.

```php
add_action( 'substack_importer_before_post', function( $post, $post_meta, $id ) {
    error_log( sprintf( 'Processing Substack post #%d: %s', $id, $post['title'] ) );
}, 10, 3 );
```

**Parameters:**
- `$post` (array) - The raw Substack post data from the CSV.
- `$post_meta` (array|null) - The post metadata from the Substack API response.
- `$id` (int) - The Substack post ID.

#### `substack_importer_after_post`

Fires after a single Substack post has been converted and added to the WXR.

```php
add_action( 'substack_importer_after_post', function( $post_data, $post, $post_meta, $id ) {
    error_log( sprintf( 'Completed Substack post #%d: %s (status: %s)', $id, $post_data['title'], $post_data['status'] ) );
}, 10, 4 );
```

**Parameters:**
- `$post_data` (array) - The final post data that was added to the WXR.
- `$post` (array) - The raw Substack post data from the CSV.
- `$post_meta` (array|null) - The post metadata from the Substack API response.
- `$id` (int) - The Substack post ID.

## Publishing the plugin

When a new tag is pushed, the tag will be published to SVN.
