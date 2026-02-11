[![Tests](https://github.com/wordpress/substack-importer/actions/workflows/test.yml/badge.svg?branch=trunk)](https://github.com/wordpress/substack-importer/actions/workflows/test.yml)
[![Coding Standards](https://github.com/wordpress/substack-importer/actions/workflows/cs.yml/badge.svg?branch=trunk)](https://github.com/wordpress/substack-importer/actions/workflows/cs.yml)

# Substack Importer

The Substack Importer is a WordPress plugin that allows you to import a [Substack](https://substack.com) export into your WordPress site.

## Testing instructions

Testing the plugin locally is easiest done by using [wp-env](https://make.wordpress.org/core/2020/03/03/wp-env-simple-local-environments-for-wordpress/).

## Set up your local environment

The easiest way to run manual and unit tests for the Substack Importer is by using [wp-env](https://make.wordpress.org/core/2020/03/03/wp-env-simple-local-environments-for-wordpress/).
The instructions here assume you are using wp-env.

Steps:

1.  Install wp-env globally and add a .wp-env.json file. You can use the example below.

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

2. As the substack importer depends on the wxr-generator and wordpress-importer plugin, make sure you have added both of them to the `plugins` field. This field contains a list of plugins to be loaded and enabled.

3. Run `wp-env start` in the substack-importer plugin directory. This will fire up a couple of Docker containers that give you access to a working WordPress instance.

### Run unit tests

To run unit tests some composer dependencies need to be installed. We can run composer and install the dependencies by running th following command inside the substack-importer directory:

`wp-env run cli --env-cwd=wp-content/plugins/substack-importer composer install`

Unit tests can now be ran with the following command:

`wp-env run tests-cli --env-cwd=wp-content/plugins/substack-importer vendor/bin/phpunit --configuration=./phpunit.xml.dist`

## Filters

The Substack Importer provides several filters to customize the import process:

### `substack_importer_paywall_marker_text`

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

### `substack_importer_paywall_content`

Filter the entire paywall conversion result. Return a non-null value to override the default conversion.

```php
add_filter( 'substack_importer_paywall_content', function( $result, $node, $parent ) {
    // Return custom conversion result
    return array(
        'node'             => $new_node,
        'block_attributes' => array(),
        'block_name'       => 'wp:paragraph',
    );
}, 10, 3 );
```

**Parameters:**
- `$result` (array|null) - The conversion result, null to use default.
- `$node` (DOMElement) - The paywall node being converted.
- `$parent` (DOMElement) - The parent element.

### `substack_importer_post_content_after_conversion`

Filter the post content after Gutenberg conversion but before it is added to the WXR. This is useful for wrapping paywalled content in custom blocks (e.g., membership plugins).

```php
add_filter( 'substack_importer_post_content_after_conversion', function( $post_content, $post, $post_meta ) {
    // Find paywall marker and wrap content in custom block
    $marker = "<!-- wp:paragraph --><p>The content below was originally paywalled.</p>\n<!-- /wp:paragraph -->";
    $parts = explode( $marker, $post_content );

    if ( count( $parts ) > 1 ) {
        $free_content = $parts[0];
        $paid_content = $parts[1];

        // Wrap paid content in your membership block
        $paid_content = '<!-- wp:your-plugin/restricted -->' . $paid_content . '<!-- /wp:your-plugin/restricted -->';

        return $free_content . $paid_content;
    }

    return $post_content;
}, 10, 3 );
```

**Parameters:**
- `$post_content` (string) - The converted Gutenberg block content.
- `$post` (array) - The original Substack post data.
- `$post_meta` (array|null) - Additional post metadata from Substack API.

## Publishing the plugin

When a new tag is pushed, the tag will be published to SVN.
