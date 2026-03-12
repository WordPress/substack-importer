<?php

use SubstackImporter\Importer_Admin;

class Tests_Importer_Admin extends WP_UnitTestCase {

	/**
	 * @var Importer_Admin
	 */
	private $importer_admin;

	public function setUp(): void {
		parent::setUp();
		$this->importer_admin = new Importer_Admin();
		$_POST                = array();
	}

	public function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	public function test_inject_substack_import_options_adds_expected_controls() {
		$base_markup = '<form><p class="submit"><input type="submit" value="Import" /></p></form>';
		$markup      = $this->invoke_protected_method( $this->importer_admin, 'inject_substack_import_options', array( $base_markup ) );

		$this->assertStringContainsString( 'Substack Import Options', $markup );
		$this->assertStringContainsString( 'name="substack_force_draft"', $markup );
		$this->assertStringContainsString( 'name="substack_set_featured_image"', $markup );
		$this->assertStringContainsString( 'name="substack_publish_date_mode"', $markup );
		$this->assertStringContainsString( 'name="substack_global_term_name"', $markup );
		$this->assertStringContainsString( 'name="substack_global_term_taxonomy"', $markup );
	}

	public function test_get_import_behavior_from_request_sanitizes_invalid_values() {
		$_POST = array(
			'substack_force_draft'          => '1',
			'substack_set_featured_image'   => '1',
			'substack_publish_date_mode'    => 'invalid-value',
			'substack_global_term_taxonomy' => 'invalid-taxonomy',
			'substack_global_term_name'     => '  Test Imported Term  ',
		);

		$behavior = $this->invoke_protected_method( $this->importer_admin, 'get_import_behavior_from_request' );

		$this->assertTrue( $behavior['force_draft'] );
		$this->assertTrue( $behavior['set_featured_image'] );
		$this->assertSame( 'original', $behavior['date_mode'] );
		$this->assertSame( 'post_tag', $behavior['global_taxonomy'] );
		$this->assertSame( 'Test Imported Term', $behavior['global_term_name'] );
	}

	public function test_filter_import_post_data_processed_applies_draft_and_import_date() {
		$this->set_protected_property(
			$this->importer_admin,
			'import_behavior',
			array(
				'force_draft' => true,
				'date_mode'   => 'import',
			)
		);

		$post_data = array(
			'post_status'   => 'publish',
			'post_date'     => '2020-01-01 00:00:00',
			'post_date_gmt' => '2020-01-01 00:00:00',
		);
		$post      = array(
			'post_date' => '2020-01-01 00:00:00',
		);

		$filtered = $this->importer_admin->filter_import_post_data_processed( $post_data, $post );

		$this->assertSame( 'draft', $filtered['post_status'] );
		$this->assertNotSame( '2020-01-01 00:00:00', $filtered['post_date_gmt'] );
		$this->assertSame( get_date_from_gmt( $filtered['post_date_gmt'] ), $filtered['post_date'] );
	}

	public function test_filter_import_post_terms_adds_global_term_without_duplicates() {
		$this->set_protected_property(
			$this->importer_admin,
			'import_behavior',
			array(
				'global_term_name' => 'substack-imported',
				'global_taxonomy'  => 'post_tag',
			)
		);

		$terms = array(
			array(
				'name'   => 'existing',
				'slug'   => 'existing',
				'domain' => 'post_tag',
			),
		);

		$filtered = $this->importer_admin->filter_import_post_terms( $terms, 0, array() );
		$this->assertCount( 2, $filtered );
		$this->assertSame( 'substack-imported', $filtered[1]['slug'] );
		$this->assertSame( 'post_tag', $filtered[1]['domain'] );

		$filtered_again = $this->importer_admin->filter_import_post_terms( $filtered, 0, array() );
		$this->assertCount( 2, $filtered_again );
	}

	public function test_filter_import_post_meta_adds_original_date_when_using_import_mode() {
		$this->set_protected_property(
			$this->importer_admin,
			'import_behavior',
			array(
				'date_mode' => 'import',
			)
		);

		$post_meta = array();
		$post      = array(
			'post_date' => '2021-03-09T04:44:14.437Z',
		);

		$filtered = $this->importer_admin->filter_import_post_meta( $post_meta, 0, $post );

		$this->assertCount( 1, $filtered );
		$this->assertSame( '_substack_original_post_date', $filtered[0]['key'] );
		$this->assertSame( '2021-03-09T04:44:14.437Z', $filtered[0]['value'] );
	}

	public function test_maybe_assign_featured_image_queues_post_when_attachment_is_unresolved() {
		$this->set_protected_property(
			$this->importer_admin,
			'import_behavior',
			array(
				'set_featured_image' => true,
			)
		);

		$post_id   = self::factory()->post->create();
		$image_url = 'https://example.com/substack-image.jpg';

		$this->importer_admin->maybe_assign_featured_image( $post_id, '_substack_first_image_url', $image_url );

		$queue = $this->get_protected_property( $this->importer_admin, 'featured_image_queue' );

		$this->assertArrayHasKey( $post_id, $queue );
		$this->assertSame( $image_url, $queue[ $post_id ] );
	}

	/**
	 * Invoke a protected method in a test context.
	 *
	 * @param object $target_object Target object.
	 * @param string $method_name Method name.
	 * @param array  $arguments Method arguments.
	 *
	 * @return mixed
	 */
	private function invoke_protected_method( $target_object, $method_name, $arguments = array() ) {
		$reflection_method = new ReflectionMethod( $target_object, $method_name );
		$reflection_method->setAccessible( true );

		return $reflection_method->invokeArgs( $target_object, $arguments );
	}

	/**
	 * Set a protected property in a test context.
	 *
	 * @param object $target_object Target object.
	 * @param string $property_name Property name.
	 * @param mixed  $value Property value.
	 *
	 * @return void
	 */
	private function set_protected_property( $target_object, $property_name, $value ) {
		$reflection_property = new ReflectionProperty( $target_object, $property_name );
		$reflection_property->setAccessible( true );
		$reflection_property->setValue( $target_object, $value );
	}

	/**
	 * Get a protected property in a test context.
	 *
	 * @param object $target_object Target object.
	 * @param string $property_name Property name.
	 *
	 * @return mixed
	 */
	private function get_protected_property( $target_object, $property_name ) {
		$reflection_property = new ReflectionProperty( $target_object, $property_name );
		$reflection_property->setAccessible( true );

		return $reflection_property->getValue( $target_object );
	}
}
