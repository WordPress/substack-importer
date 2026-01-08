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

		$will_callback = function ( $post ) {
			// Check the converted post as it is passed to the Generator to
			// verify the values are set as expected.
			$this->assertTrue( is_array( $post ) );
			$this->assertEquals( 123, $post['id'] );
			$this->assertEquals( '_unknown', $post['author'] );
			$this->assertEmpty( $post['metas'] );
			$this->assertEmpty( $post['comments'] );
			$this->assertEmpty( $post['post_taxonomies'] );
			$this->assertEquals( '2021-03-09T04:44:14.437Z', $post['date'] );
			$this->assertEquals( '2021-03-09T04:44:14.437Z', $post['post_date'] );
			$this->assertEquals( 'A Sample Substack Post!', $post['title'] );
			$this->assertStringNotContainsString( '<body>', $post['content'] );

			// Test for the presence of Gutenberg blocks in the content
			$this->assertStringContainsString( '<!-- wp:paragraph -->', $post['content'] );
			$this->assertStringContainsString( '<!-- wp:quote -->', $post['content'] );
			$this->assertStringContainsString( '<!-- wp:heading {"level":1} -->', $post['content'] );
			$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $post['content'] );
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
			$this->assertStringStartsWith( '<!-- wp:heading {"level":2} --><h2>Subtitle Example', $post['content'] );

			// Check embeds in the content
			$provider_slug_pattern = '/wp:embed.+?"providerNameSlug":"%s".+?-->/';

			$this->assertRegExp( sprintf( $provider_slug_pattern, 'youtube' ), $post['content'] );
			$this->assertRegExp( sprintf( $provider_slug_pattern, 'twitter' ), $post['content'] );
			$this->assertRegExp( sprintf( $provider_slug_pattern, 'spotify' ), $post['content'] );
			$this->assertRegExp( sprintf( $provider_slug_pattern, 'vimeo' ), $post['content'] );
			$this->assertRegExp( sprintf( $provider_slug_pattern, 'soundcloud' ), $post['content'] );

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
		};

		$generator->expects( $this->at( 3 ) )
				->method( 'add_post' )
				->willReturnCallback( $will_callback );

		$converter = new Converter( $generator, $this->getZipFilePath( 'example' ) );

		$converter->convert();
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

		$generator->expects( $this->at( 2 ) )
			->method( 'add_post' )
			->willReturnCallback(
				function ( $data ) {
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

		$generator->expects( $this->at( 1 ) )
				->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						$this->assertEquals( 'attachment', $post['type'] );
						$this->assertEquals( 'podcast.mpga', $post['title'] );
						$this->assertEquals( 'http://example.com/podcast.mpga', $post['link'] );
						$this->assertEquals( 'http://example.com/podcast.mpga', $post['attachment_url'] );
					}
				);

		$converter = new Converter( $generator, $this->getZipFilePath( 'podcast' ) );

		$converter->convert();
	}

	public function testPodcastPostHasGutenbergAudioBlock() {
		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$generator->expects( $this->at( 2 ) )
				->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
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

		$generator->expects( $this->at( 3 ) )
				->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
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

		$generator->expects( $this->at( 3 ) )
				->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Check that the expected amount of comments has been added.
						$this->assertArrayHasKey( 'comment_status', $post );
						$this->assertEquals( 'closed', $post['comment_status'] );
					}
				);
		$converter->convert();
	}


	public function testIfPublicPostDoesNotContainMeta() {
		$generator = $this->createMock( 'WXR_Generator\Generator' );

		$will_callback = function ( $post ) {
			// Check the converted post as it is passed to the Generator to
			// verify the values are set as expected.
			$this->assertArrayHasKey( 'metas', $post );
			$this->assertEquals( 0, count( $post['metas'] ) );
		};

		$generator->expects( $this->at( 3 ) )
					->method( 'add_post' )
					->willReturnCallback( $will_callback );

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

		$generator->expects( $this->at( 3 ) )
				->method( 'add_post' )
				->willReturnCallback(
					function ( $post ) {
						// Check that the expected amount of comments has been added.
						$this->assertArrayHasKey( 'comment_status', $post );
						$this->assertEquals( 'open', $post['comment_status'] );
					}
				);

		$converter->convert();
	}
}
