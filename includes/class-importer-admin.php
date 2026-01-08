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

		$this->render_page(
			'pre-import-screen',
			array(
				'wp_importer' => $wp_importer,
			)
		);
	}

	/**
	 * Import the WXR.
	 */
	protected function import() {

		// To allow podcast uploads, we need to allow the mimetype.
		$this->allow_mpga_mime();

		$wp_importer = new WP_Import();

		$wp_importer->fetch_attachments = ( ! empty( $_POST['fetch_attachments'] ) && $wp_importer->allow_fetch_attachments() );
		$file                           = get_attached_file( get_option( self::WXR_FILE_OPTION ) );

		set_time_limit( 0 );

		$wp_importer->import( $file );

		delete_option( self::SUBSTACK_PROGRESS_OPTION );
		delete_option( self::SUBSTACK_URL_OPTION );
		delete_option( self::EXPORT_FILE_OPTION );
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

		extract( $vars, EXTR_SKIP ); //phpcs:ignore WordPress.PHP.DontExtract.extract_extract --internal usage only

		ob_start();
		include __DIR__ . '/../partials/' . $partial . '.php';
		$content = ob_get_clean();

		include __DIR__ . '/../partials/container.php';
	}
}
