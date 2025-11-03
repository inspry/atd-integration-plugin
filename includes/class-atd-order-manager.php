<?php
/**
 * ATD Order Manager
 *
 * Handles order placement and tracking for ATD items
 *
 * @package ATD_Integration
 * @since 2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ATD Order Manager Class
 *
 * Manages order placement and tracking operations
 */
class ATD_Order_Manager {

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
	 * Extract shipping data from WooCommerce order
	 *
	 * @param WC_Order $order The WooCommerce order object
	 *
	 * @return array Formatted shipping data array
	 */
	private function extract_shipping_data( WC_Order $order ): array {
		return array(
			'first_name' => $order->get_shipping_first_name(),
			'last_name'  => $order->get_shipping_last_name(),
			'address_1'  => $order->get_shipping_address_1(),
			'address_2'  => $order->get_shipping_address_2(),
			'state'      => $order->get_shipping_state(),
			'city'       => $order->get_shipping_city(),
			'postcode'   => $order->get_shipping_postcode(),
			'phone'      => preg_replace( '/\D/', '', $order->get_billing_phone() ),
			'email'      => $order->get_billing_email(),
		);
	}

	/**
	 * Place an order item with ATD
	 *
	 * @param int $order_id   The WooCommerce order ID
	 * @param int $product_id The WooCommerce product ID
	 * @param int $item_id    The WooCommerce order item ID
	 *
	 * @return array|WP_Error Success array or WP_Error on failure
	 */
	public function place_order_item( int $order_id, int $product_id, int $item_id ): array|WP_Error {
		// Validate inputs
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'invalid_order', 'Order not found' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'invalid_product', 'Product not found' );
		}

		$item = new WC_Order_Item_Product( $item_id );
		if ( ! $item ) {
			return new WP_Error( 'invalid_item', 'Order item not found' );
		}

		// Check if already ordered
		$existing_order_id = wc_get_order_item_meta( $item_id, 'atd_order_id', true );
		if ( ! empty( $existing_order_id ) ) {
			return new WP_Error( 'already_ordered', 'Item already ordered with ATD ID: ' . $existing_order_id );
		}

		wc_update_order_item_meta( $item_id, 'atd_order_lock', '0' );

		// Check for processing lock
		$lock = wc_get_order_item_meta( $item_id, 'atd_order_lock', true );
		if ( '1' === $lock ) {
			return new WP_Error( 'processing_locked', 'Order placement already in progress' );
		}

		// Set processing lock
		wc_update_order_item_meta( $item_id, 'atd_order_lock', '1' );

		// Prepare order data
		$order_data = array(
			'order_number' => $order->get_order_number(),
			'sku'          => $product->get_sku(),
			'quantity'     => $item->get_quantity(),
			'shipping'     => $this->extract_shipping_data( $order ),
		);

		// Place order via API
		$result = $this->api_client->place_order( $order_data );

		// Remove processing lock
		wc_delete_order_item_meta( $item_id, 'atd_order_lock' );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Extract and save ATD order ID
		$atd_order_id = (string) $result->Body->placeOrderResponse->order->orderNumber;
		wc_update_order_item_meta( $item_id, 'atd_order_id', $atd_order_id );

		return array(
			'atd_order_id' => $atd_order_id,
			'message'      => 'Order placed successfully with ATD',
		);
	}

	/**
	 * Update tracking information for orders
	 *
	 * @param string $confirmation_number The ATD confirmation number
	 * @param int $order_item_id       The WooCommerce order item ID
	 * @param int $order_id            The WooCommerce order ID
	 *
	 * @return array|WP_Error Success array or WP_Error on failure
	 */
	public function update_tracking_info( string $confirmation_number, int $order_item_id, int $order_id ): array|WP_Error {
		$result = $this->api_client->get_order_details( $confirmation_number );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Check if tracking info exists
		$fulfillment_path = 'Body->getOrderDetailResponse->orderDetail->orderLines->orderLine->fulfillments->fulfillment';
		$fulfillment = $this->get_nested_property( $result, $fulfillment_path );

		if ( ! $fulfillment ) {
			return new WP_Error( 'no_tracking', 'No tracking information available yet' );
		}

		$tracking_number = (string) $fulfillment->trackingNumber;
		$tracking_url = (string) $fulfillment->trackingUrl;
		$provider = (string) $fulfillment->shipMethod;

		if ( empty( $tracking_number ) ) {
			return new WP_Error( 'no_tracking_number', 'No tracking number available yet' );
		}

		// Update order item meta
		wc_update_order_item_meta( $order_item_id, 'atd_order_tracking_number', $tracking_number );

		// Check if tracking already exists in WooCommerce
		global $wpdb;
		$existing_tracking = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s AND meta_value LIKE %s",
			$order_id,
			'_wc_shipment_tracking_items',
			'%' . $wpdb->esc_like( $tracking_number ) . '%'
		) );

		if ( empty( $existing_tracking ) ) {
			// Add tracking to WooCommerce Shipment Tracking
			if ( function_exists( 'wc_st_add_tracking_number' ) ) {
				if ( false !== strpos( $provider, 'UPS' ) ) {
					wc_st_add_tracking_number( $order_id, $tracking_number, 'UPS' );
				} elseif ( false !== strpos( $provider, 'ATD' ) ) {
					wc_st_add_tracking_number( $order_id, $tracking_number, 'Flex Forward', null, $tracking_url );
				} else {
					wc_st_add_tracking_number( $order_id, $tracking_number, 'Fedex' );
				}
			}
		}

		return array(
			'tracking_number' => $tracking_number,
			'tracking_url'    => $tracking_url,
			'provider'        => $provider,
			'message'         => 'Tracking information updated',
		);
	}

	/**
	 * Helper function to get nested object properties safely
	 *
	 * @param object $object The object to traverse
	 * @param string $path   The property path (e.g., 'Body->response->data')
	 * @return mixed|null The property value or null if not found
	 */
	private function get_nested_property( $object, $path ): mixed {
		$parts = explode( '->', $path );
		$current = $object;

		foreach ( $parts as $part ) {
			if ( ! isset( $current->$part ) ) {
				return null;
			}
			$current = $current->$part;
		}

		return $current;
	}
}
