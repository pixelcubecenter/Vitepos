<?php
/**
 * It is for skip unnecessary plugins
 */
if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
	if ( strpos( $_SERVER['REQUEST_URI'], 'vitepos/v1' ) !==false || strpos( $_SERVER['REQUEST_URI'], 'vitepos-user/v1' )!==false) {
		if (!defined('LITESPEED_ESI_OFF')) {
			define('LITESPEED_ESI_OFF',true);
		}
		add_filter(
			'option_active_plugins',
			function ( $plugins ) {
				$pre_skipped = array( 'wp-asset-clean-up-pro/wpacu.php', 'wordpress-seo/wp-seo.php', 'wordpress-seo-premium/wp-seo-premium.php', 'wp-cafe/wpcafe.php', 'wpcafe-pro/wpcafe-pro.php', 'insert-headers-and-footers/ihaf.php', 'wpcode-premium/wpcode.php', 'elementor/elementor.php', 'ewww-image-optimizer/ewww-image-optimizer.php', 'litespeed-cache/litespeed-cache.php', 'query-monitor/query-monitor.php', 'loco-translate/loco.php', 'loco-automatic-translate-addon-pro/loco-automatic-translate-addon-pro.php', 'hide-my-wp/index.php', 'woosuite-tax-exemption/woosuite-tax-exemption.php', 'booked/booked.php', 'yaypricing/yaypricing.php', 'elementor-pro/elementor-pro.php', 'contact-form-7/wp-contact-form-7.php', 'wpforms-lite/wpforms.php', 'gravityforms/gravityforms.php', 'mailchimp-for-wp/mailchimp-for-wp.php', 'google-site-kit/google-site-kit.php', 'monsterinsights/monsterinsights.php', 'redirection/redirection.php', 'broken-link-checker/broken-link-checker.php', 'wp-mail-smtp/wp_mail_smtp.php', 'updraftplus/updraftplus.php', 'wordfence/wordfence.php', 'sucuri-scanner/sucuri.php', 'all-in-one-wp-security-and-firewall/wp-security.php', 'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php', 'wp-rocket/wp-rocket.php', 'autoptimize/autoptimize.php', 'shortpixel-image-optimiser/wp-shortpixel.php', 'imagify/imagify.php', 'regenerate-thumbnails/regenerate-thumbnails.php', 'duplicate-post/duplicate-post.php', 'admin-columns-pro/admin-columns-pro.php' );
				$mu_skipped=get_option('_vt_mu_skipped', []);
				foreach ( $plugins as $k => $plugin ) {
					if ( in_array( $plugin, $pre_skipped ) || in_array( $plugin, $mu_skipped ) ) {
						unset( $plugins[ $k ] );
					}
				}
				$plugins = array_values( $plugins );
				return $plugins;
			}
		);
	}
}