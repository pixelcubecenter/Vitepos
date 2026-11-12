jQuery(function ($) {
	'use strict';
	// "Story Post" buttons (Products list column and the Add/Edit product box).
	$(document).on('click', '.pcc-sp-story-btn', function () {
		var $btn = $(this);
		var $msg = $btn.closest('td, .inside').find('.pcc-sp-story-msg').first();
		if ($btn.prop('disabled')) { return; }
		$btn.prop('disabled', true);
		$msg.css('color', '').text('Posting story… (about 20-60 seconds)');
		$.post(pccSpStory.ajaxUrl, {
			action: 'pcc_sp_story_now',
			nonce: pccSpStory.nonce,
			product_id: $btn.data('id')
		}).done(function (res) {
			if (res && res.success) {
				$msg.css('color', res.data.ok ? '#1e5e1e' : '#b32d2e').text(res.data.message);
			} else {
				$msg.css('color', '#b32d2e').text('Could not post the story.');
			}
		}).fail(function () {
			$msg.css('color', '#b32d2e').text('The request timed out — the story may still be posting. Check Social Poster → Queue & History.');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});
});
