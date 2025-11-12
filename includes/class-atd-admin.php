<?php
/**
 * ATD Admin
 *
 * Handles admin interface and AJAX handlers
 *
 * @package ATD_Integration
 * @since 2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ATD Admin Class
 *
 * Manages admin interface, AJAX handlers, and WooCommerce integration
 */
class ATD_Admin {

	/**
	 * ATD Order Manager instance
	 *
	 * @var ATD_Order_Manager
	 */
	private ATD_Order_Manager $order_manager;

	/**
	 * ATD Inventory Manager instance
	 *
	 * @var ATD_Inventory_Manager
	 */
	private ATD_Inventory_Manager $inventory_manager;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->order_manager = new ATD_Order_Manager();
		$this->inventory_manager = new ATD_Inventory_Manager();

		$this->init_hooks();
	}

	/**
	 * Initialize WordPress hooks
	 */
	private function init_hooks(): void {
		// Admin menu
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		// AJAX handlers
		add_action( 'wp_ajax_atd_inventory_scrubber', array( $this, 'handle_inventory_scrub_ajax' ) );
		add_action( 'wp_ajax_atd_order', array( $this, 'handle_order_placement_ajax' ) );

		// WooCommerce integration
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'display_atd_order_controls' ), 11, 3 );

		// Enqueue admin scripts and styles
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook_suffix The current admin page hook suffix
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		// Load on WooCommerce order edit pages OR inventory scrubber page
		$is_order_page = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true );
		$is_scrubber_page = 'tools_page_atd-inventory-update' === $hook_suffix;

		if ( ! $is_order_page && ! $is_scrubber_page ) {
			return;
		}

		// Additional check for order pages
		if ( $is_order_page ) {
			global $post;
			if ( ! $post || 'shop_order' !== $post->post_type ) {
				return;
			}
		}

		$plugin = ATD_Integration_Plugin::get_instance();
		$plugin_dir = $plugin->get_plugin_dir();
		$plugin_version = $plugin->get_plugin_version();

		// Enqueue JavaScript
		wp_enqueue_script(
			'atd-admin',
			$plugin_dir . 'assets/js/admin.js',
			array( 'jquery' ),
			$plugin_version,
			true
		);

		// Localize script for AJAX
		wp_localize_script( 'atd-admin', 'atdAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'atd_admin_nonce' ),
			'scrubberNonce' => wp_create_nonce( 'atd_inventory_scrub' ),
			'messages' => array(
				'placing' => __( 'Placing order with ATD...', 'atd-integration' ),
				'success' => __( 'Order placed successfully!', 'atd-integration' ),
				'error' => __( 'Failed to place order', 'atd-integration' ),
				'scrubberProcessing' => __( 'Processing inventory batch... This may take several minutes.', 'atd-integration' ),
				'scrubberSuccess' => __( 'Inventory scrub completed successfully!', 'atd-integration' ),
				'scrubberTimeout' => __( 'Processing timed out. Try refreshing and running smaller batches.', 'atd-integration' ),
			)
		) );

		// Enqueue CSS
		wp_enqueue_style(
			'atd-admin',
			$plugin_dir . 'assets/css/admin.css',
			array(),
			$plugin_version,
		);
	}

	/**
	 * Add admin menu page
	 */
	public function add_admin_menu(): void {
		add_management_page(
			'ATD Inventory Scrubber',
			'ATD Inventory Scrubber',
			'manage_options',
			'atd-inventory-update',
			array( $this, 'display_inventory_page' )
		);
	}

	/**
	 * Display the inventory management page
	 */
	public function display_inventory_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Insufficient permissions.' ) );
		}

		settings_errors( 'inventory_update_upload_message' );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';

		// Wheels section
		$this->display_product_batch_links( 'wheel', 'atdw', 'Wheels' );

		// Tires section
		$this->display_product_batch_links( 'tire', 'atdt', 'Tires' );

		echo '</div>';
	}

	/**
	 * Display batch processing links for a product type
	 *
	 * @param string $type     Product type ('wheel' or 'tire')
	 * @param string $tag      Product tag to filter by
	 * @param string $label    Display label for the product type
	 */
	private function display_product_batch_links( string $type, string $tag, string $label ): void {
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
			'posts_per_page' => '-1'
		) );

		$no_of_products = count( $products );

		if ( $no_of_products === 0 ) {
			echo '<div><p>No ' . esc_html( strtolower( $label ) ) . ' products found.</p></div>';
			return;
		}

		echo '<div><h2>' . esc_html( $label ) . ' (' . esc_html( $no_of_products ) . ' products)</h2>';
		echo '<ul>';

		$batch_number = 1;
		for ( $i = 1; $i <= $no_of_products; $i += 2000 ) {
			$start = $i;
			$end = min( $i + 1999, $no_of_products );

			echo '<li>';
			echo '<div class="atd-scrubber-controls" data-type="' . esc_attr( $type ) . '" data-page="' . esc_attr( $batch_number ) . '">';
			echo '<button type="button" class="button button-primary atd-scrubber-button" data-action="scrub-inventory">';
			echo 'Scrub ' . esc_html( $label ) . ' ' . esc_html( $start ) . ' to ' . esc_html( $end );
			echo '</button>';
			echo '<span class="atd-scrubber-status" style="display: none;"></span>';
			echo '</div>';
			echo '</li>';

			$batch_number++;
		}

		echo '</ul></div>';
	}

	/**
	 * Handle inventory scrub AJAX request
	 */
	public function handle_inventory_scrub_ajax(): void {
		// Check user capabilities
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Insufficient permissions.', 403 );
		}

		// Verify nonce
		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'atd_inventory_scrub' ) ) {
			wp_send_json_error( 'Invalid security token.', 403 );
		}

		// Validate and sanitize input
		$type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : 'wheel';
		$page = isset( $_GET['p'] ) ? absint( $_GET['p'] ) : 1;

		if ( ! in_array( $type, array( 'wheel', 'tire' ), true ) ) {
			wp_send_json_error( 'Invalid type parameter.', 400 );
		}

		// Increase execution time for large batches
		@ini_set( 'max_execution_time', 86400 );
		@set_time_limit( 86400 );

		// Use the Inventory Manager
		$result = $this->inventory_manager->scrub_inventory_batch( $type, $page, 2000 );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 500 );
		} else {
			wp_send_json_success( $result );
		}
	}

	/**
	 * Handle order placement AJAX request
	 */
	public function handle_order_placement_ajax(): void {
		// Check user capabilities
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( 'Insufficient permissions.', 403 );
		}

		// Verify nonce
		if ( ! wp_verify_nonce( $_GET['_ajax_nonce'] ?? '', 'atd_admin_nonce' ) ) {
			wp_send_json_error( 'Invalid security token.', 403 );
		}

		// Validate required parameters
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		$item_id = isset( $_GET['order_item_id'] ) ? absint( $_GET['order_item_id'] ) : 0;

		if ( ! $order_id || ! $product_id || ! $item_id ) {
			wp_send_json_error( 'Missing required parameters (order_id, product_id, order_item_id).', 400 );
		}

		// Use the Order Manager
		$result = $this->order_manager->place_order_item( $order_id, $product_id, $item_id );

		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();
			$status_code = 500; // Default

			// Map error codes to appropriate HTTP status codes
			switch ( $error_code ) {
				case 'invalid_order':
				case 'invalid_product':
				case 'invalid_item':
					$status_code = 400;
					break;
				case 'already_ordered':
				case 'processing_locked':
					$status_code = 409;
					break;
				case 'not_configured':
				case 'soap_fault':
				case 'curl_error':
				case 'http_error':
				case 'xml_parse_error':
				case 'invalid_response':
					$status_code = 502;
					break;
			}

			wp_send_json_error( $result->get_error_message(), $status_code );
		} else {
			$result['redirect_url'] = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
			wp_send_json_success( $result );
		}
	}

	/**
	 * Display ATD order controls in WooCommerce order item meta
	 *
	 * @param int $item_id The order item ID
	 * @param WC_Order_Item_Product $item    The order item object
	 * @param WC_Product $product The product object
	 */
	public function display_atd_order_controls( int $item_id, WC_Order_Item_Product $item, WC_Product $product ): void {
		if ( ! is_object( $product ) || ! has_term( array( 'atdt', 'atdw' ), 'product_tag', $product->get_id() ) ) {
			return;
		}

		$atd_order_id = wc_get_order_item_meta( $item_id, 'atd_order_id', true );

		if ( $atd_order_id ) {
			echo '<span class="atd-order-status atd-order-success">ATD order id: ' . esc_html( $atd_order_id ) . '</span>';
		} else {
			echo '<div class="atd-order-controls" data-order-id="' . esc_attr( $item->get_order_id() ) . '" data-product-id="' . esc_attr( $product->get_id() ) . '" data-order-item-id="' . esc_attr( $item_id ) . '">';
			echo '<button type="button" class="button atd-order-button" data-action="place-order">Order via ATD</button>';
			echo '<span class="atd-order-status" style="display: none;"></span>';
			echo '</div>';
		}
	}
}
