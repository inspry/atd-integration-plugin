<?php
/**
 * ATD Inventory Manager
 *
 * Handles inventory synchronization and product availability checks
 *
 * @package ATD_Integration
 * @since 2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ATD Inventory Manager Class
 *
 * Manages inventory synchronization and order completion logic
 */
class ATD_Inventory_Manager {

	/**
	 * ATD API Client instance
	 *
	 * @var ATD_API_Client
	 */
	private ATD_API_Client $api_client;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->api_client = new ATD_API_Client();
	}

	/**
	 * Scrub inventory for a batch of products
	 *
	 * @param string $product_type Product type ('wheel' or 'tire')
	 * @param int $page         Page number for pagination
	 * @param int $per_page     Number of products per page
	 *
	 * @return array|WP_Error Results array or WP_Error on failure
	 */
	public function scrub_inventory_batch( string $product_type = 'wheel', int $page = 1, int $per_page = 2000 ): WP_Error|array {
		if ( ! $this->api_client->is_configured() ) {
			return new WP_Error( 'not_configured', 'API not configured: ' . implode( ', ', $this->api_client->get_missing_config() ) );
		}

		// Validate product type
		$valid_types = array( 'wheel', 'tire' );
		if ( ! in_array( $product_type, $valid_types, true ) ) {
			return new WP_Error( 'invalid_type', 'Product type must be wheel or tire' );
		}

		// Get product tag based on type
		$tag = ( 'tire' === $product_type ) ? 'atdt' : 'atdw';

		// Get products to check
		$products = get_posts( array(
			'post_type'      => 'product',
			'tax_query'      => array(
				array(
					'taxonomy' => 'product_tag',
					'field'    => 'slug',
					'terms'    => $tag
				)
			),
			'fields'         => 'ids',
			'posts_per_page' => $per_page,
			'paged'          => $page
		) );

		if ( empty( $products ) ) {
			return array(
				'message'         => 'No products found for this batch',
				'processed_count' => 0,
				'out_of_stock'    => array()
			);
		}

		$out_of_stock_skus = array();
		$api_error_count = 0;
		$processed_count = 0;

		foreach ( $products as $product_id ) {
			$sku = get_post_meta( $product_id, '_sku', true );
			if ( empty( $sku ) ) {
				continue;
			}

			$result = $this->api_client->get_product_by_sku( $sku );

			if ( is_wp_error( $result ) ) {
				$api_error_count++;
				error_log( sprintf( 'ATD Inventory Error for SKU %s: %s', $sku, $result->get_error_message() ) );

				// If we have many consecutive API errors, it's likely a configuration issue
				if ( $api_error_count >= 5 && $processed_count < 5 ) {
					return new WP_Error(
						'api_configuration_error',
						'Multiple API failures detected. Please check your API credentials and configuration.'
					);
				}
				continue;
			}

			$processed_count++;

			// Check if product exists in ATD
			if ( ! isset( $result->Body->getProductByCriteriaResponse->products->product ) ) {
				// Mark as out of stock
				$this->mark_product_out_of_stock( $product_id );
				$out_of_stock_skus[] = $sku;
			}
		}

		// Final check: if we had significant API errors relative to successful processing
		if ( $api_error_count > 0 && $processed_count === 0 ) {
			return new WP_Error(
				'api_failure',
				sprintf( 'All API requests failed (%d errors). Please check your API credentials.', $api_error_count )
			);
		}

		return array(
			'message'            => 'Inventory scrub completed',
			'processed_count'    => $processed_count,
			'api_error_count'    => $api_error_count,
			'out_of_stock_count' => count( $out_of_stock_skus ),
			'out_of_stock_skus'  => $out_of_stock_skus
		);
	}

	/**
	 * Mark a product as out of stock
	 *
	 * @param int $product_id The WooCommerce product ID
	 */
	private function mark_product_out_of_stock( int $product_id ): void {
		update_post_meta( $product_id, '_stock', 0 );
		update_post_meta( $product_id, '_stock_status', 'outofstock' );
		wp_set_post_terms( $product_id, 'outofstock', 'product_visibility', true );
	}

	/**
	 * Get ATD products that need tracking updates
	 *
	 * @return array Array of order items needing tracking updates
	 */
	public function get_orders_needing_tracking(): array {
		global $wpdb;

		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT
			    order_id,
			    order_itemmeta.order_item_id AS order_item_id,
			    meta_value
			FROM
			    {$wpdb->prefix}woocommerce_order_itemmeta AS order_itemmeta
			LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS order_items
			ON
			    order_itemmeta.order_item_id = order_items.order_item_id
			LEFT JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = order_items.order_id
			WHERE
			    meta_key = %s
			    AND {$wpdb->posts}.post_date BETWEEN CURDATE() - INTERVAL 1 MONTH AND CURDATE() + INTERVAL 1 DAY
			    AND {$wpdb->posts}.post_status NOT IN(%s, %s, %s, %s)
			    AND order_itemmeta.order_item_id NOT IN(
			        SELECT order_item_id
			        FROM {$wpdb->prefix}woocommerce_order_itemmeta
			        WHERE meta_key = %s
			    )
			ORDER BY order_id ASC",
			'atd_order_id',
			'wc-refunded',
			'wc-failed',
			'wc-cancelled',
			'wc-completed',
			'atd_order_tracking_number'
		) );

		return $results;
	}

	/**
	 * Get orders with ATD tracking that might be ready for completion
	 *
	 * @return array Array of orders grouped by order ID
	 */
	public function get_shipped_atd_orders(): array {
		global $wpdb;

		$shipped_items = $wpdb->get_results( $wpdb->prepare(
			"SELECT
			    order_id,
			    meta_value AS product_id,
			    outer_meta.order_item_id
			FROM
			    {$wpdb->prefix}woocommerce_order_itemmeta AS outer_meta
			LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS outer_items ON outer_meta.order_item_id = outer_items.order_item_id
			WHERE
			    outer_meta.order_item_id IN(
			        SELECT inner_items.order_item_id AS order_item_id
			        FROM {$wpdb->prefix}woocommerce_order_itemmeta AS inner_meta
			        LEFT JOIN {$wpdb->prefix}woocommerce_order_items AS inner_items ON inner_meta.order_item_id = inner_items.order_item_id
			        LEFT JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = inner_items.order_id
			        WHERE
			            {$wpdb->posts}.post_date BETWEEN CURDATE() - INTERVAL 1 MONTH AND CURDATE() + INTERVAL 1 DAY
			            AND inner_meta.meta_key = %s
			            AND {$wpdb->posts}.post_status NOT IN(%s, %s, %s, %s)
			    )
			    AND outer_meta.meta_key = %s
			ORDER BY order_id ASC",
			'atd_order_tracking_number',
			'wc-refunded',
			'wc-failed',
			'wc-cancelled',
			'wc-completed',
			'_product_id'
		) );

		// Group by order ID
		$shipped_orders = array();
		foreach ( $shipped_items as $item ) {
			$shipped_orders[ $item->order_id ][] = $item->order_item_id;
		}

		return $shipped_orders;
	}

	/**
	 * Check if an order should be marked as completed
	 *
	 * @param int   $order_id          The WooCommerce order ID
	 * @param array $shipped_item_ids  Array of shipped item IDs
	 * @return bool True if order should be completed
	 */
	public function should_complete_order( $order_id, $shipped_item_ids ): bool {
		global $wpdb;

		// Create placeholders for the IN clause
		$placeholders = implode( ',', array_fill( 0, count( $shipped_item_ids ), '%d' ) );
		$query_params = array_merge( $shipped_item_ids, array( $order_id ) );

		$non_atd_items = $wpdb->get_results( $wpdb->prepare(
			"SELECT
			    order_itemmeta.meta_value AS product_id
			FROM
			    {$wpdb->prefix}woocommerce_order_items AS order_items
			LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS order_itemmeta ON order_itemmeta.order_item_id = order_items.order_item_id
			WHERE
			    order_items.order_item_id NOT IN ($placeholders)
			    AND order_items.order_item_type = 'line_item'
			    AND order_items.order_id = %d
			    AND order_itemmeta.meta_key = '_product_id'",
			$query_params
		) );

		// If no non-ATD items, order can be completed
		if ( empty( $non_atd_items ) ) {
			return true;
		}

		// Check if remaining items are wheel/tire products that don't block completion
		foreach ( $non_atd_items as $item ) {
			if ( has_term( array( 'custom-wheels', 'tires', 'atdt', 'atdw' ), 'product_tag', $item->product_id ) ) {
				return false; // Found non-ATD wheel/tire, don't complete
			}
		}

		return true; // Only non-wheel/tire items remain, can complete
	}
}
