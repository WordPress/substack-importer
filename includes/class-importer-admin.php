<?php
/**
 * WP-Admin specific functionality for the plugin
 *
 * @package Substack_Importer
 */

namespace SubstackImporter;

use WXR_Generator\File_Writer;
use WXR_Generator\Generator;
use WXR_Parser;
use WP_Import;
use WP_Error;


/**
 * The admin specific functionality for the Substack Importer Plugin
 *
 *
 */
class Importer_Admin {

	const EXPORT_FILE_OPTION = 'substack-export-attachment';

	const SUBSTACK_URL_OPTION = 'substack-newsletter-url';

	const WXR_FILE_OPTION = 'substack-wxr-attachement';

	const SUBSTACK_PROGRESS_OPTION = 'substack-import-progress';

	/**
	 * Import behavior selected by the user on the pre-import screen.
	 *
	 * @var array<string, mixed>
	 */
	protected $import_behavior = array();

	/**
	 * A queue of post IDs and first image URLs that still need featured image backfill.
	 *
	 * @var array<int, string>
	 */
	protected $featured_image_queue = array();

	public function run() {

		$action = isset( $_GET['action'] ) ? $_GET['action'] : 'start';

		switch ( $action ) {

			case 'start':
			default:
				$this->render_page(
					'start-screen',
					array(
						'progress' => get_option( self::SUBSTACK_PROGRESS_OPTION, false ),
					)
				);

				break;

			case 'upload':
				$upload_result = $this->upload();

				if ( ! is_wp_error( $upload_result ) ) {
					$url = admin_url( 'admin.php?import=substack&action=progress' );
					return wp_safe_redirect( $url );
				}

				require_once ABSPATH . 'wp-admin/admin-header.php';
				$this->render_page(
					'start-screen',
					array(
						'error'    => $upload_result->get_error_message(),
						'progress' => get_option( self::SUBSTACK_PROGRESS_OPTION, false ),
					)
				);

				break;

			case 'progress':
					$this->render_page( 'progress' );
				break;

			case 'pre-import':
				// Convert Substack export to WXR
				$wxr_path = $this->convert_substack_to_wxr();

				// Parse the WXR and render the author mapping step.
				$import_data = $this->parse_wxr( $wxr_path );
				$this->pre_import_page( $import_data );
				break;

			case 'import':
				// Use WordPress importer to import the WXR
				$this->import();
				break;
		}
	}

	/**
	 * Progresses through the posts and downloads additional data (author info, comments)
	 * through the Substack API.
	 *
	 * This method is used as an Ajax Action.
	 *
	 */
	public function progress() {

		$url       = get_option( self::SUBSTACK_URL_OPTION );
		$file      = get_attached_file( get_option( self::EXPORT_FILE_OPTION ) );
		$writer    = new File_Writer( 'php://output' );
		$converter = new Converter( new Generator( $writer ), $file, get_option( self::SUBSTACK_URL_OPTION ) );
		$progress  = get_option( self::SUBSTACK_PROGRESS_OPTION );

		$result = $converter->load_meta_data( $progress, 1 );

		// If no url was set, we can consider all posts as processed.
		if ( ! $url ) {
			$result['processed'] = $result['total'];
		}

		update_option( self::SUBSTACK_PROGRESS_OPTION, $result['processed'] );

		$result['status'] = $result['processed'] === $result['total']
			? 'done' : 'processing';

		wp_send_json( $result );

		exit();
	}

	/**
	 * Try to upload the Substack Export and ensure it is a valid export that can be used in the converter.
	 *
	 * @return bool|WP_Error
	 */
	protected function upload() {
		check_admin_referer( 'import-upload' );
		$file = wp_import_handle_upload();

		// If the upload handler already failed, don't attempt further checks
		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'upload_error', esc_html( $file['error'] ) );
		}

		if ( ! file_exists( $file['file'] ) ) {
			$error = sprintf( __( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'substack-importer' ), esc_html( $file['file'] ) );
			return new WP_Error( 'upload_error', $error );
		}

		if ( mime_content_type( $file['file'] ) !== 'application/zip' ) {
			$error = sprintf( __( 'Invalid file type uploaded. Expected a zip file, got a %s file.', 'substack-importer' ), mime_content_type( $file['file'] ) );
			return new WP_Error( 'upload_error', $error );
		}

		$writer    = new File_Writer( 'php://output' );
		$converter = new Converter( new Generator( $writer ), $file['file'] );

		$posts = $converter->get_posts();

		// Something went wrong getting posts from the zip-file
		if ( is_wp_error( $posts ) ) {
			return $posts;
		}

		// The zip-file was valid and contained a posts.csv but it was empty.
		if ( null === $posts->current() ) {
			return new WP_Error( __( 'No posts were found in the uploaded export.', 'substack-importer' ) );
		}

		// Check the substack URL. If it is not empty, the url must be valid for the uploaded export file.
		$url = ! empty( $_POST['substack-url'] )
			? $this->sanitize_substack_url( $_POST['substack-url'] )
			: null;

		if ( $url && ! $this->validate_substack_url( $url, $converter ) ) {
			return new WP_Error( 'upload_error', __( 'The provided Substack Newsletter URL is invalid', 'substack-importer' ) );
		}

		update_option( self::SUBSTACK_PROGRESS_OPTION, 0 );
		update_option( self::SUBSTACK_URL_OPTION, $url );
		update_option( self::EXPORT_FILE_OPTION, $file['id'] );

		return true;
	}

	/**
	 * Validate that the provided (sanitized) Substack leads to the correct Substack Newsletter.
	 *
	 * @param $url
	 *
	 * @return bool
	 */
	protected function validate_substack_url( $url, Converter $converter ) {

		// We need to get one post ID and check the posts comments endpoint. If we get a 200 response, the provided
		// substack url matches the export file.
		$post = $converter->get_posts()->current();

		$id           = (int) $post['post_id'];
		$api_endpoint = sprintf( '%s/api/v1/post/%d/comments?limit=1', $url, $id );

		$response = wp_remote_get( $api_endpoint );

		return ! is_wp_error( $response ) && 200 === $response['response']['code'];
	}

	/**
	 * Clean up the Substack url provided by the user to only include scheme + host.
	 *
	 * Returns false if the url is invalid and can not be parsed.
	 *
	 * @param string $url URL of Substack newsletter as provided by the user.
	 *
	 * @return string|bool
	 */
	protected function sanitize_substack_url( $url ) {

		// If scheme is missing, add it
		if ( ! preg_match( '|^.*//|', $url ) ) {
			$url = '//' . $url;
		}

		$url_parts = wp_parse_url( $url );

		if ( false === $url_parts ) {
			return false;
		}

		return 'https://' . $url_parts['host'];
	}

	/**
	 * Convert the Substack export to a WXR and render a pre-import.
	 *
	 * @return string The path of the WXR.
	 *
	 * @throws \Exception
	 */
	protected function convert_substack_to_wxr() {

		$file = get_attached_file( get_option( self::EXPORT_FILE_OPTION ) );

		// Temporarily store the WXR before sideloading it.
		$tmp_wxr = wp_tempnam( 'substack-wxr.xml' );

		$writer = new File_Writer( $tmp_wxr );

		$converter = new Converter( new Generator( $writer ), $file );

		// Convert the export file to a WXR.
		$converter->convert();
		$writer->close();

		return $this->store_wxr( $tmp_wxr );
	}

	protected function pre_import_page( $import_data ) {
		$wp_importer = new WP_Import();
		$wp_importer->get_authors_from_import( $import_data );

		// The wordpress-importer renders a form. The following filter overwrites
		// the action url of that form. This ensures the substack-importer will handle the
		// form submission.
		add_filter(
			'admin_url',
			function ( $url ) {

				if ( false === strpos( $url, 'import=wordpress' ) ) { //phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText
					return $url;
				}

				return wp_nonce_url( add_query_arg( array( 'action' => 'import' ) ), 'import-substack' );
			}
		);

		ob_start();
		$wp_importer->import_options();
		$import_options_html = (string) ob_get_clean();
		$import_options_html = $this->inject_substack_import_options( $import_options_html );

		$this->render_page(
			'pre-import-screen',
			array(
				'import_options_html' => $import_options_html,
			)
		);
	}

	/**
	 * Import the WXR.
	 */
	protected function import() {

		// To allow podcast uploads, we need to allow the mimetype.
		$this->allow_mpga_mime();

		$wp_importer           = new WP_Import();
		$this->import_behavior = $this->get_import_behavior_from_request();
		$this->register_import_behavior_hooks();

		$wp_importer->fetch_attachments = ( ! empty( $_POST['fetch_attachments'] ) && $wp_importer->allow_fetch_attachments() );
		$file                           = get_attached_file( get_option( self::WXR_FILE_OPTION ) );

		set_time_limit( 0 );

		$wp_importer->import( $file );

		delete_option( self::SUBSTACK_PROGRESS_OPTION );
		delete_option( self::SUBSTACK_URL_OPTION );
		delete_option( self::EXPORT_FILE_OPTION );
	}

	/**
	 * Inject custom Substack import options into the WordPress Importer form.
	 *
	 * @param string $import_options_html Markup generated by WP_Import::import_options().
	 *
	 * @return string
	 */
	protected function inject_substack_import_options( $import_options_html ) {
		$options_html = $this->render_partial_markup( 'substack-import-options' );

		if ( false !== strpos( $import_options_html, '<p class="submit">' ) ) {
			return str_replace( '<p class="submit">', $options_html . '<p class="submit">', $import_options_html );
		}

		return $import_options_html . $options_html;
	}

	/**
	 * Render a partial template and return the generated markup.
	 *
	 * @param string $partial The name of the partial.
	 * @param array  $vars Variables to load into the partial.
	 *
	 * @return string
	 */
	protected function render_partial_markup( $partial, $vars = array() ) {
		extract( $vars, EXTR_SKIP ); //phpcs:ignore WordPress.PHP.DontExtract.extract_extract --internal usage only

		ob_start();
		include __DIR__ . '/../partials/' . $partial . '.php';

		return (string) ob_get_clean();
	}

	/**
	 * Parse additional import behavior options from the pre-import form.
	 *
	 * @return array<string, mixed>
	 */
	protected function get_import_behavior_from_request() {
		$force_draft = isset( $_POST['substack_force_draft'] ) && '1' === wp_unslash( $_POST['substack_force_draft'] );
		$first_image = isset( $_POST['substack_set_featured_image'] ) && '1' === wp_unslash( $_POST['substack_set_featured_image'] );
		$date_mode   = isset( $_POST['substack_publish_date_mode'] ) ? sanitize_key( wp_unslash( $_POST['substack_publish_date_mode'] ) ) : 'original';

		if ( ! in_array( $date_mode, array( 'original', 'import' ), true ) ) {
			$date_mode = 'original';
		}

		$taxonomy = isset( $_POST['substack_global_term_taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['substack_global_term_taxonomy'] ) ) : 'post_tag';
		if ( ! in_array( $taxonomy, array( 'post_tag', 'category' ), true ) ) {
			$taxonomy = 'post_tag';
		}

		$term_name = isset( $_POST['substack_global_term_name'] ) ? sanitize_text_field( wp_unslash( $_POST['substack_global_term_name'] ) ) : '';

		return array(
			'force_draft'        => $force_draft,
			'set_featured_image' => $first_image,
			'date_mode'          => $date_mode,
			'global_term_name'   => $term_name,
			'global_taxonomy'    => $taxonomy,
		);
	}

	/**
	 * Register import hooks based on selected options.
	 *
	 * @return void
	 */
	protected function register_import_behavior_hooks() {
		add_filter( 'wp_import_post_data_processed', array( $this, 'filter_import_post_data_processed' ), 10, 2 );
		add_filter( 'wp_import_post_terms', array( $this, 'filter_import_post_terms' ), 10, 3 );
		add_filter( 'wp_import_post_meta', array( $this, 'filter_import_post_meta' ), 10, 3 );
		add_action( 'import_post_meta', array( $this, 'maybe_assign_featured_image' ), 10, 3 );
		add_action( 'import_end', array( $this, 'backfill_featured_images' ) );
	}

	/**
	 * Customize post data before wp_insert_post() runs in the importer.
	 *
	 * @param array $post_data Prepared post data.
	 * @param array $post Original parsed WXR post array.
	 *
	 * @return array
	 */
	public function filter_import_post_data_processed( $post_data, $post ) {
		if ( ! empty( $this->import_behavior['force_draft'] ) ) {
			$post_data['post_status'] = 'draft';
		}

		if ( isset( $this->import_behavior['date_mode'] ) && 'import' === $this->import_behavior['date_mode'] ) {
			$now_gmt                    = current_time( 'mysql', true );
			$post_data['post_date_gmt'] = $now_gmt;
			$post_data['post_date']     = get_date_from_gmt( $now_gmt );
		}

		return $post_data;
	}

	/**
	 * Add a global category/tag to all imported posts when configured.
	 *
	 * @param array $terms Terms attached to the current post.
	 * @param int   $post_id Imported post ID.
	 * @param array $post Original parsed WXR post array.
	 *
	 * @return array
	 */
	public function filter_import_post_terms( $terms, $post_id, $post ) {
		if ( empty( $this->import_behavior['global_term_name'] ) ) {
			return $terms;
		}

		$taxonomy = $this->import_behavior['global_taxonomy'];
		$slug     = sanitize_title( $this->import_behavior['global_term_name'] );
		$name     = $this->import_behavior['global_term_name'];

		foreach ( $terms as $term ) {
			if ( isset( $term['domain'], $term['slug'] ) && $taxonomy === $term['domain'] && $slug === $term['slug'] ) {
				return $terms;
			}
		}

		$terms[] = array(
			'name'   => $name,
			'slug'   => $slug,
			'domain' => $taxonomy,
		);

		return $terms;
	}

	/**
	 * Optionally retain original Substack publish date as post meta.
	 *
	 * @param array $post_meta Parsed post meta.
	 * @param int   $post_id Imported post ID.
	 * @param array $post Original parsed WXR post array.
	 *
	 * @return array
	 */
	public function filter_import_post_meta( $post_meta, $post_id, $post ) {
		if ( isset( $this->import_behavior['date_mode'] ) && 'import' === $this->import_behavior['date_mode'] && ! empty( $post['post_date'] ) ) {
			$post_meta[] = array(
				'key'   => '_substack_original_post_date',
				'value' => $post['post_date'],
			);
		}

		return $post_meta;
	}

	/**
	 * Set featured image from imported _substack_first_image_url post meta.
	 *
	 * @param int    $post_id Imported post ID.
	 * @param string $meta_key Imported post meta key.
	 * @param mixed  $meta_value Imported post meta value.
	 *
	 * @return void
	 */
	public function maybe_assign_featured_image( $post_id, $meta_key, $meta_value ) {
		if ( empty( $this->import_behavior['set_featured_image'] ) || '_substack_first_image_url' !== $meta_key ) {
			return;
		}

		if ( has_post_thumbnail( $post_id ) ) {
			return;
		}

		$image_url     = is_scalar( $meta_value ) ? (string) $meta_value : '';
		$attachment_id = $this->find_attachment_by_original_url( $image_url );

		if ( $attachment_id > 0 ) {
			$this->assign_featured_image( $post_id, $attachment_id );
			return;
		}

		if ( '' !== $image_url ) {
			$this->featured_image_queue[ $post_id ] = $image_url;
		}
	}

	/**
	 * Retry featured image assignment at import end once all attachments exist.
	 *
	 * @return void
	 */
	public function backfill_featured_images() {
		if ( empty( $this->import_behavior['set_featured_image'] ) || empty( $this->featured_image_queue ) ) {
			return;
		}

		foreach ( $this->featured_image_queue as $post_id => $image_url ) {
			if ( has_post_thumbnail( $post_id ) ) {
				continue;
			}

			$attachment_id = $this->find_attachment_by_original_url( $image_url );

			if ( $attachment_id <= 0 ) {
				$content_image_url = $this->extract_first_image_url_from_post_content( $post_id );
				if ( ! empty( $content_image_url ) ) {
					$attachment_id = attachment_url_to_postid( $content_image_url );
				}
			}

			if ( $attachment_id > 0 ) {
				$this->assign_featured_image( $post_id, $attachment_id );
			}
		}
	}

	/**
	 * Find imported attachment by original Substack source URL.
	 *
	 * @param string $image_url Original image URL from Substack.
	 *
	 * @return int
	 */
	protected function find_attachment_by_original_url( $image_url ) {
		global $wpdb;

		$normalized_url = $this->normalize_image_url( $image_url );
		if ( '' === $normalized_url ) {
			return 0;
		}

		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				'_wp_original_image_link',
				$normalized_url
			)
		);

		if ( $attachment_id ) {
			return (int) $attachment_id;
		}

		$attachment_id = attachment_url_to_postid( $normalized_url );
		if ( $attachment_id > 0 ) {
			return (int) $attachment_id;
		}

		$url_no_query  = preg_replace( '/[?#].*/', '', $normalized_url );
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				'_wp_original_image_link',
				$url_no_query
			)
		);

		return $attachment_id ? (int) $attachment_id : 0;
	}

	/**
	 * Normalize image URLs for reliable matching.
	 *
	 * @param string $image_url Source URL.
	 *
	 * @return string
	 */
	protected function normalize_image_url( $image_url ) {
		$image_url = html_entity_decode( (string) $image_url, ENT_QUOTES );
		$image_url = trim( $image_url );
		$image_url = rawurldecode( $image_url );

		return esc_url_raw( $image_url );
	}

	/**
	 * Safely assign featured image with a metadata fallback.
	 *
	 * @param int $post_id Post ID.
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return void
	 */
	protected function assign_featured_image( $post_id, $attachment_id ) {
		$assigned = set_post_thumbnail( $post_id, $attachment_id );
		if ( ! $assigned ) {
			update_post_meta( $post_id, '_thumbnail_id', $attachment_id );
		}
	}

	/**
	 * Extract first image URL from imported post content.
	 *
	 * @param int $post_id Imported post ID.
	 *
	 * @return string
	 */
	protected function extract_first_image_url_from_post_content( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || empty( $post->post_content ) ) {
			return '';
		}

		if ( preg_match( '/<img[^>]+src="([^"]+)"/i', $post->post_content, $matches ) ) {
			return $this->normalize_image_url( $matches[1] );
		}

		return '';
	}

	protected function allow_mpga_mime() {
		add_filter(
			'upload_mimes',
			function ( $mimes ) {
				$mimes['mpga'] = 'audio/mpeg';
				return $mimes;
			}
		);
	}

	/**
	 * Parse WXR file
	 *
	 * @param $wxr_path
	 *
	 * @return array|\WP_Error
	 */
	protected function parse_wxr( $wxr_path ) {
		$parser = new WXR_Parser();
		return $parser->parse( $wxr_path );
	}

	/**
	 * Sideload the WXR and store the ID as an option.
	 *
	 * @param string $wxr_path The path of the temporary WXR file that has to be sideloaded.
	 *
	 * @return string The path of the sideloaded WXR
	 */
	protected function store_wxr( $wxr_path ) {

		$filedata = array(
			'error'    => null,
			'tmp_name' => $wxr_path,
			'name'     => 'substackw-wxr.xml',
			'type'     => 'text/plain',
		);

		$overrides = array(
			'test_form' => false,
			'test_type' => false,
		);
		$sideload  = wp_handle_sideload( $filedata, $overrides );

		// Construct the object array.s
		$object = array(
			'post_title'     => wp_basename( $sideload['file'] ),
			'post_content'   => $sideload['url'],
			'post_mime_type' => mime_content_type( $sideload['file'] ),
			'guid'           => $sideload['url'],
			'context'        => 'import',
			'post_status'    => 'private',
		);

		// Save the data.
		$id = wp_insert_attachment( $object, $sideload['file'] );

		/*
		 * Schedule a cleanup for one day from now in case of failed
		 * import or missing wp_import_cleanup() call.
		 */
		wp_schedule_single_event( time() + DAY_IN_SECONDS, 'importer_scheduled_cleanup', array( $id ) );

		update_option( self::WXR_FILE_OPTION, $id );

		return $sideload['file'];
	}

	/**
	 * Render a partial template.
	 *
	 * @param string $partial The name of the partial.
	 * @param array $vars Variables to load into the partial
	 */
	protected function render_page( $partial, $vars = array() ) {
		$content = $this->render_partial_markup( $partial, $vars );

		include __DIR__ . '/../partials/container.php';
	}
}
