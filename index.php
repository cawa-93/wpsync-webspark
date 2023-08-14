<?php
/**
 * Plugin Name: wpsync-webspark
 * Description: Тестовий плагін для webspark.
 * Version: 0.0.1
 * Author: Олександр Козак
 * Author URI: https://kozack.me
 * Text Domain: woocommerce
 * Domain Path: /i18n/languages/
 * Requires at least: 6.3
 * Requires PHP: 8.1
 */

namespace wpsync;

use WC_Data_Exception;
use WC_Product;
use WC_Product_Simple;

register_deactivation_hook( __FILE__, 'wpsync\cron_deactivate' );

/**
 * Remove scheduled task on plugin deactivation.
 * @return void
 */
function cron_deactivate(): void {
	$timestamp = wp_next_scheduled( 'wpsync_cron_hook' );
	wp_unschedule_event( $timestamp, 'wpsync_cron_hook' );
}


if ( ! wp_next_scheduled( 'wpsync_cron_hook' ) ) {
	wp_schedule_event( time(), 'hourly', 'wpsync_cron_hook' );
}
add_action( 'wpsync_cron_hook', 'wpsync\main_sync' );

// For simple debugging
//cron_deactivate();
//add_action( 'init', 'wpsync\main_sync' );


/**
 * Do synchronization
 * @return void
 * @throws WC_Data_Exception
 */
function main_sync(): void {

	/**
	 * @var $products WC_Product[]
	 */
	$products = wc_get_products( [
		'limit' => - 1,
	] );

	$products_data = load_products();

	// Updating existing products
	foreach ( $products as $product ) {
		$data = $products_data[ $product->get_sku() ];

		unset( $products_data[ $product->get_sku() ] );

		if ( empty( $data ) ) {
			$product->delete( true );
			continue;
		}

		put_product( $data, $product );
	}

	// Add new products
	foreach ( $products_data as $data ) {
		put_product( $data );
	}
}

/**
 * Load product details
 *
 */
function load_products() {

	$ch      = curl_init();
	$timeout = 5; // request timeout 5 second

	curl_setopt( $ch, CURLOPT_URL, 'https://wp.webspark.dev/wp-api/products' );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, $timeout );

	$response = json_decode( curl_exec( $ch ) );
	curl_close( $ch );


	if ( empty($response) || $response->error ) {
		exit( 1 );
	}

	return array_reduce( $response->data, function ( $carry, $item ) {
		$carry[ $item->sku ] = $item;

		return $carry;
	}, [] );
}

/**
 * Create product or update existing
 * @param $data
 * @param WC_Product $product
 *
 * @return void
 * @throws WC_Data_Exception
 */
function put_product( $data, WC_Product $product = new WC_Product_Simple() ): void {
	$product->set_name( $data->name );
	$product->set_price( $data->price );
	$product->set_description( $data->description );
	$product->set_stock_quantity( $data->in_stock );
	$product->set_sku( $data->sku );
	$product->save();
}




