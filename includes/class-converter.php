<?php
// phpcs:disable Universal.NamingConventions.NoReservedKeywordParameterNames.parentFound
namespace SubstackImporter;

use WP_Error;
use WXR_Generator\Generator;
use ZipArchive;
use DOMDocument;
use DomComment;
use DomElement;
use DOMText;


/**
 * The Substack Converter is responsible for taking in a Substack export and providing
 * data to the WXR generator.
 *
 * @package SubstackImporter
 */
class Converter {

	/**
	 * @var string $export_file_path Path of the export file.
	 */
	protected $export_file_path;

	/**
	 * Instance of the WXR generator
	 * @var Generator $generator
	 */
	protected $generator;

	/**
	 * Authors.
	 * @var array
	 */
	protected $authors = array();

	/**
	 * Categories.
	 *
	 * @var array
	 */
	protected $categories = array();

	/**
	 * URL of the Substack Newsletter.
	 *
	 * @var string
	 */
	protected $substack_url;

	/**
	 * The classnames of all possible embed nodes in the Substack HTML.
	 *
	 * @var string[]
	 */
	protected $supported_embeds = array(
		'tweet',
		'instagram', // No longer supported.
		'youtube-wrap',
		'spotify-wrap',
		'soundcloud-wrap',
		'vimeo-wrap',
		'bandcamp-wrap', // Shortcode embed,
		'github-gist', // Not supported in core, using shortcode embed
		'tiktok-wrap',
	);


	/**
	 * Converter constructor.
	 *
	 * @param Generator $generator Instance of the WXR Generator.
	 * @param string $export_file_path Path to the Substack export zip file.
	 * @param null $substack_url URL of the Substack newsletter.
	 */
	public function __construct( Generator $generator, $export_file_path, $substack_url = null ) {
		$this->generator        = $generator;
		$this->export_file_path = $export_file_path;
		$this->substack_url     = $substack_url;
	}

	/**
	 * Convert the Substack export to a WXR.
	 *
	 * @returns WP_Error|void
	 *
	 * @throws \OxymelException
	 */
	public function convert() {
		if ( ! $this->export_file_path || ! file_exists( $this->export_file_path ) ) {
			return new WP_Error( 'export_file_not_exist', 'The export file does not exist' );
		}

		$this->generator->initialize();

		// Add posts.
		$out = $this->add_posts();

		if ( is_wp_error( $out ) ) {
			return $out;
		}

		// Add Authors.
		foreach ( $this->authors as $author ) {
			$this->generator->add_author( $author );
		}

		// Add categories.
		foreach ( $this->categories as $category ) {
			$this->generator->add_category( $category );
		}

		$this->generator->finalize();
	}

	/**
	 * Lets make sure that the url always has a https protocol.
	 * @param null $url
	 */
	private function ensure_protocol( $url ) {
		if ( is_null( $url ) ) {
			return null;
		}

		// Ensure substack_url has a protocol (http or https)
		$parsed_url = parse_url( $url );
		if ( ! isset( $parsed_url['scheme'] ) ) {
			$url = 'https://' . $url;
		}

		return preg_replace( '/^(https?:\/\/)?/', 'https://', $url );
	}

	/**
	 * Load additional information retrieved through the Substack API into the export zip file.
	 *
	 * @param int $offset 0-indexed starting offset for the post to start with.
	 * @param int $limit Number of posts to process.
	 *
	 * @return array|WP_Error
	 */
	public function load_meta_data( $offset = 0, $limit = 1 ) {

		$zip = $this->get_export_zip();

		if ( is_wp_error( $zip ) ) {
			return $zip;
		}

		$total_count = 0;

		$this->substack_url = $this->ensure_protocol( $this->substack_url );

		foreach ( $this->get_posts() as $idx => $post ) {
			++$total_count;

			if ( $idx < $offset || $idx >= $offset + $limit ) {
				continue;
			}

			list($id, $slug) = explode( '.', $post['post_id'], 2 );
			$meta            = $this->fetch_post_meta( $slug );

			if ( $meta ) {
				$zip->addFromString( sprintf( 'meta/%s.json', $id ), $meta );
			}
		}

		return array(
			'total'     => $total_count,
			'processed' => min( $offset + $limit, $total_count ),
		);
	}

	/**
	 * Convert each Substack post to a WordPress post and add it to the WXR.
	 *
	 * @return void|WP_Error
	 *
	 * @throws \OxymelException
	 */
	protected function add_posts() {

		$posts_generator = $this->get_posts();

		if ( is_wp_error( $posts_generator ) ) {
			return $posts_generator;
		}

		foreach ( $posts_generator as $post ) {

			$post_id   = explode( '.', $post['post_id'], 2 );
			$id        = (int) $post_id[0];
			$post_meta = $this->get_post_meta_from_export( $id );

			/**
			 * Filter the post metadata loaded from the Substack export.
			 *
			 * Allows modification of the metadata retrieved from the Substack API
			 * before it is used for author, comments, and other post data.
			 *
			 * @since 1.2.0
			 *
			 * @param array|null $post_meta The post metadata from the Substack API response.
			 * @param array      $post      The raw Substack post data from the CSV.
			 * @param int        $id        The Substack post ID.
			 */
			$post_meta = apply_filters( 'substack_importer_post_meta', $post_meta, $post, $id );

			/**
			 * Fires before a single Substack post is processed and converted.
			 *
			 * Useful for setting up state or performing actions before conversion begins.
			 *
			 * @since 1.2.0
			 *
			 * @param array      $post      The raw Substack post data from the CSV.
			 * @param array|null $post_meta The post metadata from the Substack API response.
			 * @param int        $id        The Substack post ID.
			 */
			do_action( 'substack_importer_before_post', $post, $post_meta, $id );

			if ( ! empty( $post['subtitle'] ) ) {
				$post['html_body'] = $this->add_subtitle( $post );
			}

			/**
			 * Filter the raw HTML content before Gutenberg conversion.
			 *
			 * This filter runs after the subtitle has been prepended (if present)
			 * but before the HTML is parsed and converted to Gutenberg blocks.
			 * Useful for cleaning up or transforming Substack-specific HTML,
			 * adding custom elements, or stripping unwanted markup.
			 *
			 * @since 1.2.0
			 *
			 * @param string     $html_body The raw HTML content from the Substack export.
			 * @param array      $post      The raw Substack post data from the CSV.
			 * @param array|null $post_meta The post metadata from the Substack API response.
			 */
			$post['html_body'] = apply_filters(
				'substack_importer_raw_content',
				$post['html_body'],
				$post,
				$post_meta
			);

			$post_content = $this->convert_html_to_gutenberg( $post['html_body'] );

			/**
			 * Filter the post content after Gutenberg conversion.
			 *
			 * This filter allows modification of the converted Gutenberg block content
			 * before it is added to the WXR. Useful for wrapping paywalled content in
			 * custom blocks (e.g., membership plugins).
			 *
			 * @since 1.2.0
			 *
			 * @param string     $post_content The converted Gutenberg block content.
			 * @param array      $post         The original Substack post data.
			 * @param array|null $post_meta    Additional post metadata from Substack API.
			 */
			$post_content = apply_filters(
				'substack_importer_post_content_after_conversion',
				$post_content,
				$post,
				$post_meta
			);

			$post_data = array(
				'id'              => $id,
				'title'           => $post['title'],
				'content'         => $post_content,
				'date'            => 'true' === $post['is_published'] ? $post['post_date'] : '',
				'status'          => 'true' === $post['is_published'] ? 'publish' : 'draft',
				'post_date_gmt'   => $post['post_date'],
				'post_date'       => $post['post_date'],
				'post_taxonomies' => array(),
				'metas'           => array(),
			);

			$first_image_url = $this->get_first_image_url_from_html( $post['html_body'] );
			if ( ! empty( $first_image_url ) ) {
				$post_data['metas'][] = array(
					'key'   => '_substack_first_image_url',
					'value' => $first_image_url,
				);
			}

			if ( isset( $post_id[1] ) ) {
				$post_data['post_name'] = $post_id[1];
			}

			// If we were able to retrieve more information through the Substack API, we might have
			// author information and comments.
			$post_data['author']   = $post_meta ? $this->get_post_author( $post_meta, $post_data['status'] ) : $this->get_default_author( $post_data['status'] );
			$post_data['comments'] = $post_meta ? $this->get_post_comments( $post_meta ) : array();

			// Set the comment status.
			$post_data['comment_status'] = ! empty( $post_meta['write_comment_permissions'] ) && 'none' === $post_meta['write_comment_permissions']
				? 'closed'
				: 'open';

			// Handle podcast posts - prepend a Gutenberg audio block to the post content.
			if ( 'podcast' === $post['type'] && ! empty( $post['podcast_url'] ) ) {
				$post_data = $this->handle_podcast_post( $post_data, $post );
			}

			/**
			 * Allow for custom modifications to the post data.
			 *
			 * @param array $post_data The post data.
			 * @param array $post The original post data.
			 */
			$post_data = apply_filters( 'substack_importer_post_data', $post_data, $post );

			$this->generator->add_post( $post_data );

			/**
			 * Fires after a single Substack post has been converted and added to the WXR.
			 *
			 * Useful for logging, progress tracking, or performing cleanup after each post.
			 *
			 * @since 1.2.0
			 *
			 * @param array      $post_data The final post data that was added to the WXR.
			 * @param array      $post      The raw Substack post data from the CSV.
			 * @param array|null $post_meta The post metadata from the Substack API response.
			 * @param int        $id        The Substack post ID.
			 */
			do_action( 'substack_importer_after_post', $post_data, $post, $post_meta, $id );
		}
	}

	protected function handle_podcast_post( $post_data, $post ) {
		$post_data['content'] = $this->get_audio_block( $post['podcast_url'] ) . $post_data['content'];

		// Create a new attachment
		$this->generator->add_post(
			array(
				'title'          => urldecode( basename( $post['podcast_url'] ) ),
				'link'           => $post['podcast_url'],
				'post_date'      => $post_data['post_date'],
				'type'           => 'attachment',
				'attachment_url' => $post['podcast_url'],
				'metas'          => array(
					array(
						'key'   => '_wp_original_image_link',
						'value' => $post['podcast_url'],
					),
				),
			)
		);

		// Add this post to the podcast category.
		$this->categories['podcast']    = array(
			'slug' => 'podcast',
			'name' => 'Podcast',
		);
		$post_data['post_taxonomies'][] = array(
			'name'   => 'Podcast',
			'slug'   => 'podcast',
			'domain' => 'category',
		);

		return $post_data;
	}

	/**
	 * Add the subtitle to the html_content by prepending a h2.
	 *
	 * @param array $post The post data containing subtitle and html_body.
	 *
	 * @return string html body content.
	 */
	protected function add_subtitle( $post ) {
		$heading = sprintf( '<h2>%s</h2>', $post['subtitle'] );

		/**
		 * Filter the subtitle HTML before it is prepended to the post content.
		 *
		 * Return an empty string to skip the subtitle entirely.
		 * Useful for changing the heading level, wrapping in custom markup,
		 * or conditionally removing subtitles.
		 *
		 * @since 1.2.0
		 *
		 * @param string $heading  The subtitle HTML (default: an h2 element).
		 * @param array  $post     The raw Substack post data containing 'subtitle' and 'html_body'.
		 */
		$heading = apply_filters( 'substack_importer_subtitle', $heading, $post );

		return $heading . $post['html_body'];
	}

	/**
	 * Get a Gutenberg Audio block for the podcast.
	 *
	 * @param string $audio_url The URL of the podcast audio file.
	 *
	 * @return string The Gutenberg audio block HTML.
	 */
	protected function get_audio_block( $audio_url ) {
		$code  = '<!-- wp:audio --><figure class="wp-block-audio"><audio controls src="%s"></audio><figcaption>Podcast</figcaption></figure><!-- /wp:audio -->';
		$block = sprintf( $code, $audio_url );

		/**
		 * Filter the Gutenberg audio block HTML for podcast posts.
		 *
		 * Allows modification or replacement of the audio block that is
		 * prepended to podcast post content. Useful for using a custom
		 * audio player block or adding additional markup.
		 *
		 * @since 1.2.0
		 *
		 * @param string $block     The Gutenberg audio block HTML.
		 * @param string $audio_url The URL of the podcast audio file.
		 */
		return apply_filters( 'substack_importer_audio_block', $block, $audio_url );
	}

	protected function get_post_author( $post_meta, $post_status ) {
		// If we can't get the author information, return a default author.
		if ( empty( $post_meta['publishedBylines'] ) ) {
			return $this->get_default_author( $post_status );
		}

		$byline                         = $post_meta['publishedBylines'][0];
		$this->authors[ $byline['id'] ] = array(
			'login'        => $byline['name'],
			'display_name' => $byline['name'],
			'id'           => $byline['id'],
		);

		return $byline['name'];
	}

	protected function get_default_author( $post_status ) {
		$unknown_author_key   = '_unknown';
		$unknown_author_value = array(
			'login'        => 'Unknown',
			'display_name' => 'Unknown',
			'id'           => 1,
		);
		$draft_author_key     = '_draft';
		$draft_author_value   = array(
			'login'        => 'Draft',
			'display_name' => 'Draft Posts',
			'id'           => 2,
		);

		if ( 'publish' === $post_status ) {
			$this->authors[ $unknown_author_key ] = $unknown_author_value;
			return $unknown_author_key;
		} else {
			$this->authors[ $draft_author_key ] = $draft_author_value;
			return $draft_author_key;
		}
	}

	/**
	 * Get post comments retrieved through the Substack api.
	 *
	 * @param array $post_data Additional data about a post retrieved through the Substack Post API.
	 *
	 * @return mixed
	 */
	protected function get_post_comments( $post_meta ) {
		if ( empty( $post_meta['comments'] ) ) {
			return array();
		}

		return $this->parse_comments( $post_meta['comments'] );
	}

	/**
	 * Recursively parse the comments and prepare the data required for the WXR output.
	 *
	 * @param array $comments An array of comments provided by the Substack posts API endpoint.
	 * @param array $out Output that is ready to be passed to the WXR generator.
	 * @param null $parent If we are in a recursive call, the parent must be provided.
	 *
	 * @return array|mixed
	 */
	protected function parse_comments( $comments, $out = array(), $parent = null ) {
		foreach ( $comments as $comment ) {
			$out[] = array(
				'id'       => $comment['id'],
				'author'   => $comment['name'],
				'date'     => $comment['date'],
				'date_gmt' => $comment['date'],
				'content'  => $comment['body'],
				'parent'   => $parent,
				'metas'    => array(),
			);

			if ( ! empty( $comment['children'] ) ) {
				$out = $this->parse_comments( $comment['children'], $out, (int) $comment['id'] );
			}
		}

		return $out;
	}

	/**
	 * Convert the content HTML to Gutenberg blocks and return the result.
	 *
	 * @param string $content The HTML provided by Substack.
	 *
	 * @return string|string[]|null
	 *
	 * @todo Load the content as XML to prevent errors from loadHTML.
	 */
	protected function convert_html_to_gutenberg( $content ) {
		$dom = new DOMDocument();

		// Suppress warnings and errors when loading HTML
		libxml_use_internal_errors( true );

		// By inserting a meta tag with utf-8 encoding we make sure the content is converted to utf-8
		$content = '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $content;
		$dom->loadHTML( $content );

		// Clear any errors that were logged
		libxml_clear_errors();

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );

		// We don't want to use the DomNodeList because it will change while we are iterating over the nodes.
		$nodes = array();
		foreach ( $body->childNodes as $node ) { //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( ! $node instanceof DomElement ) {
				continue;
			}
			$nodes[] = $node;
		}

		// We go through the top-level nodes and handle each of them.
		foreach ( $nodes as $idx => $node ) {
			$next_sibling = count( $nodes ) - 1 > $idx ? $nodes[ $idx + 1 ] : null;
			$this->convert_node( $node, $body, $next_sibling );
		}

		// Save as XML otherwise we don't get HTMl5 elements correctly.
		$content = $dom->saveXML( $body );

		// Strip the body tag.
		$content = preg_replace( '/<body>(.+)<\/body>/s', '$1', $content );

		return $content;
	}


	/**
	 * Convert a single node to a Gutenberg block.
	 *
	 * Tries to convert a given HTML node into a Gutenberg block.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node to be converted.
	 * @param DomElement|null $next_sibling The next sibling of the node to be converted, if it exists.
	 *
	 */
	protected function convert_node( DOMElement $node, DomElement $parent, ?DomElement $next_sibling = null ) {
		$block_name       = null;
		$block_attributes = array();

		$node_name = $node->nodeName; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		switch ( $node_name ) {

			case 'p':
				$block_name = 'wp:paragraph';
				$class      = $node->getAttribute( 'class' );

				// remove empty paragraphs.
				/** @todo Perhaps we can remove all empty nodes, not just paragraphs? */
				if ( ! $node->childNodes->length ) { //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$parent->removeChild( $node );
					$node = null;
				}

				// Button
				if ( 'button-wrapper' === $class ) {
					$node       = $this->convert_button_node( $node, $parent );
					$block_name = 'wp:button';
				}

				break;

			case 'blockquote':
				$block_name = 'wp:quote';
				$node->setAttribute( 'class', 'wp-block-quote' );
				break;

			case 'div':
			case 'iframe':
				$class = $node->getAttribute( 'class' );

				// Preformatted text - these are Poetry blocks in Substack
				if ( 'preformatted-block' === $class ) {
					$node       = $this->convert_preformatted_node( $node, $parent );
					$block_name = 'wp:verse';
				}

				// Pull Quotes
				if ( 'pullquote' === $class ) {
					$node       = $this->convert_pullquote_node( $node, $parent );
					$block_name = 'wp:pullquote';
				}

				// Images
				if ( 'captioned-image-container' === $class ) {
					$result           = $this->convert_image_node( $node, $parent );
					$node             = $result['node'];
					$block_attributes = $result['block_attributes'];
					$block_name       = 'wp:image';
				}

				// Horizontal separator
				if (
					$node &&
					$node->hasChildNodes() && //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$node->childNodes->length && //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					'hr' === $node->childNodes[0]->nodeName //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				) {
					$node       = $this->convert_separator_node( $node, $parent );
					$block_name = 'wp:separator';
				}

				// Embeds
				$first_class = explode( ' ', $class );
				if ( ! empty( $first_class ) && in_array( $first_class[0], $this->supported_embeds, true ) ) {
					$result           = $this->convert_embed_node( $node, $parent );
					$node             = $result['node'];
					$block_attributes = $result['block_attributes'];
					$block_name       = $result['block_name'];
				}

				if ( 'paywall-jump' === $class ) {
					$result           = $this->convert_paywall_node( $node, $parent );
					$node             = $result['node'];
					$block_attributes = $result['block_attributes'];
					$block_name       = $result['block_name'];
				}

				if ( 'subscription-widget-wrap' === $class ) {
					$result           = $this->convert_subscription_node( $node, $parent );
					$node             = $result['node'];
					$block_attributes = $result['block_attributes'];
					$block_name       = $result['block_name'];
				}

				break;

			case 'ol':
			case 'ul':
				$block_name = 'wp:list';

				if ( 'ol' === $node_name ) {
					$block_attributes['ordered'] = true;
				}

				break;

			case 'pre':
				$block_name = 'wp:code';
				$node->setAttribute( 'class', 'wp-block-code' );
				break;

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$block_name = 'wp:heading';

				// Gutenberg defaults to h2 for heading blocks without a level attribute.
				if ( 'h1' === $node_name ) {
					$node = $this->replace_html_node_tag( $node, $parent, 'h2' );
				}

				$node->setAttribute( 'class', 'wp-block-heading' );

				if ( ! in_array( $node_name, array( 'h1', 'h2' ), true ) ) {
					$block_attributes['level'] = (int) substr( $node_name, 1, 1 );
				}
				break;

			case 'a':
				$class = $node->getAttribute( 'class' );
				if ( 'image-link image2' === trim( $class ) ) {
					$result           = $this->convert_image_node( $node, $parent );
					$node             = $result['node'];
					$block_attributes = $result['block_attributes'];
					$block_name       = 'wp:image';
				}

				break;

		}

		if ( ! $block_name || ! $node ) {
			return;
		}

		/**
		 * Filter the result of a single node conversion to a Gutenberg block.
		 *
		 * Allows modification of the block name and attributes after the default
		 * conversion logic has run. Return a block_name of null to skip the node.
		 * Useful for overriding how specific Substack elements are converted,
		 * adding custom attributes, or changing block types.
		 *
		 * @since 1.2.0
		 *
		 * @param array      $block_data {
		 *     The block conversion result.
		 *
		 *     @type string $block_name       The Gutenberg block name (e.g. 'wp:paragraph').
		 *     @type array  $block_attributes The block attributes array.
		 * }
		 * @param DomElement $node      The converted DOM node.
		 * @param string     $node_name The original HTML tag name (e.g. 'p', 'div', 'h2').
		 */
		$block_data = apply_filters(
			'substack_importer_converted_node',
			array(
				'block_name'       => $block_name,
				'block_attributes' => $block_attributes,
			),
			$node,
			$node_name
		);

		$block_name       = $block_data['block_name'];
		$block_attributes = $block_data['block_attributes'];

		if ( ! $block_name ) {
			return;
		}

		// Create the Gutenberg block code
		$attributes_part = '';
		if ( is_countable( $block_attributes ) && count( $block_attributes ) ) {
			$attributes_part = ' ' . wp_json_encode( $block_attributes );
		}
		$block_open  = new DOMComment( ' ' . $block_name . $attributes_part . ' ' );
		$block_close = new DOMComment( ' /' . $block_name . ' ' );

		$parent->insertBefore( $block_open, $node );

		$next_sibling
			? $parent->insertBefore( $block_close, $next_sibling )
			: $parent->appendChild( $block_close );
	}

	/**
	 * Convert a preformatted text node to valid Gutenberg markup.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node.
	 *
	 * @return DomElement The converted node.
	 */
	protected function convert_preformatted_node( DomElement $node, DomElement $parent ) {
		$node_pres = $node->getElementsByTagName( 'pre' );
		if ( 0 === $node_pres->length ) {
			return null;
		}

		$new_node = new DomElement( 'pre', $node_pres[0]->textContent );
		$parent->replaceChild( $new_node, $node );
		$new_node->setAttribute( 'class', 'wp-block-verse' );

		return $new_node;
	}

	/**
	 * Convert a pullquote node to valid Gutenberg markup.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node.
	 *
	 * @return DomElement The converted node.
	 */
	protected function convert_pullquote_node( DomElement $node, DomElement $parent ) {
		$node_paragraphs = $node->getElementsByTagName( 'p' );
		if ( 0 === $node_paragraphs->length ) {
			return null;
		}

		// Create the figure and blockquote elements for pullquote block.
		$new_node   = new DomElement( 'figure' );
		$blockquote = new DomElement( 'blockquote' );
		$paragraph  = new DomElement( 'p' );
		$quote_text = new DOMText( $node_paragraphs[0]->textContent );

		// Assemble and replace node.
		$parent->replaceChild( $new_node, $node );
		$new_node->appendChild( $blockquote );
		$blockquote->appendChild( $paragraph );
		$paragraph->appendChild( $quote_text );
		$new_node->setAttribute( 'class', 'wp-block-pullquote' );

		return $new_node;
	}

	/**
	 * Handle a button node.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node.
	 *
	 * @return DomElement
	 *
	 * @todo Support multiple types of buttons. For now buttons are removed.
	 */
	protected function convert_button_node( DomElement $node, DomElement $parent ) {
		$parent->removeChild( $node );
		return null;
	}

	/**
	 * Convert an image node to a Gutenberg valid markup.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node.
	 *
	 * @return array An array containing the Block attributes and the new node.
	 *
	 * @todo If the node is a (a) link we need to make this image a link as well.
	 */
	protected function convert_image_node( DomElement $node, DomElement $parent ) {

		// Check if the image needs to be resized
		// Can we already upload the image here?
		/** @var DomElement $image */
		$image = $node->getElementsByTagName( 'img' )[0];

		// if there is no image we can't proceed.
		if ( ! $image ) {
			$parent->removeChild( $node );
			return array(
				'block_attributes' => array(),
				'node'             => null,
			);
		}

		$block_attributes = array();

		$new_node = new DomElement( 'figure' );

		$parent->replaceChild( $new_node, $node );

		$classes = array( 'wp-block-image', 'size-large' );

		// The data we need is set as json data attribute on the img node.
		$image_data = json_decode( $image->getAttribute( 'data-attrs' ), true );

		// Add the image as an attachement post to the WXR.
		$this->generator->add_post(
			array(
				'title'          => urldecode( basename( $image_data['src'] ) ),
				'link'           => $image_data['src'],
				'type'           => 'attachment',
				'attachment_url' => $image_data['src'],
				'metas'          => array(
					array(
						'key'   => '_wp_original_image_link',
						'value' => $image_data['src'],
					),
				),
			)
		);

		// Create the new image element.
		$new_image = new DomElement( 'img' );
		$new_node->appendChild( $new_image );
		$new_image->setAttribute( 'src', $image_data['src'] );
		if ( ! is_null( $image_data['alt'] ) ) {
			$new_image->setAttribute( 'alt', $image_data['alt'] );
		}

		// Deal with resizing.
		if ( $image_data['resizeWidth'] ) {
			$classes[] = 'is-resized';
			$new_image->setAttribute( 'width', $image_data['resizeWidth'] );
			$block_attributes['width'] = $image_data['resizeWidth'];
		}

		// Handle caption if it exists
		$caption = $node->getElementsByTagName( 'figcaption' );
		if ( $caption->length > 0 ) {
			$caption_text                = $caption->item( 0 )->textContent;
			$block_attributes['caption'] = $caption_text;

			// Create figcaption element
			$figcaption = new DomElement( 'figcaption' );
			$new_node->appendChild( $figcaption );
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$figcaption->textContent = $caption_text;
		}

		// Set the classes on the figure element.
		$new_node->setAttribute( 'class', implode( ' ', $classes ) );

		$block_attributes['sizeSlug']        = 'large';
		$block_attributes['linkDestination'] = 'none';

		$result = array(
			'block_attributes' => $block_attributes,
			'node'             => $new_node,
		);

		/**
		 * Filter the image node conversion result.
		 *
		 * Allows modification of the image block attributes and node after
		 * the default conversion. Useful for adjusting image sizes, adding
		 * custom classes, modifying captions, or changing link destinations.
		 *
		 * @since 1.2.0
		 *
		 * @param array      $result {
		 *     The image conversion result.
		 *
		 *     @type array      $block_attributes The image block attributes (sizeSlug, linkDestination, width, caption).
		 *     @type DomElement $node             The figure DOM element for the image block.
		 * }
		 * @param array|null $image_data The decoded image data from the Substack data-attrs attribute.
		 */
		return apply_filters( 'substack_importer_image_result', $result, $image_data );
	}

	/**
	 * Convert the node to a valid Gutenberg separator block.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node to be converted.
	 *
	 * @return DomElement The new node.
	 */
	protected function convert_separator_node( DomElement $node, DomElement $parent ) {

		$new_node = new DomElement( 'hr' );
		$parent->replaceChild( $new_node, $node );
		$new_node->setAttribute( 'class', 'wp-block-separator' );

		return $new_node;
	}

	/**
	 * Convert a node that represents an embed to valid Gutenberg embed block markup.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node to be coverted.
	 *
	 * @return array Containing the block_name, block_attributes and node.
	 */
	protected function convert_embed_node( DomElement $node, DomElement $parent ) {

		$first_class = explode( ' ', $node->getAttribute( 'class' ) )[0];

		/**
		 * Short-circuit the embed node conversion before default handling.
		 *
		 * Return a non-null array to skip the built-in switch statement entirely.
		 * The returned array must have keys: 'node', 'block_attributes', 'block_name'.
		 * Useful for handling unsupported embed types, overriding the default
		 * conversion for a specific provider, or adding entirely new providers.
		 *
		 * @since 1.2.0
		 *
		 * @param array|null $pre_result  Return non-null to short-circuit. Expected keys:
		 *                                'node' (DomElement|null), 'block_attributes' (array),
		 *                                'block_name' (string|null).
		 * @param DomElement $node        The embed DOM node before conversion.
		 * @param DomElement $parent      The parent DOM element.
		 * @param string     $first_class The CSS class identifying the embed type (e.g. 'youtube-wrap', 'tweet').
		 */
		$pre_result = apply_filters(
			'substack_importer_pre_embed_conversion',
			null,
			$node,
			$parent,
			$first_class
		);

		if ( null !== $pre_result ) {
			return $pre_result;
		}

		switch ( $first_class ) {

			case 'youtube-wrap':
				$output = $this->convert_youtube_embed( $node, $parent );
				break;

			case 'vimeo-wrap':
				$output = $this->convert_vimeo_embed( $node, $parent );
				break;

			case 'soundcloud-wrap':
				$output = $this->convert_soundcloud_embed( $node, $parent );
				break;

			case 'tweet':
				$output = $this->convert_tweet_embed( $node, $parent );
				break;

			case 'spotify-wrap':
				$output = $this->convert_spotify_embed( $node, $parent );
				break;

			case 'bandcamp-wrap':
				$output = $this->convert_bandcamp_embed( $node, $parent );
				break;

			case 'github-gist':
				$output = $this->convert_gist_embed( $node, $parent );
				break;

			case 'instagram':
				$output = $this->convert_instagram_embed( $node, $parent );
				break;

			case 'tiktok-wrap':
				$output = $this->convert_tiktok_embed( $node, $parent );
				break;

			default:
				$parent->removeChild( $node );
				$output = array(
					'node'             => null,
					'block_attributes' => array(),
					'block_name'       => null,
				);

		}

		/**
		 * Filter the embed node conversion result.
		 *
		 * Allows modification of the embed block name, attributes, and node
		 * after the default conversion. Useful for adding support for
		 * additional embed providers, modifying embed URLs, or changing
		 * how specific embeds are represented.
		 *
		 * @since 1.2.0
		 *
		 * @param array  $output {
		 *     The embed conversion result.
		 *
		 *     @type string|null      $block_name       The Gutenberg block name (e.g. 'wp:embed').
		 *     @type array            $block_attributes The block attributes (url, type, providerNameSlug, etc.).
		 *     @type DomElement|null  $node             The converted DOM node.
		 * }
		 * @param string $first_class The CSS class identifying the embed type (e.g. 'youtube-wrap', 'tweet').
		 */
		return apply_filters( 'substack_importer_embed_result', $output, $first_class );
	}

	/**
	 * Convert the embed node into Gutenberg markup for a Youtube embed.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node to be coverted.
	 *
	 * @return array Containing the block_name, block_attributes and node.
	 */
	protected function convert_youtube_embed( DomElement $node, DomElement $parent ) {

		$data_attributes = json_decode( $node->getAttribute( 'data-attrs' ), true );

		$block_attributes = array(
			'url'              => 'https://youtu.be/' . $data_attributes['videoId'],
			'type'             => 'video',
			'providerNameSlug' => 'youtube',
			'responsive'       => true,
			'className'        => 'wp-embed-aspect-16-9 wp-has-aspect-ratio',
		);

		$node    = $this->replace_embed_node( $node, $parent, $block_attributes['url'] );
		$classes = 'wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio';
		$node->setAttribute( 'class', $classes );

		return array(
			'block_name'       => 'wp:embed',
			'block_attributes' => $block_attributes,
			'node'             => $node,
		);
	}

	/**
	 * Convert the embed node into Gutenberg markup for a Vimeo embed.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node to be coverted.
	 *
	 * @return array Containing the block_name, block_attributes and node.
	 */
	protected function convert_vimeo_embed( DomElement $node, DomElement $parent ) {

		$data_attributes = json_decode( $node->getAttribute( 'data-attrs' ), true );

		$block_attributes = array(
			'url'              => 'https://vimeo.com/' . $data_attributes['videoId'],
			'type'             => 'video',
			'providerNameSlug' => 'vimeo',
			'responsive'       => true,
			'className'        => 'wp-embed-aspect-16-9 wp-has-aspect-ratio',
		);

		$node    = $this->replace_embed_node( $node, $parent, $block_attributes['url'] );
		$classes = 'wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo wp-embed-aspect-16-9 wp-has-aspect-ratio';
		$node->setAttribute( 'class', $classes );

		return array(
			'block_name'       => 'wp:embed',
			'block_attributes' => $block_attributes,
			'node'             => $node,
		);
	}

	/**
	 * Convert the embed node into Gutenberg markup for a Soundcloud embed.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node to be coverted.
	 *
	 * @return array Containing the block_name, block_attributes and node.
	 */
	protected function convert_soundcloud_embed( DomElement $node, DomElement $parent ) {

		$data_attributes = json_decode( $node->getAttribute( 'data-attrs' ), true );

		// We construct the Soundcloud URL as a combination of Author URL and the
		// Soundcloud Embed ID as this is recognized as a valid embed URL within
		// WordPress.
		$url_parts = explode( '/', $data_attributes['url'] );
		$id        = array_pop( $url_parts );
		$url       = $data_attributes['author_url'] . '/' . $id;

		$block_attributes = array(
			'url'              => $url,
			'type'             => 'rich',
			'providerNameSlug' => 'soundcloud',
			'responsive'       => true,
		);

		$node    = $this->replace_embed_node( $node, $parent, $block_attributes['url'] );
		$classes = 'wp-block-embed is-type-rich is-provider-soundcloud wp-block-embed-soundcloud';
		$node->setAttribute( 'class', $classes );

		return array(
			'block_name'       => 'wp:embed',
			'block_attributes' => $block_attributes,
			'node'             => $node,
		);
	}

	/**
	 * Convert the embed node into Gutenberg markup for a Tweet embed.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node to be coverted.
	 *
	 * @return array Containing the block_name, block_attributes and node.
	 */
	protected function convert_tweet_embed( DomElement $node, DomElement $parent ) {

		$data_attributes = json_decode( $node->getAttribute( 'data-attrs' ), true );

		$block_attributes = array(
			'url'              => $data_attributes['url'],
			'type'             => 'rich',
			'providerNameSlug' => 'twitter',
			'responsive'       => true,
		);

		$node    = $this->replace_embed_node( $node, $parent, $block_attributes['url'] );
		$classes = 'wp-block-embed is-type-rich is-provider-twitter wp-block-embed-twitter';
		$node->setAttribute( 'class', $classes );

		return array(
			'block_name'       => 'wp:embed',
			'block_attributes' => $block_attributes,
			'node'             => $node,
		);
	}

	/**
	 * Convert the embed node into Gutenberg markup for a Spotify embed.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node to be coverted.
	 *
	 * @return array Containing the block_name, block_attributes and node.
	 */
	protected function convert_spotify_embed( DomElement $node, DomElement $parent ) {

		$data_attributes = json_decode( $node->getAttribute( 'data-attrs' ), true );

		$block_attributes = array(
			'url'              => $data_attributes['url'],
			'type'             => 'rich',
			'providerNameSlug' => 'spotify',
			'responsive'       => true,
			'className'        => 'wp-embed-aspect-21-9 wp-has-aspect-ratio',
		);

		$node    = $this->replace_embed_node( $node, $parent, $block_attributes['url'] );
		$classes = 'wp-block-embed is-type-rich is-provider-spotify wp-block-embed-spotify wp-embed-aspect-21-9 wp-has-aspect-ratio';
		$node->setAttribute( 'class', $classes );

		return array(
			'block_name'       => 'wp:embed',
			'block_attributes' => $block_attributes,
			'node'             => $node,
		);
	}

	/**
	 * Converts the node into a shortcode for Bandcamp.
	 *
	 * The shortcode is currently not supported in Core but is available by enabling the embeds module
	 * of the Jetpack plugin.
	 *
	 * @example [bandcamp width=350 height=470 album=473417827 size=large bgcol=ffffff linkcol=0687f5 tracklist=false]
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node to be coverted.
	 *
	 * @return array
	 */
	protected function convert_bandcamp_embed( DomElement $node, DomElement $parent ) {

		$data_attributes = json_decode( $node->getAttribute( 'data-attrs' ), true );

		// The embed URL contains the attributes for the shortcode. Here we extract them and add them to the shortcode.
		preg_match_all( '/[a-z]+=[a-z0-9]+/', $data_attributes['embed_url'], $matches );
		$shortcode = sprintf( '[bandcamp %s]', implode( ' ', $matches[0] ) );

		$new_node = new DOMText( $shortcode );
		$parent->replaceChild( $new_node, $node );

		return array(
			'block_name'       => 'wp:shortcode',
			'block_attributes' => array(),
			'node'             => $new_node,
		);
	}

	/**
	 * Convert a Github Gist node into a shortcode.
	 *
	 * Tries to get the Gist id from the raw link or removes the entire Gist if the ID can not be determined.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node to be coverted.
	 *
	 * @return array
	 */
	protected function convert_gist_embed( DomElement $node, DomElement $parent ) {

		$a_elements = $node->getElementsByTagName( 'a' );

		$url = $a_elements->length > 0
			? $a_elements[0]->getAttribute( 'href' )
			: null;

		if ( ! $url || ! preg_match( '/\/([a-z0-9]+)\/raw/', $a_elements[0]->getAttribute( 'href' ), $matches ) ) {
			$parent->removeChild( $node );
			return array(
				'node'             => null,
				'block_attributes' => array(),
				'block_name'       => null,
			);
		}

		$shortcode = sprintf( '[gist https://gist.github.com/%s]', $matches[1] );

		$new_node = new DOMText( $shortcode );
		$parent->replaceChild( $new_node, $node );

		return array(
			'block_name'       => 'wp:shortcode',
			'block_attributes' => array(),
			'node'             => $new_node,
		);
	}

	/**
	 * Convert the embed node into Gutenberg markup for a TikTok embed.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node to be coverted.
	 *
	 * @return array Containing the block_name, block_attributes and node.
	 */
	protected function convert_tiktok_embed( DomElement $node, DomElement $parent ) {

		$data_attributes = json_decode( $node->getAttribute( 'data-attrs' ), true );

		$block_attributes = array(
			'url'              => $data_attributes['url'],
			'type'             => 'video',
			'providerNameSlug' => 'tiktok',
			'responsive'       => true,
			'className'        => 'wp-embed-aspect-9-16 wp-has-aspect-ratio',
		);

		$node    = $this->replace_embed_node( $node, $parent, $block_attributes['url'] );
		$classes = 'wp-block-embed is-type-video is-provider-tiktok wp-block-embed-tiktok';
		$node->setAttribute( 'class', $classes );

		return array(
			'block_name'       => 'wp:embed',
			'block_attributes' => $block_attributes,
			'node'             => $node,
		);
	}

	/**
	 * Convert Instagram embed to a link to the Instagram post.
	 *
	 * Currently, Instagram embeds are not supported without the installation
	 * of additional plugins. For this reason, the embed will be converted in
	 * a link to the post.
	 *
	 * @param DomElement $node
	 * @param DomElement $parent
	 *
	 * @return array
	 */
	protected function convert_instagram_embed( DomElement $node, DomElement $parent ) {

		$data_attributes = json_decode( $node->getAttribute( 'data-attrs' ), true );

		$new_node  = new DomElement( 'p' );
		$link_node = new DomElement( 'a' );

		$parent->replaceChild( $new_node, $node );

		$new_node->appendChild( $link_node );

		$instagram_link = sprintf( 'https://instagram.com/p/%s/', $data_attributes['instagram_id'] );
		$link_node->setAttribute( 'href', $instagram_link );
		$link_node->setAttribute( 'target', '_blank' );
		$link_node->setAttribute( 'rel', 'noreferrer noopener' );
		$link_node->textContent = $instagram_link; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		return array(
			'block_name'       => 'wp:paragraph',
			'block_attributes' => array(),
			'node'             => $new_node,
		);
	}

	/**
	 * Convert the node to paragraph block indicating the content was paywalled.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node to be converted.
	 *
	 * @return array The converted node data.
	 */
	protected function convert_paywall_node( DomElement $node, DomElement $parent ) {
		/**
		 * Filter the paywall marker text.
		 *
		 * @since 1.2.0
		 *
		 * @param string     $marker_text The default paywall marker text.
		 * @param DomElement $node        The paywall node being converted.
		 * @param DomElement $parent      The parent element.
		 */
		$marker_text = apply_filters(
			'substack_importer_paywall_marker_text',
			__( 'The content below was originally paywalled.', 'substack-importer' ),
			$node,
			$parent
		);

		/**
		 * Filter the entire paywall conversion result.
		 *
		 * Return a non-null value to override the default conversion.
		 * The returned array should have keys: 'node', 'block_attributes', 'block_name'.
		 *
		 * @since 1.2.0
		 *
		 * @param array|null $result The conversion result, null to use default.
		 * @param DomElement $node   The paywall node being converted.
		 * @param DomElement $parent The parent element.
		 */
		$filtered_result = apply_filters(
			'substack_importer_paywall_content',
			null,
			$node,
			$parent
		);

		if ( null !== $filtered_result ) {
			return $filtered_result;
		}

		// Default behavior: create a paragraph with the marker text.
		$new_node = new DomElement( 'p' );
		$text     = new DOMText( $marker_text );

		$parent->replaceChild( $new_node, $node );
		$new_node->appendChild( $text );

		return array(
			'node'             => $new_node,
			'block_attributes' => array(),
			'block_name'       => 'wp:paragraph',
		);
	}

	/**
	 * Removes the Subscription input field.
	 *
	 * @param DomElement $node The node to be converted.
	 * @param DomElement $parent The parent of the node to be converted.
	 *
	 * @return DomElement The new node.
	 */
	protected function convert_subscription_node( DomElement $node, DomElement $parent ) {
		$parent->removeChild( $node );

		return array(
			'node'             => null,
			'block_attributes' => array(),
			'block_name'       => null,
		);
	}

	/**
	 * Replace the Substack Embed node with embed markup that is valid for Gutenberg.
	 *
	 * Returns the replacement node.
	 *
	 * @param DomElement $node
	 * @param DomElement $parent
	 *
	 * @return DomElement
	 */
	protected function replace_embed_node( DomElement $node, DomElement $parent, $content ) {
		$new_node = new DomElement( 'figure' );
		$wrapper  = new DomElement( 'div' );

		$parent->replaceChild( $new_node, $node );
		$new_node->appendChild( $wrapper );
		$wrapper->setAttribute( 'class', 'wp-block-embed__wrapper' );

		// URL needs to be on its own line, see:
		// https://github.com/wordpress/gutenberg/blob/trunk/packages/block-library/src/embed/save.js#L27
		$content = new DOMText( "\n" . $content . "\n" );
		$new_node->getElementsByTagName( 'div' )[0]->appendChild( $content );

		return $new_node;
	}

	/**
	 * Replace an HTML node tag while preserving its attributes and child nodes.
	 *
	 * @param DomElement $node Existing node.
	 * @param DomElement $parent Parent node.
	 * @param string     $new_tag_name New node tag.
	 *
	 * @return DomElement
	 */
	protected function replace_html_node_tag( DomElement $node, DomElement $parent, $new_tag_name ) {
		$document = $node->ownerDocument; //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$new_node = $document->createElement( $new_tag_name );

		if ( $node->hasAttributes() ) { //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			foreach ( $node->attributes as $attribute ) { //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$new_node->setAttribute( $attribute->nodeName, $attribute->nodeValue ); //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			}
		}

		while ( $node->hasChildNodes() ) { //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$new_node->appendChild( $node->firstChild ); //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		$parent->replaceChild( $new_node, $node );

		return $new_node;
	}

	/**
	 * Retrieve additional post information through the Substack Post API.
	 *
	 * The most important data we are after includes author information and comments as this currently is not provided
	 * in the export file.
	 *
	 * It is important to note that comments might not be included or might not contain any information
	 * if the comments are only visible to paid users or if post itself is only accessible to paid users.
	 *
	 * The completeness of information in the response depends on the type of the post (paid vs. public).
	 *
	 * @param string $slug The slug of the post.
	 *
	 * @return string|null Returns a JSON string with post information or null if it could not be retrieved.
	 */
	protected function fetch_post_meta( $slug ) {

		// If the substack url is not set, we skip this step.
		if ( ! $this->substack_url ) {
			return null;
		}

		$post_url = sprintf( '%s/api/v1/posts/%s?all_comments=true', $this->substack_url, $slug );

		$response = wp_remote_get( $post_url, array( 'redirection' => 0 ) );

		if ( is_wp_error( $response ) || 200 !== $response['response']['code'] ) {
			return null;
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Get meta info from the substack export zip. Returns null if no meta was found.
	 *
	 * @param int $id Substack Post ID.
	 *
	 * @return array|null
	 */
	protected function get_post_meta_from_export( $id ) {
		$zip = $this->get_export_zip();

		if ( is_wp_error( $zip ) ) {
			return null;
		}

		$meta = $zip->getFromName( sprintf( 'meta/%s.json', $id ) );

		return $meta
			? json_decode( $meta, true )
			: null;
	}

	/**
	 * Returns a generator yielding posts retrieved from the Substack export.
	 *
	 * If a there was a problem retrieving the Zip file, a WP_Error will be returned.
	 *
	 * @return \Generator|WP_Error
	 */
	public function get_posts() {

		$zip = $this->get_export_zip();

		if ( is_wp_error( $zip ) ) {
			return $zip;
		}

		return $this->get_posts_generator( $zip );
	}

	public function get_subscribers() {
		$zip = $this->get_export_zip();

		if ( is_wp_error( $zip ) ) {
			return $zip;
		}
		$email_list_csv_filename = null;
		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$stat = $zip->statIndex( $i ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			if ( str_starts_with( $stat['name'], 'email_list' ) ) {
				$email_list_csv_filename = $stat['name'];
				break;
			}
		}

		if ( is_null( $email_list_csv_filename ) ) {
			return new WP_Error(
				'no_email_list_in_export_file',
				__( 'No email_list.*.csv file was found in the archive.' ),
				$this->get_error_data( $zip, true )
			);
		}

		$subscriber_csv = $zip->getFromName( $email_list_csv_filename );

		$subscribers = explode( "\n", trim( $subscriber_csv ) );
		$map         = str_getcsv( array_shift( $subscribers ) );

		$mapped_subscribers = [];
		foreach ( $subscribers as $subscriber ) {
			$subscriber_data = str_getcsv( $subscriber );
			// Check if the number of columns matches the number of headers
			if ( count( $subscriber_data ) === count( $map ) ) {
				$mapped_subscribers[] = array_combine( $map, $subscriber_data );
			} else {
				$extra = wp_json_encode(
					[
						'headers' => $map,
						'data'    => $subscriber_data,
					],
					JSON_PRETTY_PRINT
				);
				return new WP_Error(
					'csv_mismatch',
					__( 'The number of columns in the email_list.*.csv file does not match the number of headers.' ),
					$extra
				);
			}
		}

		return [ $mapped_subscribers, $email_list_csv_filename ];
	}

	public function summarize_subscribers( $subscribers ) {
		if ( is_wp_error( $subscribers ) ) {
			return $subscribers;
		}

		$num_subscribers = count( $subscribers );
		$active          = 0;
		$oldest          = strtotime( 'now' );
		$newest          = 0;
		$plans           = [];
		foreach ( $subscribers as $subscriber ) {
			if (
				isset( $subscriber['active_subscription'] )
				&& 'true' === $subscriber['active_subscription']
			) {
				++$active;
			}
			if ( isset( $subscriber['created_at'] ) ) {
				$created_at = strtotime( $subscriber['created_at'] );
				$oldest     = min( $oldest, $created_at );
				$newest     = max( $newest, $created_at );
			}
			if ( isset( $subscriber['plan'] ) ) {
				$plans[ $subscriber['plan'] ] = ( $plans[ $subscriber['plan'] ] ?? 0 ) + 1;
			}
		}

		return [
			'newest'          => gmdate( 'F j, Y', $newest ),
			'oldest'          => gmdate( 'F j, Y', $oldest ),
			'active'          => $active,
			'num_subscribers' => $num_subscribers,
			'plans'           => $plans,
		];
	}

	protected function get_posts_generator( ZipArchive $zip ) {
		$post_csv = $zip->getFromName( 'posts.csv' );

		$posts = explode( "\n", trim( $post_csv ) );
		$map   = str_getcsv( array_shift( $posts ) );

		foreach ( $posts as $post ) {
			$post              = str_getcsv( $post, ',' );
			$post              = array_combine( $map, $post );
			$post['html_body'] = $zip->getFromName( sprintf( 'posts/%s.html', $post['post_id'] ) );
			yield $post;
		}
	}

	/**
	 * Extract the first image URL from post HTML.
	 *
	 * @param string $html HTML body from the Substack export.
	 *
	 * @return string|null
	 */
	protected function get_first_image_url_from_html( $html ) {
		if ( empty( $html ) ) {
			return null;
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return null;
		}

		$images = $document->getElementsByTagName( 'img' );
		if ( 0 === $images->count() ) {
			return null;
		}

		$image = $images->item( 0 );
		if ( ! $image instanceof DOMElement ) {
			return null;
		}

		$data_attrs = $image->getAttribute( 'data-attrs' );
		if ( ! empty( $data_attrs ) ) {
			$decoded_data = json_decode( html_entity_decode( $data_attrs, ENT_QUOTES ), true );
			if ( is_array( $decoded_data ) && ! empty( $decoded_data['src'] ) ) {
				return esc_url_raw( $decoded_data['src'] );
			}
		}

		$src = $image->getAttribute( 'src' );
		if ( ! empty( $src ) ) {
			return esc_url_raw( $src );
		}

		return null;
	}

	/**
	 * Get error data for a zip archive.
	 *
	 * @param ZipArchive|null $zip The zip archive.
	 * @param int|bool|null $file_open_result The result of the file open operation.
	 *
	 * @return array The error data.
	 */
	private function get_error_data( $zip = null, $file_open_result = null ) {
		$files = [];

		if ( is_numeric( $file_open_result ) ) {
			switch ( $file_open_result ) {
				case ZipArchive::ER_INCONS:
					$file_open_result = 'Zip archive inconsistent';
					break;

				case ZipArchive::ER_INVAL:
					$file_open_result = 'Invalid argument';
					break;

				case ZipArchive::ER_MEMORY:
					$file_open_result = 'Malloc failure';
					break;

				case ZipArchive::ER_INVAL:
					$file_open_result = 'No such file';
					break;

				case ZipArchive::ER_NOZIP:
					$file_open_result = 'Not a zip archive';
					break;

				case ZipArchive::ER_OPEN:
					$file_open_result = 'Can\'t open file';
					break;

				case ZipArchive::ER_READ:
					$file_open_result = 'Read error';
					break;

				case ZipArchive::ER_SEEK:
					$file_open_result = 'Seek error';
					break;

				default:
					$file_open_result = sprintf( 'Unknow error code (%s)', $file_open_result );
					break;
			}
		}

		if ( is_bool( $file_open_result ) ) {
			$file_open_result = $file_open_result ? 'Success' : 'Failure, no error code';
		}

		if ( ! empty( $zip ) ) {
			for ( $i = 0; $i < $zip->numFiles; $i++ ) { //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property
				$files[] = $zip->getNameIndex( $i );
			}
		}

		return [
			'file-path'        => $this->export_file_path,
			'file-open-result' => $file_open_result,
			'files'            => $files,
			'substack-url'     => $this->substack_url,
		];
	}

	/**
	 * Get a ZipArchive instance of the export file or return an error if it failed.
	 *
	 * @return WP_Error|ZipArchive The zip archive or a WP_error instance on failure.
	 */
	protected function get_export_zip() {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'missing_zip_extension', __( 'Could not unzip the Substack export file.' ), $this->get_error_data() );
		}

		$zip    = new ZipArchive();
		$result = $zip->open( $this->export_file_path );

		if ( true !== $result || 0 === $zip->numFiles ) { //phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property
			return new WP_Error( 'invalid_export_file', __( 'Could not unzip the Substack export file.' ), $this->get_error_data( $zip, $result ) );
		}

		// If posts.csv was not found in the zip archive, the export is invalid.
		if ( false === $zip->getFromName( 'posts.csv' ) ) {
			return new WP_Error( 'no_posts_in_export_file', __( 'The export file is not a valid Substack export, no posts.csv was found in the archive. ' ), $this->get_error_data( $zip, $result ) );
		}

		return $zip;
	}
}
