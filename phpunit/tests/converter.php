<?php

use SubstackImporter\Converter;

class Tests_Converter extends WP_UnitTestCase {

	/**
	 * Create a zip from a directory.
	 *
	 * Instead of adding zips in the data dir, it is easier to work directly with the intended zip contents and zip them on the fly.
	 *
	 * @param $name
	 *
	 * @return false|string
	 */
	protected function getZipFilePath( $name ) {
		$data_dir = DIR_TESTDATA_SUBSTACK_IMPORTER . '/exports/' . $name;

		$zip_path = tempnam( sys_get_temp_dir(), $name );

		$zip = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::OVERWRITE );

		// Use a directory iterator as addGlob and addPattern don't work across all php versions
		$rdi = new RecursiveDirectoryIterator( $data_dir );
		$ri  = new RecursiveIteratorIterator( $rdi );

		foreach ( $ri as $file_info ) {
			if ( ! $file_info->isFile() ) {
				continue;
			}

			$local = str_replace( $data_dir . '/', '', $file_info->getPathName() );
			$zip->addFile( $file_info->getPathname(), $local );
		}

		$zip->close();

		return $zip_path;
	}

	public function testConverter() {

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->expects( $this->once() )
			->method( 'initialize' );

		$generator->expects( $this->once() )
			->method( 'finalize' );

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		$this->assertTrue( true );
	}

	public function testExportFileDoesNotExistReturnsWPError() {

		$generator = $this->createMock( 'WXR_Generator\Generator' );
		$converter = new Converter( $generator, 'file_does_not_exist.zip' );

		$output = $converter->convert();

		$this->assertWPError( $output );
		$this->assertEquals( 'export_file_not_exist', $output->get_error_code() );
	}

	public function testFileIsNotZipFileReturnsWPError() {
		$generator = $this->createMock( 'WXR_Generator\Generator' );
		$converter = new Converter( $generator, __FILE__ );

		$output = $converter->convert();

		$this->assertWPError( $output );
		$this->assertEquals( 'invalid_export_file', $output->get_error_code() );
	}

	public function testMissingPostCsvInZipReturnsWPError() {
		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$converter = new Converter( $generator, $this->getZipFilePath( 'empty' ) );

		$output = $converter->convert();

		$this->assertWPError( $output );
		$this->assertEquals( 'no_posts_in_export_file', $output->get_error_code() );
	}

	public function testPostIsAddedToGeneratorCorrectly() {

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$post_asserted = false;

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) use ( &$post_asserted ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}

						$post_asserted = true;

						// Check the converted post as it is passed to the Generator to
						// verify the values are set as expected.
						$this->assertTrue( is_array( $post ) );
						$this->assertEquals( 123, $post['id'] );
						$this->assertEquals( '_unknown', $post['author'] );
						$this->assertCount( 1, $post['metas'] );
						$this->assertSame( '_substack_first_image_url', $post['metas'][0]['key'] );
						$this->assertNotEmpty( $post['metas'][0]['value'] );
						$this->assertEmpty( $post['comments'] );
						$this->assertEmpty( $post['post_taxonomies'] );
						$this->assertEquals( '2021-03-09T04:44:14.437Z', $post['date'] );
						$this->assertEquals( '2021-03-09T04:44:14.437Z', $post['post_date'] );
						$this->assertEquals( 'A Sample Substack Post!', $post['title'] );
						$this->assertStringNotContainsString( '<body>', $post['content'] );

						// Test for the presence of Gutenberg blocks in the content
						$this->assertStringContainsString( '<!-- wp:paragraph -->', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:quote -->', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:heading --><h2 class="wp-block-heading">A H1</h2><!-- /wp:heading -->', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:heading --><h2 class="wp-block-heading">A H2</h2><!-- /wp:heading -->', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:heading {"level":3} -->', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:heading {"level":4} -->', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:heading {"level":5} -->', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:heading {"level":6} -->', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:image {"caption":"Non-resized image","sizeSlug":"large","linkDestination":"none"} -->', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:image {"width":250,"caption":"Resized image","sizeSlug":"large","linkDestination":"none"} -->', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:code -->', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:list -->', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:list {"ordered":true} -->', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:verse -->', $post['content'] );

						// Check if the subtitle is added at the beginning of the post
						$this->assertStringStartsWith( '<!-- wp:heading --><h2 class="wp-block-heading">Subtitle Example', $post['content'] );

						// Check embeds in the content
						$provider_slug_pattern = '/wp:embed.+?"providerNameSlug":"%s".+?-->/';

						$this->assertMatchesRegularExpression( sprintf( $provider_slug_pattern, 'youtube' ), $post['content'] );
						$this->assertMatchesRegularExpression( sprintf( $provider_slug_pattern, 'twitter' ), $post['content'] );
						$this->assertMatchesRegularExpression( sprintf( $provider_slug_pattern, 'spotify' ), $post['content'] );
						$this->assertMatchesRegularExpression( sprintf( $provider_slug_pattern, 'vimeo' ), $post['content'] );
						$this->assertMatchesRegularExpression( sprintf( $provider_slug_pattern, 'soundcloud' ), $post['content'] );

						// Check the Bandcamp and gist shortcodes

						$this->assertStringContainsString( '<!-- wp:shortcode -->[bandcamp album=590445194 size=large bgcol=ffffff linkcol=333333 artwork=small transparent=true]', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:shortcode -->[bandcamp size=large bgcol=ffffff linkcol=333333 tracklist=false artwork=small track=3483434005 transparent=true]', $post['content'] );
						$this->assertStringContainsString( '<!-- wp:shortcode -->[gist https://gist.github.com/54164c92d6162dc0b0c0769ec1727786]', $post['content'] );

						// Check paywall content
						$this->assertStringContainsString( "<!-- wp:paragraph --><p>The content below was originally paywalled.</p>\n<!-- /wp:paragraph -->", $post['content'] );

						// Verify that instagram link is added.
						$ig_link = 'https://instagram.com/p/CN8S0jplH9p/';
						$link    = sprintf( '<a href="%s" target="_blank" rel="noreferrer noopener">%s</a>', $ig_link, $ig_link );
						$this->assertStringContainsString( $link, $post['content'] );

						// Check that empty paragraphs don't appear
						$this->assertStringNotContainsString( 'empty-paragraph', $post['content'] );
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		$this->assertTrue( $post_asserted, 'Main post assertions should have run.' );
	}

	public function testPodcastAddsCategory() {

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->expects( $this->once() )
			->method( 'add_category' )
			->willReturnCallback(
				function ( $data ) {
					$this->assertArrayHasKey( 'name', $data );
					$this->assertArrayHasKey( 'slug', $data );
					$this->assertEquals( 'podcast', $data['slug'] );
					$this->assertEquals( 'Podcast', $data['name'] );
				}
			);

		$generator->method( 'add_post' )
			->willReturnCallback(
				function ( $data ) {
					// Skip attachment posts.
					if ( ! isset( $data['content'] ) ) {
						return;
					}
					$this->assertArrayHasKey( 'post_taxonomies', $data );
					$this->assertCount( 1, $data['post_taxonomies'] );
					$this->assertEquals(
						array(
							'name'   => 'Podcast',
							'slug'   => 'podcast',
							'domain' => 'category',
						),
						$data['post_taxonomies'][0]
					);
				}
			);

		$converter = new Converter( $generator, $this->getZipFilePath( 'podcast' ) );

		$converter->convert();
	}

	public function testPodcastAttachmentAdded() {

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$attachment_asserted = false;

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) use ( &$attachment_asserted ) {
						// Only check attachment posts.
						if ( ! isset( $post['type'] ) || 'attachment' !== $post['type'] ) {
							return;
						}

						$attachment_asserted = true;

						$this->assertEquals( 'attachment', $post['type'] );
						$this->assertEquals( 'podcast.mpga', $post['title'] );
						$this->assertEquals( 'http://example.com/podcast.mpga', $post['link'] );
						$this->assertEquals( 'http://example.com/podcast.mpga', $post['attachment_url'] );
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'podcast' ) );

		$converter->convert();

		$this->assertTrue( $attachment_asserted, 'Attachment post assertions should have run.' );
	}

	public function testPodcastPostHasGutenbergAudioBlock() {
		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						$this->assertStringContainsString( '<!-- wp:audio --><figure class="wp-block-audio"><audio controls src="http://example.com/podcast.mpga"></audio><figcaption>Podcast</figcaption></figure><!-- /wp:audio -->', $post['content'] );
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'podcast' ) );

		$converter->convert();
	}

	public function testPostMetaDataAddedToZipFileWhenSubstackUrlIsSet() {

		// Add a pre_http_request filter to prevent an actual request to the substack API.
		$response_body = file_get_contents( DIR_TESTDATA_SUBSTACK_IMPORTER . '/substack-api-response.json' );
		add_filter(
			'pre_http_request',
			function ( $url ) {
				return array(
					'headers'  => array(),
					'body'     => file_get_contents( DIR_TESTDATA_SUBSTACK_IMPORTER . '/substack-api-response.json' ),
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$zip_path  = $this->getZipFilePath( 'example' );
		$converter = new Converter( $generator, $zip_path, 'https://example.substack.com' );

		$converter->load_meta_data();

		// We want to make sure the meta data retrieved through the API is successfully added to the zip for later usage.

		$zip = new ZipArchive();
		$zip->open( $zip_path );

		$result = $zip->getFromName( 'meta/123.json' );

		$this->assertNotFalse( $result );
		$this->assertEquals( $result, $response_body );
	}

	public function testAuthorAndCommentsAddedWhenSubstackUrlProvided() {

		// Add a pre_http_request filter to prevent an actual request to the substack API.
		$response_body = file_get_contents( DIR_TESTDATA_SUBSTACK_IMPORTER . '/substack-api-response.json' );
		add_filter(
			'pre_http_request',
			function ( $url ) {
				return array(
					'headers'  => array(),
					'body'     => file_get_contents( DIR_TESTDATA_SUBSTACK_IMPORTER . '/substack-api-response.json' ),
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$zip_path  = $this->getZipFilePath( 'example' );
		$converter = new Converter( $generator, $zip_path, 'https://example.substack.com' );

		$converter->load_meta_data();

		$generator->expects( $this->exactly( 1 ) )
			->method( 'add_author' )
			->willReturnCallback(
				function ( $author ) {
					$this->assertEquals( 'Substack User', $author['login'] );
				}
			);

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						// Check that the expected amount of comments has been added.
						$this->assertArrayHasKey( 'comments', $post );
						$this->assertCount( 5, $post['comments'] );

						// Check the format of a comment.
						$this->assertEquals(
							array(
								'id'       => 1467713,
								'author'   => 'Substack Subscriber',
								'date'     => '2021-03-11T07:59:52.309Z',
								'date_gmt' => '2021-03-11T07:59:52.309Z',
								'content'  => 'test',
								'parent'   => null,
								'metas'    => array(),
							),
							$post['comments'][0]
						);

						// Check if parent-child relationship between comments is correct.
						$this->assertEquals( 1467523, $post['comments'][2]['parent'] );
						$this->assertEquals( 1467610, $post['comments'][3]['parent'] );
						$this->assertEquals( 1467623, $post['comments'][4]['parent'] );
					}
				);

		$converter->convert();
	}

	public function testIfCommentsStatusClosed() {

		// Add a pre_http_request filter to prevent an actual request to the substack API.

		add_filter(
			'pre_http_request',
			function ( $url ) {
				return array(
					'headers'  => array(),
					'body'     => file_get_contents( DIR_TESTDATA_SUBSTACK_IMPORTER . '/substack-api-no-comments-response.json' ),
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$zip_path  = $this->getZipFilePath( 'example' );
		$converter = new Converter( $generator, $zip_path, 'https://example.substack.com' );

		$converter->load_meta_data();

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						// Check that the expected amount of comments has been added.
						$this->assertArrayHasKey( 'comment_status', $post );
						$this->assertEquals( 'closed', $post['comment_status'] );
					}
				);
		$converter->convert();
	}


	public function testIfPublicPostDoesNotContainMeta() {
		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
					->willReturnCallback(
						function ( $post ) {
							// Skip attachment posts.
							if ( ! isset( $post['content'] ) ) {
								return;
							}
							// Check the converted post as it is passed to the Generator to
							// verify the values are set as expected.
							$this->assertArrayHasKey( 'metas', $post );
							$this->assertCount( 1, $post['metas'] );
							$this->assertSame( '_substack_first_image_url', $post['metas'][0]['key'] );
							$this->assertNotEmpty( $post['metas'][0]['value'] );
						}
					);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();
	}

	public function testIfCommentsStatusOpen() {

		// Add a pre_http_request filter to prevent an actual request to the substack API.

		add_filter(
			'pre_http_request',
			function ( $url ) {
				return array(
					'headers'  => array(),
					'body'     => file_get_contents( DIR_TESTDATA_SUBSTACK_IMPORTER . '/substack-api-response.json' ),
					'response' => array( 'code' => 200 ),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$zip_path  = $this->getZipFilePath( 'example' );
		$converter = new Converter( $generator, $zip_path, 'https://example.substack.com' );

		$converter->load_meta_data();

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						// Check that the expected amount of comments has been added.
						$this->assertArrayHasKey( 'comment_status', $post );
						$this->assertEquals( 'open', $post['comment_status'] );
					}
				);

		$converter->convert();
	}

	/**
	 * Test that the paywall marker text can be filtered.
	 */
	public function testPaywallMarkerTextFilter() {

		$custom_marker = 'Custom paywall marker text';

		add_filter(
			'substack_importer_paywall_marker_text',
			function () use ( $custom_marker ) {
				return $custom_marker;
			}
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) use ( $custom_marker ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						$this->assertStringContainsString(
							'<!-- wp:paragraph --><p>' . $custom_marker . '</p>',
							$post['content']
						);
						// Ensure the default marker is not present.
						$this->assertStringNotContainsString(
							'The content below was originally paywalled.',
							$post['content']
						);
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		remove_all_filters( 'substack_importer_paywall_marker_text' );
	}

	/**
	 * Test that the entire paywall conversion can be overridden.
	 */
	public function testPaywallContentFilter() {

		add_filter(
			'substack_importer_paywall_content',
			function ( $result, $node, $parent_node ) {
				// Create a custom node to replace the paywall.
				$new_node = new DOMElement( 'div' );
				$parent_node->replaceChild( $new_node, $node );
				$new_node->setAttribute( 'class', 'custom-paywall-block' );

				return array(
					'node'             => $new_node,
					'block_attributes' => array( 'className' => 'custom-paywall-block' ),
					'block_name'       => 'wp:group',
				);
			},
			10,
			3
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						// Verify custom block is present.
						$this->assertStringContainsString(
							'<!-- wp:group {"className":"custom-paywall-block"} -->',
							$post['content']
						);
						// Ensure the default paywall paragraph is not present.
						$this->assertStringNotContainsString(
							'The content below was originally paywalled.',
							$post['content']
						);
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		remove_all_filters( 'substack_importer_paywall_content' );
	}

	/**
	 * Test that the raw HTML content can be filtered before Gutenberg conversion.
	 */
	public function testRawContentFilter() {

		$custom_html = '<p>Injected before conversion</p>';

		add_filter(
			'substack_importer_raw_content',
			function ( $html_body, $post, $post_meta ) use ( $custom_html ) {
				// Verify filter receives correct parameters.
				$this->assertIsString( $html_body );
				$this->assertIsArray( $post );
				$this->assertArrayHasKey( 'title', $post );

				// Prepend custom HTML.
				return $custom_html . $html_body;
			},
			10,
			3
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						// Verify the injected content appears in the output.
						$this->assertStringContainsString(
							'Injected before conversion',
							$post['content']
						);
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		remove_all_filters( 'substack_importer_raw_content' );
	}

	/**
	 * Test that the subtitle HTML can be filtered.
	 */
	public function testSubtitleFilter() {

		add_filter(
			'substack_importer_subtitle',
			function ( $heading, $post ) {
				// Verify filter receives correct parameters.
				$this->assertIsString( $heading );
				$this->assertIsArray( $post );
				$this->assertArrayHasKey( 'subtitle', $post );

				// Change from h2 to h3.
				return sprintf( '<h3 class="custom-subtitle">%s</h3>', $post['subtitle'] );
			},
			10,
			2
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						// Verify the subtitle is using h3 instead of h2.
						$this->assertStringContainsString(
							'<h3 class="wp-block-heading">Subtitle Example</h3>',
							$post['content']
						);
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		remove_all_filters( 'substack_importer_subtitle' );
	}

	/**
	 * Test that the subtitle can be removed via filter.
	 */
	public function testSubtitleFilterCanRemoveSubtitle() {

		add_filter(
			'substack_importer_subtitle',
			function () {
				// Return empty string to skip the subtitle.
				return '';
			}
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						// The content should NOT start with the subtitle heading.
						$this->assertStringNotContainsString(
							'<h2>Subtitle Example</h2>',
							$post['content']
						);
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		remove_all_filters( 'substack_importer_subtitle' );
	}

	/**
	 * Test that the post metadata can be filtered.
	 */
	public function testPostMetaFilter() {

		add_filter(
			'substack_importer_post_meta',
			function ( $post_meta, $post, $id ) {
				// Verify filter receives correct parameters.
				$this->assertIsArray( $post );
				$this->assertEquals( 123, $id );

				// Inject custom meta data with author info.
				return array(
					'publishedBylines'          => array(
						array(
							'id'   => 999,
							'name' => 'Filtered Author',
						),
					),
					'comments'                  => array(),
					'write_comment_permissions' => 'everyone',
				);
			},
			10,
			3
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						// Verify the filtered author is used.
						$this->assertEquals( 'Filtered Author', $post['author'] );
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		remove_all_filters( 'substack_importer_post_meta' );
	}

	/**
	 * Test that the before_post action fires before each post is processed.
	 */
	public function testBeforePostAction() {

		$action_called = false;

		add_action(
			'substack_importer_before_post',
			function ( $post, $post_meta, $id ) use ( &$action_called ) {
				$action_called = true;

				// Verify action receives correct parameters.
				$this->assertIsArray( $post );
				$this->assertArrayHasKey( 'title', $post );
				$this->assertEquals( 123, $id );
			},
			10,
			3
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );
		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		$this->assertTrue( $action_called, 'substack_importer_before_post action should have been called.' );

		remove_all_actions( 'substack_importer_before_post' );
	}

	/**
	 * Test that the after_post action fires after each post is added to the WXR.
	 */
	public function testAfterPostAction() {

		$action_called = false;

		add_action(
			'substack_importer_after_post',
			function ( $post_data, $post, $post_meta, $id ) use ( &$action_called ) {
				$action_called = true;

				// Verify action receives the final post data.
				$this->assertIsArray( $post_data );
				$this->assertArrayHasKey( 'content', $post_data );
				$this->assertArrayHasKey( 'title', $post_data );
				$this->assertEquals( 123, $post_data['id'] );
				$this->assertEquals( 123, $id );

				// Verify it also receives the original post data.
				$this->assertIsArray( $post );
				$this->assertArrayHasKey( 'title', $post );
			},
			10,
			4
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );
		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		$this->assertTrue( $action_called, 'substack_importer_after_post action should have been called.' );

		remove_all_actions( 'substack_importer_after_post' );
	}

	/**
	 * Test that node conversions can be filtered.
	 */
	public function testConvertedNodeFilter() {

		add_filter(
			'substack_importer_converted_node',
			function ( $block_data, $node, $node_name ) {
				// Verify filter receives correct parameters.
				$this->assertIsArray( $block_data );
				$this->assertArrayHasKey( 'block_name', $block_data );
				$this->assertArrayHasKey( 'block_attributes', $block_data );
				$this->assertIsString( $node_name );

				// Change all h1 headings to h2.
				if ( 'wp:heading' === $block_data['block_name'] && isset( $block_data['block_attributes']['level'] ) && 1 === $block_data['block_attributes']['level'] ) {
					$block_data['block_attributes']['level'] = 2;
				}

				return $block_data;
			},
			10,
			3
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						// The original h1 heading should now be converted to level 2.
						$this->assertStringNotContainsString(
							'<!-- wp:heading {"level":1} -->',
							$post['content']
						);
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		remove_all_filters( 'substack_importer_converted_node' );
	}

	/**
	 * Test that the converted node filter can skip a node by returning null block_name.
	 */
	public function testConvertedNodeFilterCanSkipNode() {

		add_filter(
			'substack_importer_converted_node',
			function ( $block_data, $node, $node_name ) {
				// Skip all code blocks.
				if ( 'wp:code' === $block_data['block_name'] ) {
					$block_data['block_name'] = null;
				}

				return $block_data;
			},
			10,
			3
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						$this->assertStringNotContainsString(
							'<!-- wp:code -->',
							$post['content']
						);
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		remove_all_filters( 'substack_importer_converted_node' );
	}

	/**
	 * Test that image conversion results can be filtered.
	 */
	public function testImageResultFilter() {

		add_filter(
			'substack_importer_image_result',
			function ( $result, $image_data ) {
				// Verify filter receives correct parameters.
				$this->assertIsArray( $result );
				$this->assertArrayHasKey( 'block_attributes', $result );
				$this->assertArrayHasKey( 'node', $result );

				// Change link destination for all images.
				$result['block_attributes']['linkDestination'] = 'media';

				return $result;
			},
			10,
			2
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						// Verify images now have linkDestination set to media.
						$this->assertStringContainsString(
							'"linkDestination":"media"',
							$post['content']
						);
						$this->assertStringNotContainsString(
							'"linkDestination":"none"',
							$post['content']
						);
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		remove_all_filters( 'substack_importer_image_result' );
	}

	/**
	 * Test that embed conversion results can be filtered.
	 */
	public function testEmbedResultFilter() {

		add_filter(
			'substack_importer_embed_result',
			function ( $output, $first_class ) {
				// Verify filter receives correct parameters.
				$this->assertIsArray( $output );
				$this->assertIsString( $first_class );

				// Add a custom class name to all YouTube embeds.
				if ( 'youtube-wrap' === $first_class && ! empty( $output['block_attributes'] ) ) {
					$output['block_attributes']['className'] = 'custom-youtube-embed';
				}

				return $output;
			},
			10,
			2
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						$this->assertStringContainsString(
							'"className":"custom-youtube-embed"',
							$post['content']
						);
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		remove_all_filters( 'substack_importer_embed_result' );
	}

	/**
	 * Test that embed conversions can be short-circuited with the pre-conversion filter.
	 */
	public function testPreEmbedConversionFilter() {

		add_filter(
			'substack_importer_pre_embed_conversion',
			function ( $pre_result, $node, $parent_node, $first_class ) {
				// Verify filter receives correct parameters.
				$this->assertNull( $pre_result );
				$this->assertInstanceOf( 'DOMElement', $node );
				$this->assertInstanceOf( 'DOMElement', $parent_node );
				$this->assertIsString( $first_class );

				// Override YouTube embeds with a custom block.
				if ( 'youtube-wrap' === $first_class ) {
					$data_attributes = json_decode( $node->getAttribute( 'data-attrs' ), true );

					$new_node = new \DomElement( 'div' );
					$parent_node->replaceChild( $new_node, $node );
					$new_node->setAttribute( 'class', 'custom-video-player' );

					return array(
						'node'             => $new_node,
						'block_attributes' => array(
							'url'       => 'https://youtu.be/' . $data_attributes['videoId'],
							'className' => 'custom-video-player',
						),
						'block_name'       => 'wp:custom/video',
					);
				}

				return $pre_result;
			},
			10,
			4
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						// Verify the YouTube embed was replaced with custom block.
						$this->assertStringContainsString(
							'<!-- wp:custom/video',
							$post['content']
						);
						// The default YouTube embed should not be present.
						$this->assertStringNotContainsString(
							'"providerNameSlug":"youtube"',
							$post['content']
						);
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		remove_all_filters( 'substack_importer_pre_embed_conversion' );
	}

	/**
	 * Test that the podcast audio block can be filtered.
	 */
	public function testAudioBlockFilter() {

		add_filter(
			'substack_importer_audio_block',
			function ( $block, $audio_url ) {
				// Verify filter receives correct parameters.
				$this->assertIsString( $block );
				$this->assertIsString( $audio_url );
				$this->assertEquals( 'http://example.com/podcast.mpga', $audio_url );

				// Replace with a custom audio player block.
				return sprintf(
					'<!-- wp:custom/audio-player {"url":"%s"} --><div class="custom-audio-player" data-src="%s"></div><!-- /wp:custom/audio-player -->',
					$audio_url,
					$audio_url
				);
			},
			10,
			2
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						// Verify the custom audio block is present.
						$this->assertStringContainsString(
							'<!-- wp:custom/audio-player',
							$post['content']
						);
						// The default audio block should not be present.
						$this->assertStringNotContainsString(
							'<!-- wp:audio -->',
							$post['content']
						);
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'podcast' ) );

		$converter->convert();

		remove_all_filters( 'substack_importer_audio_block' );
	}

	/**
	 * Test that post content can be modified after Gutenberg conversion.
	 */
	public function testPostContentAfterConversionFilter() {

		$wrapper_start = '<!-- wp:group {"className":"test-wrapper"} --><div class="wp-block-group test-wrapper">';
		$wrapper_end   = '</div><!-- /wp:group -->';

		add_filter(
			'substack_importer_post_content_after_conversion',
			function ( $post_content, $post ) use ( $wrapper_start, $wrapper_end ) {
				// Verify filter receives correct parameters.
				$this->assertIsString( $post_content );
				$this->assertIsArray( $post );
				$this->assertArrayHasKey( 'title', $post );

				// Wrap the entire content in a group block.
				return $wrapper_start . $post_content . $wrapper_end;
			},
			10,
			3
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) use ( $wrapper_start, $wrapper_end ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						// Verify the content is wrapped.
						$this->assertStringStartsWith( $wrapper_start, $post['content'] );
						$this->assertStringEndsWith( $wrapper_end, $post['content'] );
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		remove_all_filters( 'substack_importer_post_content_after_conversion' );
	}

	/**
	 * Test that the post content after conversion filter can wrap paywalled content.
	 */
	public function testPostContentAfterConversionFilterWrapsPaywalledContent() {

		$paywall_marker   = "<!-- wp:paragraph --><p>The content below was originally paywalled.</p>\n<!-- /wp:paragraph -->";
		$restricted_start = '<!-- wp:test/restricted -->';
		$restricted_end   = '<!-- /wp:test/restricted -->';

		add_filter(
			'substack_importer_post_content_after_conversion',
			function ( $post_content ) use ( $paywall_marker, $restricted_start, $restricted_end ) {
				$parts = explode( $paywall_marker, $post_content );

				if ( count( $parts ) > 1 ) {
					$free_content = $parts[0];
					$paid_content = $parts[1];

					return $free_content . $restricted_start . $paid_content . $restricted_end;
				}

				return $post_content;
			},
			10,
			3
		);

		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) use ( $restricted_start, $restricted_end, $paywall_marker ) {
						// Skip attachment posts.
						if ( ! isset( $post['content'] ) ) {
							return;
						}
						// Verify the paywall marker is removed (it's used as the split point).
						$this->assertStringNotContainsString( $paywall_marker, $post['content'] );

						// Verify the restricted block wrapper is present.
						$this->assertStringContainsString( $restricted_start, $post['content'] );
						$this->assertStringContainsString( $restricted_end, $post['content'] );

						// Verify content after paywall is wrapped (e.g., the resized image that was after paywall).
						$this->assertMatchesRegularExpression(
							'/' . preg_quote( $restricted_start, '/' ) . '.*Resized image.*' . preg_quote( $restricted_end, '/' ) . '/s',
							$post['content']
						);
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();

		remove_all_filters( 'substack_importer_post_content_after_conversion' );
	}
}
