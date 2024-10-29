<?php
/**
 * Uninstall (delete from options table)
 *
 * @package GlueLabsBaghera
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;

}

delete_option( 'woocommerce_baghera_option' );
