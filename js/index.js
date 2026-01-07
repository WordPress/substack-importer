jQuery(document).ready(($) => {

	let process_posts = () => {
		let data = {
			action : 'substack_progress'
		};

		$.post(ajaxurl, data, (response) => {
			let progress = (response.processed / response.total) * 100;
			$('#substack-progress .progress-bar-fill').css('width', progress + '%');
			if(response.status === 'processing') {
				process_posts();
			} else {
				window.location = $('#substack-progress').data('pre-import-url');
			}
		});
	};

	if( $('div#substack-progress').length ) {
		process_posts();
	}

});

