<p>
	<?php _e( 'To import content from your Substack Newsletter, upload an export zip file below.', 'substack-importer' ); ?>
</p>
<p>
	<a target="_blank" href="https://support.substack.com/hc/en-us/articles/360037466012-How-do-I-export-my-posts">
		<?php _e( 'How to get an export file from Substack.', 'substack-importer' ); ?>
	</a>
</p>

<?php if ( $progress ) : ?>
<p class="notice">
		<?php printf( __( 'An import is already in progess, you can start a new import or <a href="%s">continue the previous import</a>', 'substack-importer' ), admin_url( 'admin.php?import=substack&action=progress' ) ); ?>
</p>
<?php endif; ?>

<?php if ( ! empty( $error ) ) : ?>
	<p><strong><?php _e( 'Sorry, there has been an error.', 'substack-importer' ); ?></strong>
	<p class="notice notice-error"><?php echo $error; ?></p>
<?php endif; ?>

<?php
wp_import_upload_form(
	add_query_arg(
		array(
			'action'   => 'upload',
			'noheader' => 1,
		)
	)
);
?>


<script>
	let url_input_container = document.createElement('p');
	let url_input_label = document.createElement('label');
	url_input_label.appendChild(document.createTextNode('<?php _e( 'Substack Newsletter Url (Recommended):', 'substack-importer' ); ?>'));
	url_input_label.setAttribute('for', 'substack-url');

	let explanation = document.createElement('p');
	explanation.classList.add('explanation');
	explanation.appendChild(document.createTextNode('<?php esc_html_e( 'The Substack Newsletter Url is needed to import comments and author information.', 'substack-importer' ); ?>'))

	url_input_container.appendChild(explanation);

	let url_input = document.createElement('input');
	url_input.setAttribute('name', 'substack-url');
	url_input.setAttribute('id', 'substack-url');
	url_input.setAttribute('type', 'text');
	url_input.setAttribute('placeholder', 'eg: https://example.substack.com');

	let note = document.createElement('p');
	note.classList.add('note');
	note.appendChild(document.createTextNode("<?php _e( "If you don't know the URL or the Substack Newsletter no longer exists, you can leave the field empty.", 'substack-importer' ); ?>"));

	url_input_container.appendChild(url_input_label);
	url_input_container.appendChild(explanation);
	url_input_container.appendChild(url_input)
	url_input_container.appendChild(note);

	let form = document.querySelector('#import-upload-form p:first-child');
	form.appendChild(url_input_container);

	let file_input = document.getElementById('upload');
	upload.setAttribute('accept', '.zip');

	document.querySelector('label[for="upload"]').textContent = '<?php _e( 'Pick a Substack export file from your computer', 'substack-importer' ); ?>'
</script>
