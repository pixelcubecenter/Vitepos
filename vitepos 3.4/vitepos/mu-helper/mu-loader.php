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
				$pre_skipped=array( 'wp-asset-clean-up-pro/wpacu.php','wp-cafe/wpcafe.php','wpcafe-pro/wpcafe-pro.php','insert-headers-and-footers/ihaf.php','wpcode-premium/wpcode.php','elementor/elementor.php','ewww-image-optimizer/ewww-image-optimizer.php', 'litespeed-cache/litespeed-cache.php', 'query-monitor/query-monitor.php', 'loco-translate/loco.php', 'loco-automatic-translate-addon-pro/loco-automatic-translate-addon-pro.php', 'hide-my-wp/index.php','woosuite-tax-exemption/woosuite-tax-exemption.php','booked/booked.php','yaypricing/yaypricing.php' );
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