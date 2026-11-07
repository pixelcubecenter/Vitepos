<?php
/**
 * Plugin Name: Vitepos Pro - Point of sale (POS) for WooCommerce
 * Plugin URI: http://vitepos.com
 * Description: It's a Point of Sale plugin for Woocommerce, so fast and easy.
 * Version: 3.4.0
 * Author: appsbd
 * Author URI: http://www.appsbd.com
 * Text Domain: vitepos
 * Domain Path: /languages
 * Requires at least: 5.2
 * Requires PHP:7.2
 * wc require:3.1.0
 *
 * @package Vitepos
 */




require_once 'vitepos/helper/plugin-helper.php';
require_once 'vitepos/core/class-vitepos.php';
require_once 'vitepos/libs/class-vitepos-dependency.php';
use VitePos\Core\VitePos;

if ( true === \VitePos\Libs\Vitepos_Dependency::is_met_requirements( __FILE__ ) ) {

	


	$vtpos = new VitePos( __FILE__ );
	

	$vtpos->start_plugin();
}
