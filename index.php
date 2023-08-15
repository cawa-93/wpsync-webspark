<?php
/**
 * Plugin Name: wpsync-webspark
 * Description: Тестовий плагін для webspark.
 * Version: 0.0.1
 * Author: Олександр Козак
 * Author URI: https://kozack.me
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
			$image_id = $product->get_image_id();
			wp_delete_attachment( $image_id, true );
			$product->delete( true );
		} else {
			put_product( $data, $product );
		}
	}

	// Add new products
	foreach ( $products_data as $data ) {
		put_product( $data );
	}
}

/**
 * Load product details
 */
function load_products( $attempt = 1 ) {
	$response = json_decode( wp_remote_retrieve_body( wp_remote_get( 'https://wp.webspark.dev/wp-api/products' ) ) );

	if ( empty( $response ) || $response->error ) {
		if ( $attempt >= 5 ) {
			exit( 1 );
		}

		return load_products( $attempt + 1 );
	}

	return array_reduce( $response->data, function ( $carry, $item ) {
		$carry[ $item->sku ] = $item;

		return $carry;
	}, [] );
}

/**
 * Creates a product or updates an existing one
 *
 * @param $data
 * @param WC_Product $product
 *
 * @return void
 * @throws WC_Data_Exception
 */
function put_product( $data, WC_Product $product = new WC_Product_Simple() ): void {

	$product->set_name( $data->name );

	$product->set_description( html_entity_decode( $data->description ) );

	$product->set_stock_quantity( $data->in_stock );

	$product->set_sku( $data->sku );

	$price = preg_replace( '/[^0-9.]/', '', $data->price );

	$product->set_regular_price( $price );

	if ( empty( $product->get_image_id() ) ) {

		if ( empty( $product->get_id() ) ) {
			$product->save();
		}

		$image_id = upload_image_from_url( $data->picture, $data->name, $product->get_id() );
		if ( $image_id !== false ) {
			$product->set_image_id( $image_id );
		}
	}

	$product->save();
}


/**
 * Upload a file to the media library using a URL.
 *
 * @param string $url URL to be uploaded
 * @param string|null $title If set, used as the post_title
 * @param int|string|null $post_id The post ID the media is associated with.
 *
 * @return int|false
 * @version 1.2
 *
 * @see https://gist.github.com/RadGH/966f8c756c5e142a5f489e86e751eacb
 */
function upload_image_from_url( string $url, string $title = null, int|string|null $post_id = 0 ) {
	require_once( ABSPATH . "/wp-load.php" );
	require_once( ABSPATH . "/wp-admin/includes/image.php" );
	require_once( ABSPATH . "/wp-admin/includes/file.php" );
	require_once( ABSPATH . "/wp-admin/includes/media.php" );

	// Download url to a temp file
	$tmp = download_url( $url );
	if ( is_wp_error( $tmp ) ) {
		return false;
	}

	// Get the filename and extension ("photo.png" => "photo", "png")
	$filename  = pathinfo( $url, PATHINFO_FILENAME );
	$extension = pathinfo( $url, PATHINFO_EXTENSION );

	// An extension is required or else WordPress will reject the upload
	if ( ! $extension ) {
		// Look up mime type, example: "/photo.png" -> "image/png"
		$mime = mime_content_type( $tmp );
		$mime = is_string( $mime ) ? sanitize_mime_type( $mime ) : false;

		// Only allow certain mime types because mime types do not always end in a valid extension (see the .doc example below)
		$mime_extensions = array(
			// mime_type         => extension (no period)
			'text/plain'         => 'txt',
			'text/csv'           => 'csv',
			'application/msword' => 'doc',
			'image/jpg'          => 'jpg',
			'image/jpeg'         => 'jpeg',
			'image/gif'          => 'gif',
			'image/png'          => 'png',
			'video/mp4'          => 'mp4',
		);

		if ( isset( $mime_extensions[ $mime ] ) ) {
			// Use the mapped extension
			$extension = $mime_extensions[ $mime ];
		} else {
			// Could not identify extension
			@unlink( $tmp );

			return false;
		}
	}


	// Upload by "sideloading": "the same way as an uploaded file is handled by media_handle_upload"
	$args = array(
		'name'     => "$filename.$extension",
		'tmp_name' => $tmp,
	);

	// Do the upload
	$attachment_id = media_handle_sideload( $args, $post_id, $title );

	// Cleanup temp file
	@unlink( $tmp );

	// Error uploading
	if ( is_wp_error( $attachment_id ) ) {
		return false;
	}

	// Success, return attachment ID (int)
	return (int) $attachment_id;
}

