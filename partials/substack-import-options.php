<h3><?php esc_html_e( 'Substack Import Options', 'substack-importer' ); ?></h3>
<p>
	<label for="substack-force-draft">
		<input type="checkbox" value="1" name="substack_force_draft" id="substack-force-draft" />
		<?php esc_html_e( 'Import all posts as Draft.', 'substack-importer' ); ?>
	</label>
</p>
<p>
	<label for="substack-set-featured-image">
		<input type="checkbox" value="1" name="substack_set_featured_image" id="substack-set-featured-image" />
		<?php esc_html_e( 'Use the first image as Featured Image.', 'substack-importer' ); ?>
	</label>
</p>
<p><strong><?php esc_html_e( 'Publish Date', 'substack-importer' ); ?></strong></p>
<p>
	<label for="substack-date-mode-original">
		<input
			type="radio"
			name="substack_publish_date_mode"
			id="substack-date-mode-original"
			value="original"
			checked="checked"
		/>
		<?php esc_html_e( 'Use original Substack publish date.', 'substack-importer' ); ?>
	</label><br />
	<label for="substack-date-mode-import">
		<input type="radio" name="substack_publish_date_mode" id="substack-date-mode-import" value="import" />
		<?php esc_html_e( 'Use import date (now) for all imported posts.', 'substack-importer' ); ?>
	</label>
</p>
<p><strong><?php esc_html_e( 'Apply Category or Tag to all imported posts', 'substack-importer' ); ?></strong></p>
<p>
	<input
		type="text"
		name="substack_global_term_name"
		id="substack-global-term-name"
		placeholder="<?php echo esc_attr__( 'Example: substack-imported', 'substack-importer' ); ?>"
	/>
</p>
<p>
	<label for="substack-global-term-taxonomy">
		<?php esc_html_e( 'Apply as', 'substack-importer' ); ?>:
	</label>
	<select name="substack_global_term_taxonomy" id="substack-global-term-taxonomy">
		<option value="post_tag"><?php esc_html_e( 'Tag', 'substack-importer' ); ?></option>
		<option value="category"><?php esc_html_e( 'Category', 'substack-importer' ); ?></option>
	</select>
</p>
