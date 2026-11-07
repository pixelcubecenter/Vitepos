<?php
/**
 * Pay for order form
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/form-pay.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 8.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * It's a short description
 *
 * @var \WC_Order $order It's wc order.
 */
$totals = $order->get_order_item_totals(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

?>
<style>
	html{
		background: #fff;
		padding: 0 !important;
		margin: 0 !important;
	}
	body{
		background: #fff !important;

	}
	#order_review{
		padding: 15px;
		border: 1px solid #e7e3e3;
		margin: 15px auto;
		border-radius: 15px;
		width: 95% !important;
		box-shadow: 0 0 15px -10px #ccc;
        padding-bottom:50px;
	}
	#payment{
		
	}
	#payment .form-row{
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
	}
	#payment ul.wc_payment_methods{
		border-radius: 15px;
		overflow: hidden;
	}
	#payment ul.wc_payment_methods li{
		text-align: left ;
	}
	#place_order{
		width: unset !important;
		max-width: 200px;
		border-radius: 15px;
	}
</style>
<form id="order_review" method="post">
		<?php if ( $totals ) : ?>
			<?php
			foreach ( $totals as $key => $total ) {
				if ( 'order_total' != $key ) {
					continue;
				}
				?>

					<h1 class="text-center">
						<?php echo esc_html( $total['label'] ); ?>
						<?php echo wp_kses_post( $total['value'] ); ?>
					</h1>
				</tr>
			<?php } ?>
		<?php endif; ?>


	<?php
	/**
	 * Triggered from within the checkout/form-pay.php template, immediately before the payment section.
	 *
	 * @since 8.2.0
	 */
	do_action( 'woocommerce_pay_order_before_payment' );
	?>

	<div id="payment">
		<?php if ( $order->needs_payment() ) : ?>
			<ul class="wc_payment_methods payment_methods methods">
				<?php
				if ( ! empty( $available_gateways ) ) {
					foreach ( $available_gateways as $gateway ) {
						wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
					}
				} else {
					echo '<li>';
					wc_print_notice( apply_filters( 'woocommerce_no_available_payment_methods_message', esc_html__( 'Sorry, it seems that there are no available payment methods for your location. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce' ) ), 'notice' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
					echo '</li>';
				}
				?>
			</ul>
		<?php endif; ?>
		<div class="form-row">
			<input type="hidden" name="woocommerce_pay" value="1" />
			<input type="hidden" name="vt" value="Y" />
			<?php wc_get_template( 'checkout/terms.php' ); ?>

			<?php
			/**
			 * Its for check is there any change before process
			 *
			 * @since 3.1.4
			 */
			do_action( 'woocommerce_pay_order_before_submit' );
			?>

			<?php echo apply_filters( 'woocommerce_pay_order_button_html', '<button type="submit" class="button alt' . esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ) . '" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . '</button>' ); ?>

			<?php
			/**
			 * Its for check is there any change before process
			 *
			 * @since 3.1.4
			 */
			do_action( 'woocommerce_pay_order_after_submit' );
			?>

			<?php wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce' ); ?>
		</div>

	</div>
</form>

<script>
	document.addEventListener("DOMContentLoaded", function () {
		const form = document.getElementById("order_review");
		const button = document.getElementById("place_order");

		// Catch form submission (works for saved card and sometimes new card)
		if (form) {
			form.addEventListener("submit", function (e) {
				try {
					window.parent.$vitepos.do_action('wc-payment-processing',{order_id:'<?php echo esc_html( $order->get_id() ); ?>',order_status:'<?php echo esc_html( $order->get_status() ); ?>' });
				}catch (e) {}
			});
		}

		// Catch button click (covers cases where gateway intercepts)
		if (button) {
			button.addEventListener("click", function () {
				try {
					window.parent.$vitepos.do_action('wc-payment-processing',{order_id:'<?php echo esc_html( $order->get_id() ); ?>',order_status:'<?php echo esc_html( $order->get_status() ); ?>' });
				}catch (e) {}
			});
		}

		// --- Payment error observer ---
		// WooCommerce usually injects errors inside .woocommerce-NoticeGroup or form
		const noticeContainer = document.querySelector("#order_review, .woocommerce");

		if (noticeContainer) {
			const observer = new MutationObserver(function (mutations) {
				mutations.forEach(function (mutation) {
					mutation.addedNodes.forEach(function (node) {
						if (node.nodeType === 1) {
							if (node.matches(".woocommerce-error, .woocommerce-error li, .woocommerce-NoticeGroup, .woocommerce-NoticeGroup-checkout")) {
								const errorText = node.innerText.trim();
								try {
									window.parent.$vitepos.do_action("wc-payment-error", {
										order_id: "<?php echo esc_html( $order->get_id() ); ?>",
										order_status: "<?php echo esc_html( $order->get_status() ); ?>",
										message: errorText
									});
								} catch (e) {}
							}
						}
					});
				});
			});

			observer.observe(noticeContainer, { childList: true, subtree: true });
		}
	});
</script>
