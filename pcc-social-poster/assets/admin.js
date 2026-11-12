jQuery(function ($) {
	'use strict';

	/* ---------------- Generate & Post page ---------------- */

	var $catSelect = $('#pcc-sp-category');
	var $productsWrap = $('#pcc-sp-products-wrap');
	var $productsBody = $('#pcc-sp-products-table tbody');
	var $loading = $('#pcc-sp-loading');

	function loadProducts() {
		var categoryId = $catSelect.val();
		if (!categoryId) {
			$productsWrap.hide();
			return;
		}
		$loading.show();
		$productsWrap.hide();
		$.post(pccSp.ajaxUrl, {
			action: 'pcc_sp_get_products',
			nonce: pccSp.nonce,
			category_id: categoryId
		}).done(function (res) {
			$loading.hide();
			if (!res.success) {
				return;
			}
			$productsBody.empty();
			res.data.forEach(function (p) {
				var disabled = p.has_open ? 'disabled' : '';
				var statusLabel = p.has_open ? '<span class="pcc-sp-status pcc-sp-status-queued">Already queued</span>' : '';
				var thumb = p.thumb ? '<img src="' + p.thumb + '" />' : '';
				var row = '<tr>' +
					'<td><input type="checkbox" class="pcc-sp-product-cb" value="' + p.id + '" ' + disabled + ' /></td>' +
					'<td>' + thumb + '</td>' +
					'<td>' + escapeHtml(p.title) + '</td>' +
					'<td>' + escapeHtml(p.price) + '</td>' +
					'<td>' + statusLabel + '</td>' +
					'</tr>';
				$productsBody.append(row);
			});
			$productsWrap.show();
			updateSelectCount();
		});
	}

	if ($catSelect.length) {
		$catSelect.on('change', loadProducts);
	}

	function updateSelectCount() {
		var n = $('.pcc-sp-product-cb:checked').length;
		var max = pccSp.carouselMax || 10;
		var msg = n + ' selected';
		if (n > max) {
			msg += ' — will be split into ' + Math.ceil(n / max) + ' posts of up to ' + max + ' each';
		} else if (n > 1) {
			msg += ' — will post as one combined post per platform';
		}
		$('#pcc-sp-select-count').text(n ? msg : '');
	}

	$(document).on('change', '.pcc-sp-product-cb', updateSelectCount);

	$('#pcc-sp-select-all').on('click', function () {
		$('.pcc-sp-product-cb:not(:disabled)').prop('checked', true);
		updateSelectCount();
	});
	$('#pcc-sp-select-none').on('click', function () {
		$('.pcc-sp-product-cb').prop('checked', false);
		updateSelectCount();
	});

	$('#pcc-sp-queue-btn').on('click', function () {
		var ids = $('.pcc-sp-product-cb:checked').map(function () { return $(this).val(); }).get();
		if (!ids.length) {
			alert('Select at least one product.');
			return;
		}
		var platforms = [];
		if ($('#pcc-sp-platform-ig').is(':checked')) { platforms.push('instagram'); }
		if ($('#pcc-sp-platform-fb').is(':checked')) { platforms.push('facebook'); }
		if ($('#pcc-sp-platform-tiktok').is(':checked')) { platforms.push('tiktok'); }
		if ($('#pcc-sp-platform-threads').is(':checked')) { platforms.push('threads'); }
		if ($('#pcc-sp-platform-linkedin').is(':checked')) { platforms.push('linkedin'); }
		if ($('#pcc-sp-platform-telegram').is(':checked')) { platforms.push('telegram'); }
		if ($('#pcc-sp-platform-telegram-private').is(':checked')) { platforms.push('telegram_private'); }
		if ($('#pcc-sp-platform-tumblr').is(':checked')) { platforms.push('tumblr'); }
		if ($('#pcc-sp-platform-pinterest').is(':checked')) { platforms.push('pinterest'); }
		if ($('#pcc-sp-platform-bluesky').is(':checked')) { platforms.push('bluesky'); }
		if ($('#pcc-sp-platform-mastodon').is(':checked')) { platforms.push('mastodon'); }
		if ($('#pcc-sp-platform-reddit').is(':checked')) { platforms.push('reddit'); }
		if (!platforms.length) {
			alert('Choose at least one platform to post to.');
			return;
		}
		var categoryId = $catSelect.val();
		var method = 'template';
		var $btn = $(this);
		$btn.prop('disabled', true);
		$.post(pccSp.ajaxUrl, {
			action: 'pcc_sp_queue_products',
			nonce: pccSp.nonce,
			category_id: categoryId,
			method: method,
			platforms: platforms,
			product_ids: ids
		}).done(function (res) {
			$btn.prop('disabled', false);
			if (res.success) {
				$('#pcc-sp-queue-result').text('Queued ' + res.data.queued + ' product(s) as ' + res.data.posts + ' post(s). Skipped (already queued): ' + res.data.skipped + '.');
				loadProducts();
			}
		});
	});

	/* ---------------- Generate & Post: Story section ---------------- */

	var $storyCat = $('#pcc-sp-story-category');
	var $storyWrap = $('#pcc-sp-story-products-wrap');
	var $storyBody = $('#pcc-sp-story-products-table tbody');

	function loadStoryProducts() {
		var categoryId = $storyCat.val();
		if (!categoryId) {
			$storyWrap.hide();
			return;
		}
		$('#pcc-sp-story-loading').show();
		$storyWrap.hide();
		$.post(pccSp.ajaxUrl, {
			action: 'pcc_sp_get_products',
			nonce: pccSp.nonce,
			category_id: categoryId,
			story: 1
		}).done(function (res) {
			$('#pcc-sp-story-loading').hide();
			if (!res.success) { return; }
			$storyBody.empty();
			res.data.forEach(function (p) {
				var disabled = p.has_open ? 'disabled' : '';
				var statusLabel = p.has_open ? '<span class="pcc-sp-status pcc-sp-status-queued">Story already queued</span>' : '';
				var thumb = p.thumb ? '<img src="' + p.thumb + '" />' : '';
				$storyBody.append('<tr>' +
					'<td><input type="checkbox" class="pcc-sp-story-cb" value="' + p.id + '" ' + disabled + ' /></td>' +
					'<td>' + thumb + '</td>' +
					'<td>' + escapeHtml(p.title) + '</td>' +
					'<td>' + escapeHtml(p.price) + '</td>' +
					'<td>' + statusLabel + '</td></tr>');
			});
			$storyWrap.show();
			updateStoryCount();
		});
	}

	function updateStoryCount() {
		var n = $('.pcc-sp-story-cb:checked').length;
		$('#pcc-sp-story-select-count').text(n ? n + ' selected — one story each' : '');
	}

	if ($storyCat.length) {
		$storyCat.on('change', loadStoryProducts);
	}
	$(document).on('change', '.pcc-sp-story-cb', updateStoryCount);
	$('#pcc-sp-story-select-all').on('click', function () {
		$('.pcc-sp-story-cb:not(:disabled)').prop('checked', true);
		updateStoryCount();
	});
	$('#pcc-sp-story-select-none').on('click', function () {
		$('.pcc-sp-story-cb').prop('checked', false);
		updateStoryCount();
	});

	$('#pcc-sp-story-queue-btn').on('click', function () {
		var ids = $('.pcc-sp-story-cb:checked').map(function () { return $(this).val(); }).get();
		var $res = $('#pcc-sp-story-result');
		if (!ids.length) { alert('Select at least one product.'); return; }
		var platforms = [];
		if ($('#pcc-sp-story-ig').is(':checked')) { platforms.push('instagram_story'); }
		if ($('#pcc-sp-story-fb').is(':checked')) { platforms.push('facebook_story'); }
		if (!platforms.length) { alert('Choose Instagram and/or Facebook.'); return; }
		var $btn = $(this);
		$btn.prop('disabled', true);
		$.post(pccSp.ajaxUrl, {
			action: 'pcc_sp_queue_stories',
			nonce: pccSp.nonce,
			platforms: platforms,
			product_ids: ids
		}).done(function (res) {
			if (res.success) {
				var d = res.data;
				var msg = 'Queued ' + d.queued + ' story/stories.';
				if (d.skipped) { msg += ' Skipped ' + d.skipped + (d.error ? ' (' + d.error + ')' : ' (already queued)') + '.'; }
				if (d.queued) { msg += ' They start within a minute — or press "Process next post now" in Queue & History.'; }
				$res.text(msg);
				loadStoryProducts();
			} else {
				$res.text('Could not queue the stories.');
			}
		}).fail(function () {
			$res.text('Request failed — try again.');
		}).always(function () { $btn.prop('disabled', false); });
	});

	/* ---------------- Post Manually page ---------------- */

	var manualImages = []; // [{id, url}]

	function renderManualThumbs() {
		var $wrap = $('#pcc-sp-manual-thumbs');
		if (!$wrap.length) { return; }
		$wrap.empty();
		manualImages.forEach(function (img, idx) {
			$wrap.append(
				'<div class="pcc-sp-manual-thumb"><img src="' + img.url + '" />' +
				'<button type="button" class="pcc-sp-manual-remove" data-idx="' + idx + '" title="Remove">✕</button></div>'
			);
		});
		$('#pcc-sp-manual-count').text(manualImages.length ? ' ' + manualImages.length + ' selected' : '');
	}

	var manualFrame;
	$('#pcc-sp-manual-pick').on('click', function (e) {
		e.preventDefault();
		if (!manualFrame) {
			manualFrame = wp.media({ title: 'Choose images to post', multiple: 'add', library: { type: 'image' }, button: { text: 'Use these images' } });
			manualFrame.on('select', function () {
				manualFrame.state().get('selection').each(function (att) {
					var a = att.toJSON();
					if (manualImages.some(function (m) { return m.id === a.id; })) { return; }
					if (manualImages.length >= (pccSp.carouselMax || 10)) { return; }
					var thumb = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
					manualImages.push({ id: a.id, url: thumb });
				});
				renderManualThumbs();
			});
		}
		manualFrame.open();
	});

	$(document).on('click', '.pcc-sp-manual-remove', function () {
		manualImages.splice($(this).data('idx'), 1);
		renderManualThumbs();
	});

	$('#pcc-sp-manual-text').on('input', function () {
		$('#pcc-sp-manual-chars').text($(this).val().length);
	});

	$('#pcc-sp-manual-queue').on('click', function () {
		var $btn = $(this);
		var $res = $('#pcc-sp-manual-result');
		var platforms = $('.pcc-sp-manual-platform:checked').map(function () { return $(this).val(); }).get();
		if (!manualImages.length) { $res.text('Choose at least one image.').css('color', '#b32d2e'); return; }
		if (!$.trim($('#pcc-sp-manual-text').val())) { $res.text('Write some text first.').css('color', '#b32d2e'); return; }
		if (!platforms.length) { $res.text('Choose at least one platform.').css('color', '#b32d2e'); return; }
		$btn.prop('disabled', true);
		$res.text('Queuing…').css('color', '');
		$.post(pccSp.ajaxUrl, {
			action: 'pcc_sp_queue_manual',
			nonce: pccSp.nonce,
			attachment_ids: manualImages.map(function (m) { return m.id; }),
			caption: $('#pcc-sp-manual-text').val(),
			platforms: platforms
		}).done(function (res) {
			if (res.success) {
				$res.text('Queued as post #' + res.data.post_id + ' (' + res.data.images + ' image(s)). See Queue & History.').css('color', '#1e5e1e');
				manualImages = [];
				renderManualThumbs();
				$('#pcc-sp-manual-text').val('');
				$('#pcc-sp-manual-chars').text('0');
			} else {
				$res.text(res.data || 'Could not queue the post.').css('color', '#b32d2e');
			}
		}).fail(function () {
			$res.text('Request failed — try again.').css('color', '#b32d2e');
		}).always(function () { $btn.prop('disabled', false); });
	});

	/* ---------------- Queue & History page ---------------- */

	var $queueBody = $('#pcc-sp-queue-table tbody');

	function loadQueue() {
		if (!$queueBody.length) {
			return;
		}
		$.post(pccSp.ajaxUrl, {
			action: 'pcc_sp_queue_status',
			nonce: pccSp.nonce
		}).done(function (res) {
			if (!res.success) {
				return;
			}
			$queueBody.empty();
			res.data.forEach(function (post) {
				var platforms = (post.platforms || '').split(',');
				var igCell = platforms.indexOf('instagram') === -1
					? '—'
					: platformCell(post.ig_status, post.ig_media_id, post.error_message);
				var fbCell = platforms.indexOf('facebook') === -1
					? '—'
					: platformCell(post.fb_status, post.fb_post_id, post.fb_error_message);
				var tiktokCell = platforms.indexOf('tiktok') === -1
					? '—'
					: platformCell(post.tiktok_status, post.tiktok_post_id, post.tiktok_error_message);
				var threadsCell = platforms.indexOf('threads') === -1
					? '—'
					: platformCell(post.threads_status, post.threads_post_id, post.threads_error_message);

				var linkedinCell = platforms.indexOf('linkedin') === -1
					? '—'
					: platformCell(post.linkedin_status, post.linkedin_post_id, post.linkedin_error_message);

				var telegramCell = platforms.indexOf('telegram') === -1
					? '—'
					: platformCell(post.telegram_status, post.telegram_post_id, post.telegram_error_message);

				var telegramPrivateCell = platforms.indexOf('telegram_private') === -1
					? '—'
					: platformCell(post.telegram_private_status, post.telegram_private_post_id, post.telegram_private_error_message);

				var tumblrCell = platforms.indexOf('tumblr') === -1
					? '—'
					: platformCell(post.tumblr_status, post.tumblr_post_id, post.tumblr_error_message);

				var pinterestCell = platforms.indexOf('pinterest') === -1
					? '—'
					: platformCell(post.pinterest_status, post.pinterest_post_id, post.pinterest_error_message);

				var blueskyCell = platforms.indexOf('bluesky') === -1
					? '—'
					: platformCell(post.bluesky_status, post.bluesky_post_id, post.bluesky_error_message);

				var mastodonCell = platforms.indexOf('mastodon') === -1
					? '—'
					: platformCell(post.mastodon_status, post.mastodon_post_id, post.mastodon_error_message);

				var igStoryCell = platforms.indexOf('instagram_story') === -1
					? '—'
					: platformCell(post.igstory_status, post.igstory_post_id, post.igstory_error_message);

				var fbStoryCell = platforms.indexOf('facebook_story') === -1
					? '—'
					: platformCell(post.fbstory_status, post.fbstory_post_id, post.fbstory_error_message);

				var redditCell = platforms.indexOf('reddit') === -1
					? '—'
					: platformCell(post.reddit_status, post.reddit_post_id, post.reddit_error_message);

				var actions = '';
				if (post.status === 'failed' || post.status === 'partial') {
					actions += '<button type="button" class="button pcc-sp-retry" data-id="' + post.id + '">Retry</button> ';
						actions += '<button type="button" class="button pcc-sp-cancel" data-id="' + post.id + '">Cancel</button> ';
				}
				if (post.status === 'queued' || post.status === 'generating' || post.status === 'posting') {
					actions += '<button type="button" class="button pcc-sp-cancel" data-id="' + post.id + '">Cancel</button>';
				}
				var row = '<tr>' +
					'<td>' + post.id + '</td>' +
					'<td>' + escapeHtml(post.method === 'manual' ? 'manual' : (post.method === 'story' ? 'story' : 'template')) + '</td>' +
					'<td>' + escapeHtml(post.products) + ' (' + post.product_count + ')</td>' +
					'<td><span class="pcc-sp-status pcc-sp-status-' + post.status + '">' + post.status + '</span></td>' +
					'<td>' + igCell + '</td>' +
					'<td>' + fbCell + '</td>' +
					'<td>' + tiktokCell + '</td>' +
					'<td>' + threadsCell + '</td>' +
					'<td>' + linkedinCell + '</td>' +
					'<td>' + telegramCell + '</td>' +
					'<td>' + telegramPrivateCell + '</td>' +
					'<td>' + tumblrCell + '</td>' +
					'<td>' + pinterestCell + '</td>' +
					'<td>' + blueskyCell + '</td>' +
					'<td>' + mastodonCell + '</td>' +
					'<td>' + igStoryCell + '</td>' +
					'<td>' + fbStoryCell + '</td>' +
					'<td>' + redditCell + '</td>' +
					'<td>' + escapeHtml(post.updated_at) + '</td>' +
					'<td>' + actions + '</td>' +
					'</tr>';
				$queueBody.append(row);
			});
		});
	}

	function platformCell(status, successId, errorMessage) {
		if ('posted' === status && successId) {
			return escapeHtml(successId);
		}
		if ('failed' === status && errorMessage) {
			return '<span class="pcc-sp-status pcc-sp-status-failed">failed</span> ' + escapeHtml(errorMessage);
		}
		if (status) {
			return '<span class="pcc-sp-status pcc-sp-status-' + status + '">' + status + '</span>';
		}
		return '—';
	}

	if ($queueBody.length) {
		loadQueue();
		setInterval(function () { if (!document.hidden) { loadQueue(); } }, 15000);
	}

	$(document).on('click', '#pcc-sp-refresh-queue', loadQueue);

	$(document).on('click', '#pcc-sp-run-now', function () {
		var $btn = $(this);
		var $msg = $('#pcc-sp-run-result');
		$btn.prop('disabled', true);
		$msg.text('Working… (generating and posting can take a minute)');
		$.post(pccSp.ajaxUrl, {
			action: 'pcc_sp_run_tick_now',
			nonce: pccSp.nonce
		}).done(function (res) {
			$msg.text(res && res.success && res.data ? res.data.message : 'Something went wrong.');
		}).fail(function () {
			$msg.text('The request timed out — the post may still be running. Press Refresh in a minute.');
		}).always(function () {
			$btn.prop('disabled', false);
			loadQueue();
		});
	});

	$(document).on('click', '.pcc-sp-retry', function () {
		var id = $(this).data('id');
		$.post(pccSp.ajaxUrl, { action: 'pcc_sp_retry_post', nonce: pccSp.nonce, post_id: id }).done(loadQueue);
	});

	$(document).on('click', '.pcc-sp-cancel', function () {
		var id = $(this).data('id');
		$.post(pccSp.ajaxUrl, { action: 'pcc_sp_cancel_post', nonce: pccSp.nonce, post_id: id }).done(loadQueue);
	});

	/* ---------------- Settings page media uploader ---------------- */

	function bindMediaField(prefix) {
		var frame;
		$('#' + prefix + '_upload_btn').on('click', function (e) {
			e.preventDefault();
			if (frame) {
				frame.open();
				return;
			}
			frame = wp.media({ title: 'Choose image', multiple: false, library: { type: 'image' } });
			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				$('#' + prefix + '_attachment_id').val(attachment.id);
				$('#' + prefix + '_preview').attr('src', attachment.url).show();
				$('#' + prefix + '_remove_btn').show();
			});
			frame.open();
		});
		$('#' + prefix + '_remove_btn').on('click', function (e) {
			e.preventDefault();
			$('#' + prefix + '_attachment_id').val('');
			$('#' + prefix + '_preview').hide();
			$(this).hide();
		});
	}
	bindMediaField('template_base');
	bindMediaField('story_template');

	function escapeHtml(str) {
		if (str === null || str === undefined) {
			return '';
		}
		return String(str)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}
});
