<p><?php _e( 'Your posts from Substack are currently being processed. This can take a while...', 'substack-importer' ); ?></p>
<div id="substack-progress" data-pre-import-url="<?php echo wp_nonce_url( add_query_arg( array( 'action' => 'pre-import' ) ), 'pre-import' ); ?>">
	<div class="progress-bar">
		<span class="progress-bar-fill" style="width: 0%;"></span>
	</div>
</div>
