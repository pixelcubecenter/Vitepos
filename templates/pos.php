<?php
/**
 * It is template of pos client
 *
 * @var \VitePos\Modules\POS_Settings $this
 *
 * @package vitepos
 */

?>
<!DOCTYPE html><html dir="<?php echo esc_attr( \VitePos\Modules\POS_Settings::get_lan_dir() ); ?>" lang="en" ><head><meta charset="utf-8">
	<meta http-equiv="X-UA-Compatible" content="IE=edge">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<meta name="theme-color" content="<?php echo esc_html( $this->get_pos_color_code() ); ?>">
	<link rel="icon" href="<?php echo esc_url( $this->get_favicon() ); ?>">
	<link rel="manifest" href="<?php echo esc_url( $this->get_manifest_link() ); ?>">
	<title>pos</title>
	<?php if (function_exists('litespeed_purge')) { ?>
        <!-- LiteSpeed should not ESI cache below -->
	<?php } ?>
	<script>
		var vitePosBase="<?php echo esc_url( get_rest_url( null, 'vitepos/v1' ) ); ?>/";
		var viteposSWJs="<?php echo esc_url( $this->get_sw_link() ); ?>";
		var vitePos= {
			version:"<?php echo esc_html( $this->kernel_object->plugin_version ); ?>",
			heart_bit: 30000,
			m_size:820,
			currencySymbol: '<?php echo esc_html( html_entity_decode( get_woocommerce_currency_symbol() ) ); ?>',
			decimalPlaces: <?php echo esc_attr( wc_get_price_decimals() ); ?>,
			login_type:"<?php echo esc_html( strtoupper( \VitePos\Modules\POS_Settings::get_module_option( 'login_type' ) ) ); ?>",
			ca_prefix:"<?php echo esc_attr( hash( 'crc32b', site_url() ) . '_' ); ?>",
			pos_link:"<?php echo esc_html( \VitePos\Modules\POS_Settings::get_module_instance()->get_pos_link( true ) ); ?>",
			wcnonce: "<?php echo esc_html( wp_create_nonce( 'wp_rest' ) ); ?>",
			date_format: "<?php echo esc_html( vitepos_get_client_date_format() ); ?>",
			time_format: "<?php echo esc_html( vitepos_get_client_time_format() ); ?>",
			wc_amount: function ($amount) {
                try {
                    $amount = parseFloat($amount);
                    return ($amount+(Number.EPSILON*10)).toFixed(vitePos.decimalPlaces);
                } catch (e) {
                    return "0.00";
                }
			},
			wc_price: function ($amount) {
				$amount = parseFloat($amount);
				$amount=vitePos.wc_amount($amount)
				var price_format=<?php echo wp_json_encode( $this->get_price_format_settigns() ); ?>;
				var rx=  /(\d+)(\d{3})/;
				if(price_format.thousand_separator && price_format.thousand_separator != "") {
					$amount = String($amount).replace(/^\d+/, function (w) {
						while (rx.test(w)) {
							w = w.replace(rx, '$1' + price_format.thousand_separator + '$2');
						}
						return w;
					});
				}
				return price_format.price_format.replace('{{amt}}', $amount);
			},
			roundingFactor: "D",//D=Discount, F=Fee, N=none
			assets_path:'<?php $this->get_plugin_esc_url( 'templates/pos-assets' ); ?>/',
			urls:<?php echo wp_json_encode( \VitePos\Modules\POS_Settings::get_urls() ); ?>,
			translationObj: {
				availableLanguages: {
					en_US: "American English"
				},
				defaultLanguage: "en_US",
				translations: {
					"en_US": <?php echo wp_json_encode( \VitePos\Libs\Client_Language::get_pos_languages( $this->kernel_object ) ); ?>
				}
			}
		}</script>
	<?php
	/**
	 * Its for pos client header
	 *
	 * @since 1.0
	 */
	do_action( 'vitepos-client-header' );
	?>
</head>
	<body><noscript><strong> <?php echo esc_html( $this->kernel_object->__( "We're sorry but pos doesn't work properly without JavaScript enabled. Please enable it to continue." ) ); ?></strong></noscript>
	<div id="app">
			<div class="pre-loader">
				<?php echo esc_html( $this->kernel_object->__( 'Please wait ..' ) ); ?>
			</div>
		</div>
	<?php
	/**
	 * Its for pos client header
	 *
	 * @since 1.0
	 */
	do_action( 'vitepos-client-footer' );
	?>
	</body>
	</html>
