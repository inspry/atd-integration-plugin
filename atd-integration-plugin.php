<?php
/**
 * Plugin Name: ATD Integration
 * Plugin URI: https://www.inspry.com
 * Description: A WordPress plugin that allows placing orders via ATD's API.
 * Version: 2.1.1
 * Requires at least: 6.8.3
 * Requires PHP: 8.3
 * Author: Inspry
 * Author URI: https://www.inspry.com
 * Requires Plugins: woocommerce, woocommerce-shipment-tracking, advanced-custom-fields
 * Requires MU Plugin: ramtrading-core
 *
 * @package ATD_Integration
 */

// Prevent direct access
defined( 'ABSPATH' ) || exit;

// Initialize the plugin
class ATD_Integration_Plugin extends RamTrading_Abstract_Plugin {

	protected function setup_constants(): void {
		$this->plugin_file = __FILE__;
		$this->plugin_version = '2.1.1';
		$this->plugin_prefix = 'ATD_INTEGRATION';
		$this->required_plugins = ['woocommerce', 'woocommerce-shipment-tracking', 'advanced-custom-fields'];
	}

	protected function init_hooks(): void {
		$this->include_files();
		$this->init_classes();

		// WP-CLI command
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'atd_tracking_numbers', array( $this, 'cli_tracking_numbers_command' ) );
		}
	}

	protected function get_plugin_name(): string {
		return 'ATD Integration';
	}

	/**
	 * ATD Admin instance
	 *
	 * @var ATD_Admin
	 */
	private ATD_Admin $admin;

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
	 * Include required files
	 */
	private function include_files(): void {
		require_once $this->plugin_dir . 'includes/class-atd-api-client.php';
		require_once $this->plugin_dir . 'includes/class-atd-order-manager.php';
		require_once $this->plugin_dir . 'includes/class-atd-inventory-manager.php';
		require_once $this->plugin_dir . 'includes/class-atd-admin.php';
	}


	/**
	 * Initialize class instances
	 */
	private function init_classes(): void {
		// Initialize managers
		$this->order_manager = new ATD_Order_Manager();
		$this->inventory_manager = new ATD_Inventory_Manager();

		// Initialize admin interface
		if ( is_admin() ) {
			$this->admin = new ATD_Admin();
		}
	}


	/**
	 * WP-CLI command for tracking numbers
	 */
	public function cli_tracking_numbers_command(): void {
		if ( ! class_exists( 'ATD_API_Client' ) ) {
			WP_CLI::error( 'ATD API Client not available.' );
			return;
		}

		$api_client = new ATD_API_Client();

		if ( ! $api_client->is_configured() ) {
			WP_CLI::error( 'ATD API not configured. Missing: ' . implode( ', ', $api_client->get_missing_config() ) );
			return;
		}

		WP_CLI::log( 'Starting ATD tracking number updates...' );

		// Get orders needing tracking updates
		$orders_needing_tracking = $this->inventory_manager->get_orders_needing_tracking();

		if ( empty( $orders_needing_tracking ) ) {
			WP_CLI::success( 'No orders need tracking updates.' );
			return;
		}

		$updated_count = 0;
		foreach ( $orders_needing_tracking as $row ) {
			if ( empty( $row->meta_value ) ) {
				continue;
			}

			$result = $this->order_manager->update_tracking_info(
				$row->meta_value,
				$row->order_item_id,
				$row->order_id
			);

			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( sprintf(
					'Failed to update tracking for order %d: %s',
					$row->order_id,
					$result->get_error_message()
				) );
			} else {
				WP_CLI::log( sprintf( 'Updated tracking for order %d', $row->order_id ) );
				$updated_count++;
			}
		}

		// Check for orders ready for completion
		$shipped_orders = $this->inventory_manager->get_shipped_atd_orders();
		$completed_count = 0;

		foreach ( $shipped_orders as $order_id => $order_items ) {
			if ( $this->inventory_manager->should_complete_order( $order_id, $order_items ) ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order->update_status( 'completed' );
					WP_CLI::log( sprintf( 'Completed order %d', $order_id ) );
					$completed_count++;
				}
			}
		}

		WP_CLI::success( sprintf(
			'Processing complete. Updated tracking: %d orders. Completed: %d orders.',
			$updated_count,
			$completed_count
		) );
	}

	/**
	 * Get Order Manager instance
	 *
	 * @return ATD_Order_Manager
	 */
	public function get_order_manager(): ATD_Order_Manager {
		return $this->order_manager;
	}

	/**
	 * Get Inventory Manager instance
	 *
	 * @return ATD_Inventory_Manager
	 */
	public function get_inventory_manager(): ATD_Inventory_Manager {
		return $this->inventory_manager;
	}

	/**
	 * Get Admin instance
	 *
	 * @return ATD_Admin|null
	 */
	public function get_admin(): ?ATD_Admin {
		return $this->admin;
	}
}

ATD_Integration_Plugin::get_instance();
