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

?>

<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title><?php esc_html_e( 'Order Payment', 'woocommerce' ); ?></title>
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<div class="custom-order-pay">
	<?php
	
	WC_Shortcode_Checkout::output( array() );
	?>
</div>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        try{
            window.parent.$vitepos.do_action('wc-order-received');
        }catch(e){}
    });
</script>
<?php wp_footer(); ?>
</body>
</html>
