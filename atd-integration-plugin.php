<?php

/**
 * Plugin Name: ATD Integration
 * Plugin URI: https://www.inspry.com
 * Description:
 * Version: 1.0.0
 * Requires at least: 6.6.2
 * Requires PHP: 8.0
 * Author: Inspry
 * Author URI: https://www.inspry.com
 * Requires Plugins: woocommerce, woocommerce-shipment-tracking, advanced-custom-fields
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'atd_inventory_update', 'atd_inventory_update_callback' );
	WP_CLI::add_command( 'atd_tracking_numbers', 'atd_tracking_numbers_callback' );
}

function atd_inventory_update_callback( $args, $assoc_args ) {
	@ini_set( 'max_execution_time', 86400 );
	@set_time_limit(86400);

	$username = defined( 'ATD_USERNAME' ) ? ATD_USERNAME : null;

	if ( empty ( $username ) ) {
		echo "No ATD_USERNAME constant configured.";
		die;
	}

	$password = defined( 'ATD_PASSWORD' ) ? ATD_PASSWORD : null;

	if ( empty ( $password ) ) {
		echo "No ATD_PASSWORD constant configured.";
		die;
	}

	$product = 'wheel';
	$tag = 'atdw';
	$batch = 1;
	if ( isset( $assoc_args['product'] ) && in_array( $assoc_args['product'], array( 'wheel', 'tire' ) ) ) {
		$product = $assoc_args['product'];
		if ( 'tire' == $assoc_args['product'] ) {
			$tag = 'atdt';
		} else {
			$tag = 'atdw';
		}
	} elseif ( isset( $assoc_args['product'] ) ) {
		$tag = $assoc_args['product'];
	}
	if ( isset( $assoc_args['batch'] ) && is_numeric( $assoc_args['batch'] ) ) {
		$batch = $assoc_args['batch'];
	}

	$GLOBALS['wp_object_cache']->delete( '_atd_function_' . $product, 'options' );
	$atd_option = get_option( '_atd_function_' . $product );
	if ( isset( $atd_option['running'] ) && 'yes' == $atd_option['running'] && in_array( $tag, array( 'atdw', 'atdt' ) ) ) {
		if ( 86400 < ( time() - $atd_option['end_time'] ) && 86400 < ( time() - $atd_option['start_time'] ) ) {
			delete_option( '_atd_function_' . $product );
		} else {
			die( "Already running" );
		}
	}

	$atd_option['running'] = 'yes';
	$atd_option['start_time'] = time();
	$atd_option['current_action'] = 'inventory';
	update_option( '_atd_function_' . $product, $atd_option, false );

	$atd_tires = get_posts( array( 'post_type' => 'product', 'tax_query' => array( array( 'taxonomy' => 'product_tag', 'field' => 'slug', 'terms' => $tag ) ), 'fields' => 'ids', 'posts_per_page' => '-1' ) );
	$posts_per_page = ceil( count( $atd_tires ) / 50 );
	$atd_tires = get_posts( array( 'post_type' => 'product', 'tax_query' => array( array( 'taxonomy' => 'product_tag', 'field' => 'slug', 'terms' => $tag ) ), 'fields' => 'ids', 'posts_per_page' => $posts_per_page, 'paged' => $batch ) );
	$ch = curl_init();
	foreach ( $atd_tires as $tire ) {
		$map = 0;
		$matched_rebate = '';
		$brand = wp_get_object_terms( $tire, 'pwb-brand', array( 'fields' => 'ids' ) );
		$sku = get_post_meta( $tire, '_sku', true );
		$xml_request = <<<XML
		<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:prod="http://sth.atdconnect.com/atd/1_1/productShipToHome" xmlns:com="http://sth.atdconnect.com/atd/1_1/commonShipToHome" xmlns:prod1="http://sth.atdconnect.com/atd/1_1/productShipToHomeCommon">
		      <soapenv:Header>
		      <wsse:Security soapenv:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
		         <wsse:UsernameToken atd:clientId="IAPDI_ASAP" xmlns:atd="http://api.atdconnect.com/atd">
		            <wsse:Username>{$username}</wsse:Username>
		            <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">${password}</wsse:Password>
		         </wsse:UsernameToken>
		      </wsse:Security>
		   </soapenv:Header>
		   <soapenv:Body>
		      <prod:getProductByCriteriaRequest>
		         <prod:locationNumber>1320769</prod:locationNumber>
		         <prod:criteria>
		            <com:entry>
		               <com:key>atdproductnumber</com:key>
			       <com:value>{$sku}</com:value>
		            </com:entry>
		         </prod:criteria>
		         <prod:options>
		            <prod1:price>
		               <com:cost>1</com:cost>
		               <com:retail>1</com:retail>
		               <com:specialDiscount>1</com:specialDiscount>
		               <com:fet>1</com:fet>
		            </prod1:price>
		            <prod1:images>
		               <prod1:thumbnail>1</prod1:thumbnail>
		               <prod1:small>1</prod1:small>
		               <prod1:large>1</prod1:large>
		            </prod1:images>
		            <prod1:productSpec>
		            </prod1:productSpec>
		            <prod1:includeAvailability>1</prod1:includeAvailability>
		            <prod1:includeRebates>1</prod1:includeRebates>
		            <prod1:includeMarketingPrograms>0</prod1:includeMarketingPrograms>
		         </prod:options>
		      </prod:getProductByCriteriaRequest>
		   </soapenv:Body>
		</soapenv:Envelope>
XML;

		curl_setopt($ch, CURLOPT_URL, 'https://sth.atdconnect.com/ws/1_1/products.wsdl');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

		$headers = array();
		array_push($headers, "Content-Type: text/xml; charset=utf-8");

		curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_request);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		$response = curl_exec($ch);

		$clean_xml = str_ireplace(array('SOAP-ENV:', 'SOAP:', 'ns4:', 'pc:', 'c:'), '', $response);
		$response = simplexml_load_string($clean_xml);
		if ( ! isset( $response->Body->getProductByCriteriaResponse->products->product ) ) {
			update_post_meta( $tire, '_stock', 0 );
			update_post_meta( $tire, '_stock_status', wc_clean( 'outofstock' ) );
			update_post_meta( $tire, '_atd_not_present', 1 );
			wp_set_post_terms( $tire, 'outofstock', 'product_visibility', true );
			continue;
		} else {
			delete_post_meta( $tire, '_atd_not_present' );
		}
		$xml_products = $response->Body->getProductByCriteriaResponse->products->product;
		foreach ( $xml_products as $xml_product ) {
			if ( $sku == $xml_product->ATDProductNumber ) {
				$matched_product = $xml_product;
				break;
			}
		}
		if ( isset( $matched_product->productSpec ) ) {
			$specs = $matched_product->productSpec;
			foreach ( $specs->entry as $spec ) {
				if ( 0 === stripos( $spec->key, 'MAP' ) ) {
					$map = (float) $spec->value;
				}
				if ( 0 === stripos( $spec->key, 'MileageWarranty' ) ) {
					$mile_warranty = (string) $spec->value;
				}
				if ( 0 === stripos( $spec->key, 'RunFlat' ) ) {
					$runflat = (string) $spec->value;
				}
				if ( 0 === stripos( $spec->key, 'Sidewall' ) ) {
					$sidewall = (string) $spec->value;
				}
			}
			if ( ! isset( $mile_warranty ) || empty( $mile_warranty ) ) {
				$attributes = get_post_meta( $tire, '_product_attributes', true );
				if ( isset( $attributes['pa_mileage-warranty'] ) ) {
					wp_remove_object_terms( $tire, $attributes['pa_mileage-warranty']['value'], 'pa_mileage-warranty' );
					unset( $attributes['pa_mileage-warranty'] );
					update_post_meta( $tire, '_product_attributes', $attributes );
				}
			} else {
				wp_set_object_terms( $tire, $mile_warranty, 'pa_mileage-warranty' );
				$attr_meta = get_post_meta( $tire, '_product_attributes', true );
				$attr_meta['pa_mileage-warranty'] = array( 'name' => 'pa_mileage-warranty', 'value' => $mile_warranty, 'position' => count( $attr_meta ), 'is_visible' => 0, 'is_variation' => 0, 'is_taxonomy' => 1 );
				update_post_meta( $tire, '_product_attributes', $attr_meta );
			}
			if ( ! isset( $sidewall ) || empty( $sidewall ) ) {
				$attributes = get_post_meta( $tire, '_product_attributes', true );
				if ( isset( $attributes['pa_sidewall-type'] ) ) {
					wp_remove_object_terms( $tire, $attributes['pa_sidewall-type']['value'], 'pa_sidewall-type' );
					unset( $attributes['pa_sidewall-type'] );
					update_post_meta( $tire, '_product_attributes', $attributes );
				}
			} else {
				wp_set_object_terms( $tire, $sidewall, 'pa_sidewall-type' );
				$attr_meta = get_post_meta( $tire, '_product_attributes', true );
				$attr_meta['pa_sidewall-type'] = array( 'name' => 'pa_sidewall-type', 'value' => $sidewall, 'position' => count( $attr_meta ), 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 1 );
				update_post_meta( $tire, '_product_attributes', $attr_meta );
			}
			if ( ! isset( $runflat ) || empty( $runflat ) ) {
				$attributes = get_post_meta( $tire, '_product_attributes', true );
				if ( isset( $attributes['pa_runflat'] ) ) {
					wp_remove_object_terms( $tire, $attributes['pa_runflat']['value'], 'pa_runflat' );
					unset( $attributes['pa_runflat'] );
					update_post_meta( $tire, '_product_attributes', $attributes );
				}
			} else {
				wp_set_object_terms( $tire, $runflat, 'pa_runflat' );
				$attr_meta = get_post_meta( $tire, '_product_attributes', true );
				$attr_meta['pa_runflat'] = array( 'name' => 'pa_runflat', 'value' => $runflat, 'position' => count( $attr_meta ), 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 1 );
				update_post_meta( $tire, '_product_attributes', $attr_meta );
			}
		}
		if ( isset( $matched_product->availableQty ) ) {
			$qty = (int) $matched_product->availableQty;
			var_dump( $tire );
			var_dump( update_post_meta( $tire, '_stock', $qty ) );
			if ( 0 === $qty ) {
				update_post_meta( $tire, '_stock_status', wc_clean( 'outofstock' ) );
				wp_set_post_terms( $tire, 'outofstock', 'product_visibility', true );
			} else {
				update_post_meta( $tire, '_stock_status', wc_clean( 'instock' ) );
				wp_remove_object_terms( $tire, 'outofstock', 'product_visibility' );
			}
		}
		if ( isset( $matched_product->rebates->rebate ) ) {
			foreach ( $matched_product->rebates->rebate as $rebate ) {
				if ( 'total access' != strtolower( $rebate->code ) ) {
					$matched_rebate = $rebate;
					break;
				}
			}
		}
		if ( isset( $matched_rebate->description ) ) {
			$rebate_description = (string) $matched_rebate->description;
			update_post_meta( $tire, '_atd_rebate_description', $rebate_description );
		} else {
			delete_post_meta( $tire, '_atd_rebate_description' );
		}
		if ( isset( $matched_rebate->url ) ) {
			$rebate_url = (string) $matched_rebate->url;
			update_post_meta( $tire, '_atd_rebate_url', $rebate_url );
		} else {
			delete_post_meta( $tire, '_atd_rebate_url' );
		}
		if ( isset( $matched_product->price->cost ) ) {
			$cost = (float) $matched_product->price->cost;
			$fet = (float) $matched_product->price->fet;
			$weight = get_post_meta( $tire, '_weight', true );
			if ( 0 < $cost ) {
				$freight = get_option( 'markup_freight' );
				if ( 0 < $fet ) {
					$cost += $fet;
				}
				if ( isset( $map ) && 0 < $map ) {
					if ( $weight <= $freight['tier_1_freight']['end_weight'] ) {
						$freight = $freight['tier_1_freight']['freight_fee'];
						$markup = get_field( 'map-tier-1', 'pwb-brand_' . $brand[0] );
					} elseif ( $weight <= $freight['tier_2_freight']['end_weight'] ) {
						$freight = $freight['tier_2_freight']['freight_fee'];
						$markup = get_field( 'map-tier-2', 'pwb-brand_' . $brand[0] );
					} elseif ( $weight <= $freight['tier_3_freight']['end_weight'] ) {
						$freight = $freight['tier_3_freight']['freight_fee'];
						$markup = get_field( 'map-tier-3', 'pwb-brand_' . $brand[0] );
					} else {
						$freight = $freight['tier_4_freight']['freight_fee'];
						$markup = get_field( 'map-tier-4', 'pwb-brand_' . $brand[0] );
					}
					$price = round( ( ( $cost / ( 1 - ( $markup / 100 ) ) ) + $freight ), 2 );
					if ( $map > $price ) {
						$price = $map;
					}
					$map_attr = wp_get_object_terms( $tire, 'pa_map' );
					if ( ! isset( $map_attr[0]->slug ) || 'yes' != $map_attr[0]->slug ) {
						wp_set_object_terms( $tire, 'yes', 'pa_map' );
					}
					$attr_meta = get_post_meta( $tire, '_product_attributes', true );
					$attr_meta['pa_map'] = array( 'name' => 'pa_map', 'value' => '', 'position' => count( $attr_meta ), 'is_visible' => 0, 'is_variation' => 0, 'is_taxonomy' => 1 );
					update_post_meta( $tire, '_product_attributes', $attr_meta );
				} else {
					if ( $weight <= $freight['tier_1_freight']['end_weight'] ) {
						$freight = $freight['tier_1_freight']['freight_fee'];
						$markup = get_field( 'markup-tier-1', 'pwb-brand_' . $brand[0] );
					} elseif ( $weight <= $freight['tier_2_freight']['end_weight'] ) {
						$freight = $freight['tier_2_freight']['freight_fee'];
						$markup = get_field( 'markup-tier-2', 'pwb-brand_' . $brand[0] );
					} elseif ( $weight <= $freight['tier_3_freight']['end_weight'] ) {
						$freight = $freight['tier_3_freight']['freight_fee'];
						$markup = get_field( 'markup-tier-3', 'pwb-brand_' . $brand[0] );
					} else {
						$freight = $freight['tier_4_freight']['freight_fee'];
						$markup = get_field( 'markup-tier-4', 'pwb-brand_' . $brand[0] );
					}
					$price = round( ( ( $cost / ( 1 - ( $markup / 100 ) ) ) + $freight ), 2 );
					$attributes = get_post_meta( $tire, '_product_attributes', true );
					if ( isset( $attributes['pa_map'] ) ) {
						unset( $attributes['pa_map'] );
						update_post_meta( $tire, '_product_attributes', $attributes );
						wp_remove_object_terms( $tire, 'yes', 'pa_map' );
					}
				}
				update_post_meta( $tire, '_regular_price', $price );
				update_post_meta( $tire, '_price', $price );
				update_post_meta( $tire, '_wc_cog_cost', $cost );
				echo "SKU: $sku. Price updated to $price\r\n";
			} else {
				echo "SKU: $sku. Cost is not above 0\r\n";
			}
		} else {
			echo "SKU: $sku. Cost attribute not found\r\n";
		}
		if ( 'atdw' == $tag ) :
			$local_stock = 0;
			$local_stock += atd_get_inventory_by_location( $sku, '34550', '18610' );
			$local_stock += atd_get_inventory_by_location( $sku, '34550', '76262' );
			$local_stock += atd_get_inventory_by_location( $sku, '34550', '30084' );
			$local_stock += atd_get_inventory_by_location( $sku, '34550', '33025' );
			$local_stock += atd_get_inventory_by_location( $sku, '34550', '93263' );
			$local_stock += atd_get_inventory_by_location( $sku, '34550', '28092' );
			update_post_meta( $tire, '_wheelpros_1018_stock', $local_stock );
		endif;
		if ( 'atdt' == $tag ) :
			$local_stock = 0;
			$local_stock += atd_get_inventory_by_location( $sku, '1320769', '33025' );
			$local_stock += atd_get_inventory_by_location( $sku, '1320769', '32503' );
			$local_stock += atd_get_inventory_by_location( $sku, '1320769', '33916' );
			$local_stock += atd_get_inventory_by_location( $sku, '1320769', '33619' );
			$local_stock += atd_get_inventory_by_location( $sku, '1320769', '32303' );
			$local_stock += atd_get_inventory_by_location( $sku, '1320769', '33411' );
			$local_stock += atd_get_inventory_by_location( $sku, '1320769', '32824' );
			update_post_meta( $tire, '_wheelpros_1018_stock', $local_stock );
		endif;
	}
	curl_close( $ch );
	$atd_option['end_time'] = time();
	$atd_option['running'] = 'no';
	update_option( '_atd_function_' . $product, $atd_option, false );
	die;
}

function atd_tracking_numbers_callback() {
	global $wpdb;

	$username = defined( 'ATD_USERNAME' ) ? ATD_USERNAME : null;

	if ( empty ( $username ) ) {
		echo "No ATD_USERNAME constant configured.";
		die;
	}

	$password = defined( 'ATD_PASSWORD' ) ? ATD_PASSWORD : null;

	if ( empty ( $password ) ) {
		echo "No ATD_PASSWORD constant configured.";
		die;
	}

	$all_atd_items = $wpdb->get_results( "SELECT
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
    meta_key = 'atd_order_id' AND {$wpdb->posts}.post_date BETWEEN CURDATE() - INTERVAL 1 MONTH AND CURDATE() + INTERVAL 1 DAY AND {$wpdb->posts}.post_status NOT IN(
        'wc-refunded',
        'wc-failed',
        'wc-cancelled',
        'wc-completed',
        'wc-completed-package'
    ) AND order_itemmeta.order_item_id NOT IN(
    SELECT
        order_item_id
    FROM
        {$wpdb->prefix}woocommerce_order_itemmeta
    WHERE
        meta_key = 'atd_order_tracking_number'
)
ORDER BY
    order_id
ASC" );
	foreach ( $all_atd_items as $row ) {
		if ( ! isset( $row->meta_value ) || empty( $row->meta_value ) ) {
			continue;
		}
		$xml_request = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ord="http://api.atdconnect.com/atd/3_4/orderStatus">
      <soapenv:Header>
      <wsse:Security soapenv:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
         <wsse:UsernameToken atd:clientId="IAPDI_ASAP" xmlns:atd="http://api.atdconnect.com/atd">
            <wsse:Username>{$username}</wsse:Username>
            <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">${password}</wsse:Password>
         </wsse:UsernameToken>
      </wsse:Security>
   </soapenv:Header>
   <soapenv:Body>
      <ord:getOrderDetailRequest>
	<ord:locationNumber>1320769</ord:locationNumber>
	<ord:confirmationNumber>{$row->meta_value}</ord:confirmationNumber>
      </ord:getOrderDetailRequest>
   </soapenv:Body>
</soapenv:Envelope>
XML;
		$ch          = curl_init();
		curl_setopt( $ch, CURLOPT_URL, 'https://ws.atdconnect.com/ws/3_4/orderStatus.wsdl' );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 0 );

		$headers = array();
		array_push( $headers, "Content-Type: text/xml; charset=utf-8" );

		curl_setopt( $ch, CURLOPT_POSTFIELDS, $xml_request );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		$response = curl_exec( $ch );

		$clean_xml = str_ireplace( array( 'SOAP-ENV:', 'SOAP:', 'ns2:', 'ns4:', 'pc:', 'c:' ), '', $response );
		$response  = simplexml_load_string( $clean_xml );
		if ( isset( $response->Body->getOrderDetailResponse->orderDetail->orderLines->orderLine->fulfillments->fulfillment->trackingNumber ) && ! empty( $response->Body->getOrderDetailResponse->orderDetail->orderLines->orderLine->fulfillments->fulfillment->trackingNumber ) ) {
			$trackingNumber = (string) $response->Body->getOrderDetailResponse->orderDetail->orderLines->orderLine->fulfillments->fulfillment->trackingNumber;
			$trackingUrl    = (string) $response->Body->getOrderDetailResponse->orderDetail->orderLines->orderLine->fulfillments->fulfillment->trackingUrl;
			$provider       = (string) $response->Body->getOrderDetailResponse->orderDetail->orderLines->orderLine->fulfillments->fulfillment->shipMethod;
			wc_update_order_item_meta( $row->order_item_id, 'atd_order_tracking_number', $trackingNumber );
			$tracking = $wpdb->get_var( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = '{$row->order_id}' AND meta_key = '_wc_shipment_tracking_items' AND meta_value LIKE '%{$trackingNumber}%'" );
			if ( empty( $tracking ) ) {
				if ( false !== strpos( $provider, 'UPS' ) ) {
					wc_st_add_tracking_number( $row->order_id, $trackingNumber, 'UPS' );
				} elseif ( false !== strpos( $provider, 'ATD' ) ) {
					wc_st_add_tracking_number( $row->order_id, $trackingNumber, 'Flex Forward', null, $trackingUrl );
				} else {
					wc_st_add_tracking_number( $row->order_id, $trackingNumber, 'Fedex' );
				}
				var_dump( "$row->order_id Tracking added" );
			}
		}
	}
	$shipped_atd             = array();
	$non_atd_wheel_tire      = false;
	$shipped_atd_order_items = $wpdb->get_results( "SELECT
    order_id,
    meta_value AS product_id,
    {$wpdb->prefix}woocommerce_order_itemmeta.order_item_id
FROM
    {$wpdb->prefix}woocommerce_order_itemmeta
LEFT JOIN {$wpdb->prefix}woocommerce_order_items ON {$wpdb->prefix}woocommerce_order_itemmeta.order_item_id = {$wpdb->prefix}woocommerce_order_items.order_item_id
WHERE
    {$wpdb->prefix}woocommerce_order_itemmeta.order_item_id IN(
    SELECT
        {$wpdb->prefix}woocommerce_order_items.order_item_id AS order_item_id
    FROM
        {$wpdb->prefix}woocommerce_order_itemmeta
    LEFT JOIN {$wpdb->prefix}woocommerce_order_items ON {$wpdb->prefix}woocommerce_order_itemmeta.order_item_id = {$wpdb->prefix}woocommerce_order_items.order_item_id
    LEFT JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->prefix}woocommerce_order_items.order_id
    WHERE
	{$wpdb->posts}.post_date BETWEEN CURDATE() - INTERVAL 1 MONTH AND CURDATE() + INTERVAL 1 DAY AND meta_key = 'atd_order_tracking_number' AND post_status NOT IN(
            'wc-refunded',
            'wc-failed',
            'wc-cancelled',
            'wc-completed',
            'wc-completed-package'
        )) AND meta_key = '_product_id'
    ORDER BY
        order_id
    ASC" );
	foreach ( $shipped_atd_order_items as $item ) {
		$shipped_atd[ $item->order_id ][] = $item->order_item_id;
	}
	foreach ( $shipped_atd as $order_id => $order_items ) {
		$non_atd_items = $wpdb->get_results( "SELECT
    {$wpdb->prefix}woocommerce_order_itemmeta.meta_value AS product_id
FROM
    `{$wpdb->prefix}woocommerce_order_items`
LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta ON {$wpdb->prefix}woocommerce_order_itemmeta.order_item_id = {$wpdb->prefix}woocommerce_order_items.order_item_id
WHERE
{$wpdb->prefix}woocommerce_order_items.order_item_id NOT IN (" . implode( ",", $order_items ) . ") AND `order_item_type` = 'line_item' AND `order_id` = '{$order_id}' AND {$wpdb->prefix}woocommerce_order_itemmeta.meta_key = '_product_id'" );
		if ( empty( $non_atd_items ) ) {
			$order_obj = wc_get_order( $order_id );
			$order_obj->update_status( 'completed' );
			var_dump( "$order_id completed" );
		} else {
			foreach ( $non_atd_items as $item ) {
				if ( has_term( array( 'custom-wheels', 'tires', 'atdt', 'atdw' ), 'product_tag', $item ) ) {
					$non_atd_wheel_tire = true;
					break;
				}
			}
			if ( ! $non_atd_wheel_tire ) {
				$order_obj = wc_get_order( $order_id );
				$order_obj->update_status( 'completed' );
				var_dump( "$order_id completed" );
			}
		}
	}
}

function atd_get_inventory_by_location( $sku, $location, $zip ) {
	$username = defined( 'ATD_USERNAME' ) ? ATD_USERNAME : null;

	if ( empty ( $username ) ) {
		echo "No ATD_USERNAME constant configured.";
		die;
	}

	$password = defined( 'ATD_PASSWORD' ) ? ATD_PASSWORD : null;

	if ( empty ( $password ) ) {
		echo "No ATD_PASSWORD constant configured.";
		die;
	}

	$xml_request2 = <<<XML
		<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:inv="http://sth.atdconnect.com/atd/1_1/inventoryShipToHome" xmlns:com="http://sth.atdconnect.com/atd/1_1/commonShipToHome">
		      <soapenv:Header>
		      <wsse:Security soapenv:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
		         <wsse:UsernameToken atd:clientId="IAPDI_ASAP" xmlns:atd="http://api.atdconnect.com/atd">
		            <wsse:Username>{$username}</wsse:Username>
		            <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">${password}</wsse:Password>
		         </wsse:UsernameToken>
		      </wsse:Security>
		   </soapenv:Header>
		   <soapenv:Body>
			   <inv:getInventoryByLocationRequest>
				   <inv:locationNumber>{$location}</inv:locationNumber>
				   <inv:postalCode>{$zip}</inv:postalCode>
				   <inv:criteria>
					   <com:entry>
						   <com:key>ATDProductNumber</com:key>
						   <com:value>{$sku}</com:value>
					   </com:entry>
				   </inv:criteria>
			</inv:getInventoryByLocationRequest>
		</soapenv:Body>
	</soapenv:Envelope>
XML;
	$ch2 = curl_init();
	curl_setopt($ch2, CURLOPT_URL, 'https://sth.atdconnect.com/ws/1_1/products.wsdl');
	curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 0);

	$headers2 = array();
	array_push( $headers2, "Content-Type: text/xml; charset=utf-8");

	curl_setopt($ch2, CURLOPT_POSTFIELDS, $xml_request2);
	curl_setopt($ch2, CURLOPT_POST, true);
	curl_setopt($ch2, CURLOPT_HTTPHEADER, $headers2);
	$response2 = curl_exec($ch2);

	$clean_xml2 = str_ireplace(array('SOAP-ENV:', 'SOAP:', 'ns2:', 'ns4:', 'pc:', 'c:'), '', $response2);
	$response2 = simplexml_load_string($clean_xml2);
	if ( isset( $response2->Body->getInventoryByLocationResponse->products->inventory ) ) {
		foreach ( $response2->Body->getInventoryByLocationResponse->products->inventory as $product2 ) {
			if ( $sku == $product2->ATDProductNumber && isset( $product2->local ) && 0 < $product2->local ) {
				return (int) $product2->local;
			}
		}
	}
}

add_action( 'admin_menu', function() {
	add_management_page( 'ATD Inventory Scrubber', 'ATD Inventory Scrubber', 'manage_options', 'atd-inventory-update', 'atd_wheel_inventory_page' );
});

function atd_wheel_inventory_page() {
	if ( ! current_user_can( 'manage_options' ) )  {
		wp_die( __( 'Get LOST' ) );
	}
	settings_errors( 'inventory_update_upload_message' );
	echo '<div class="wrap">';
	echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';
	$products = get_posts( array( 'post_type' => 'product', 'tax_query' => array( array( 'taxonomy' => 'product_tag', 'field' => 'slug', 'terms' => 'atdw' ) ), 'fields' => 'ids', 'posts_per_page' => '-1' ) );
	$no_of_products = count( $products );
	echo '<ul>';
	$j = 1;
	for ( $i = 1; $i <= $no_of_products; $i += 2000 ) {
		echo '<li><a style="font-size:20px" href="admin-ajax.php?action=atd_inventory_scrubber&type=wheel&p=' . $j . '" target="_blank">Scrub Wheels ' . $i . ' to ' . ( ( ( $i + 1999 ) <= $no_of_products ) ? ( $i + 1999 ) : $no_of_products ) . '</a></li>';
		$j++;
	}
	echo '</ul></div>';
	$products = get_posts( array( 'post_type' => 'product', 'tax_query' => array( array( 'taxonomy' => 'product_tag', 'field' => 'slug', 'terms' => 'atdt' ) ), 'fields' => 'ids', 'posts_per_page' => '-1' ) );
	$no_of_products = count( $products );
	echo '<div><ul>';
	$j = 1;
	for ( $i = 1; $i <= $no_of_products; $i += 2000 ) {
		echo '<li><a style="font-size:20px" href="admin-ajax.php?action=atd_inventory_scrubber&type=tire&p=' . $j . '" target="_blank">Scrub Tires ' . $i . ' to ' . ( ( ( $i + 1999 ) <= $no_of_products ) ? ( $i + 1999 ) : $no_of_products ) . '</a></li>';
		$j++;
	}
	echo '</ul></div>';
}

add_action( 'wp_ajax_atd_inventory_scrubber', function() {
	@ini_set( 'max_execution_time', 86400 );
	@set_time_limit(86400);
	$tag = 'atdw';
	if ( isset( $_GET['type'] ) && 'tire' == $_GET['type'] ) {
		$tag = 'atdt';
	}

	$username = defined( 'ATD_USERNAME' ) ? ATD_USERNAME : null;

	if ( empty ( $username ) ) {
		echo "No ATD_USERNAME constant configured.";
		die;
	}

	$password = defined( 'ATD_PASSWORD' ) ? ATD_PASSWORD : null;

	if ( empty ( $password ) ) {
		echo "No ATD_PASSWORD constant configured.";
		die;
	}

	$atd_tires = get_posts( array( 'post_type' => 'product', 'tax_query' => array( array( 'taxonomy' => 'product_tag', 'field' => 'slug', 'terms' => $tag ) ), 'fields' => 'ids', 'posts_per_page' => '2000', 'paged' => ( ( isset( $_GET['p'] ) && is_numeric( $_GET['p'] ) ) ? $_GET['p'] : 1 ) ) );
	$skus = array();
	foreach ( $atd_tires as $tire ) {
		$sku = get_post_meta( $tire, '_sku', true );
		$xml_request = <<<XML
		<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:prod="http://sth.atdconnect.com/atd/1_1/productShipToHome" xmlns:com="http://sth.atdconnect.com/atd/1_1/commonShipToHome" xmlns:prod1="http://sth.atdconnect.com/atd/1_1/productShipToHomeCommon">
		      <soapenv:Header>
		      <wsse:Security soapenv:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
		         <wsse:UsernameToken atd:clientId="IAPDI_ASAP" xmlns:atd="http://api.atdconnect.com/atd">
		            <wsse:Username>{$username}</wsse:Username>
		            <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">{$password}</wsse:Password>
		         </wsse:UsernameToken>
		      </wsse:Security>
		   </soapenv:Header>
		   <soapenv:Body>
		      <prod:getProductByCriteriaRequest>
		         <prod:locationNumber>1320769</prod:locationNumber>
		         <prod:criteria>
		            <com:entry>
		               <com:key>atdproductnumber</com:key>
			       <com:value>{$sku}</com:value>
		            </com:entry>
		         </prod:criteria>
		         <prod:options>
		            <prod1:price>
		               <com:cost>1</com:cost>
		               <com:retail>1</com:retail>
		               <com:specialDiscount>1</com:specialDiscount>
		               <com:fet>1</com:fet>
		            </prod1:price>
		            <prod1:images>
		               <prod1:thumbnail>1</prod1:thumbnail>
		               <prod1:small>1</prod1:small>
		               <prod1:large>1</prod1:large>
		            </prod1:images>
		            <prod1:productSpec>
		            </prod1:productSpec>
		            <prod1:includeAvailability>1</prod1:includeAvailability>
		            <prod1:includeRebates>1</prod1:includeRebates>
		            <prod1:includeMarketingPrograms>0</prod1:includeMarketingPrograms>
		         </prod:options>
		      </prod:getProductByCriteriaRequest>
		   </soapenv:Body>
		</soapenv:Envelope>
XML;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://sth.atdconnect.com/ws/1_1/products.wsdl');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

		$headers = array();
		array_push($headers, "Content-Type: text/xml; charset=utf-8");

		curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_request);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		$response = curl_exec($ch);

		$clean_xml = str_ireplace(array('SOAP-ENV:', 'SOAP:', 'ns4:', 'pc:', 'c:'), '', $response);
		$response = simplexml_load_string($clean_xml);
		if ( ! isset( $response->Body->getProductByCriteriaResponse->products->product ) ) {
			update_post_meta( $tire, '_stock', 0 );
			update_post_meta( $tire, '_stock_status', wc_clean( 'outofstock' ) );
			wp_set_post_terms( $tire, 'outofstock', 'product_visibility', true );
			$skus[] = $sku;
			echo $sku . "<br>";
		}
	}
	if ( empty( $skus ) ) echo 'All products in this batch exist in the ATD database';
	die;
});

add_action( 'woocommerce_after_order_itemmeta', function( $item_id, $item, $product ) {
	if ( is_object( $product ) && has_term( array( 'atdt', 'atdw' ), 'product_tag', $product->get_id() ) ) {
		$atd_order_id = wc_get_order_item_meta( $item_id, 'atd_order_id', true );
		if ( $atd_order_id ) {
			echo '<span>ATD order id: ' . $atd_order_id . '</span>';
		} else {
			echo '<a href="' . admin_url( 'admin-ajax.php?action=atd_order&iid=' . $item_id . '&oid=' . $item->get_order_id() . '&pid=' . $product->get_id() ) . '" style="color: #f00;">Order via ATD</a>';
		}
	}
}, 11, 3 );

add_action( 'wp_ajax_atd_order', function() {
	if ( ! isset( $_GET['oid'] ) || ! isset( $_GET['pid'] ) || ! isset( $_GET['iid'] ) ) {
		echo 'Invalid Request';
		die;
	}

	$atd_order_id = wc_get_order_item_meta( $_GET['iid'], 'atd_order_id', true );
	if ( ! empty( $atd_order_id ) ) {
		echo 'This order was already placed - ' . $atd_order_id;
		die;
	}
	$atd_order_lock = wc_get_order_item_meta( $_GET['iid'], 'atd_order_lock', true );
	if ( 1 == $atd_order_lock ) {
		echo 'This action is processing.';
		die;
	} else {
		wc_update_order_item_meta( $_GET['iid'], 'atd_order_lock', 1 );
	}

	$shipping = Array();
	$order = new WC_Order( $_GET['oid'] );
	$sku = get_post_meta( $_GET['pid'], '_sku', true );
	$item = new WC_Order_Item_Product( $_GET['iid'] );
	$qty = $item->get_quantity();
	$order_number = $order->get_order_number();
	$shipping['first_name'] = $order->get_shipping_first_name();
	$shipping['last_name'] = $order->get_shipping_last_name();
	$shipping['address_1'] = $order->get_shipping_address_1();
	$shipping['address_2'] = $order->get_shipping_address_2();
	$shipping['state'] = $order->get_shipping_state();
	$shipping['city'] = $order->get_shipping_city();
	$shipping['postcode'] = $order->get_shipping_postcode();
	$shipping['phone'] = preg_replace( '/\D/', '', $order->get_billing_phone() );
	$shipping['email'] = $order->get_billing_email();

	$key = defined( 'ATD_API_KEY' ) ? ATD_API_KEY : null;

	if ( empty ( $key ) ) {
		echo "No ATD_API_KEY constant configured.";
		die;
	}

	$username = defined( 'ATD_USERNAME' ) ? ATD_USERNAME : null;

	if ( empty ( $username ) ) {
		echo "No ATD_USERNAME constant configured.";
		die;
	}

	$password = defined( 'ATD_PASSWORD' ) ? ATD_PASSWORD : null;

	if ( empty ( $password ) ) {
		echo "No ATD_PASSWORD constant configured.";
		die;
	}

	$header = [
		'alg' => 'HS256',
		'typ' => 'JWT'
	];
	$header = json_encode( $header );
	$header = str_replace( ['+', '/', '='], ['-', '_', ''], base64_encode( $header ) );
	$payload = [
		"location" => "1320769",
		"name" => trim($shipping['first_name'] . ' ' . $shipping['last_name']),
		"address1" => trim($shipping['address_1'] . ' ' . $shipping['address_2']),
		"city" => trim($shipping['city']),
		"state" => trim($shipping['state']),
		"postalCode" => trim($shipping['postcode']),
		"country" => "US"
	];
	$payload = json_encode( $payload );
	$payload = str_replace( ['+', '/', '='], ['-', '_', ''], base64_encode( $payload ) );
	$signature = hash_hmac( 'sha256', $header . "." . $payload, $key, true );
	$signature = str_replace( ['+', '/', '='], ['-', '_', ''], base64_encode( $signature ) );
	$auth_str = "$header.$payload.$signature";
	$xml_request = <<<XML
	<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ord="http://sth.atdconnect.com/atd/1_1/orderShipToHome" xmlns:com="http://sth.atdconnect.com/atd/1_1/commonShipToHome">
	      <soapenv:Header>
	      <wsse:Security soapenv:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
	         <wsse:UsernameToken atd:clientId="IAPDI_ASAP" atd:authToken="Bearer {$auth_str}" xmlns:atd="http://api.atdconnect.com/atd">
	            <wsse:Username>{$username}</wsse:Username>
	            <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">{$password}</wsse:Password>
	         </wsse:UsernameToken>
	      </wsse:Security>
	   </soapenv:Header>
	   <soapenv:Body>
		<ord:placeOrderRequest>
			<ord:locationNumber>1320769</ord:locationNumber>
			<ord:order>
				<ord:customerPONumber>{$order_number}</ord:customerPONumber>
				<ord:consumerData>
					<ord:name>{$shipping['first_name']} {$shipping['last_name']}</ord:name>
					<ord:phoneNumber>{$shipping['phone']}</ord:phoneNumber>
					<ord:emailAddress>{$shipping['email']}</ord:emailAddress>
					<ord:address>
						<com:address1>{$shipping['address_1']} {$shipping['address_2']}</com:address1>
						<com:city>{$shipping['city']}</com:city>
						<com:state>{$shipping['state']}</com:state>
						<com:postalCode>{$shipping['postcode']}</com:postalCode>
						<com:country>US</com:country>
					</ord:address>
					<ord:deliveryInstructions>**MUST SHIP SIGNATURE REQUIRED**</ord:deliveryInstructions>
				</ord:consumerData>
				<ord:shippingMethod>CHEAPEST_FREIGHT</ord:shippingMethod>
				<ord:signatureRequired>DirectSignature</ord:signatureRequired>
				<ord:lineItems>
					<ord:lineItem>
						<ord:cartLineNumber>001</ord:cartLineNumber>
						<ord:ATDProductNumber>{$sku}</ord:ATDProductNumber>
						<ord:quantity>{$qty}</ord:quantity>
					</ord:lineItem>
				</ord:lineItems>
			</ord:order>
		</ord:placeOrderRequest>
	   </soapenv:Body>
	</soapenv:Envelope>
XML;
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, 'https://sth.atdconnect.com/ws/1_1/orderShipToHome.wsdl');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

	$headers = array();
	array_push($headers, "Content-Type: text/xml; charset=utf-8");

	curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_request);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	$response = curl_exec($ch);

	$clean_xml = str_ireplace(array('SOAP-ENV:', 'SOAP:', 'ns2:', 'ns3:' ), '', $response);
	$response = simplexml_load_string($clean_xml);
	if ( isset( $response->Body->Fault ) ) {
		header( 'Content-Type: text/plain' );
		print_r( $response );
		die;
	}
	$atd_order_id = (string) $response->Body->placeOrderResponse->order->orderNumber;
	wc_delete_order_item_meta( $_GET['iid'], 'atd_order_lock' );
	wc_update_order_item_meta( $_GET['iid'], 'atd_order_id', $atd_order_id );
	wp_redirect( admin_url( 'post.php?post=' . $_GET['oid'] . '&action=edit' ) );
	die;
});
