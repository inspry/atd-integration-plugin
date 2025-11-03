<?php
/**
 * ATD API Client
 *
 * Handles all communication with ATD's SOAP APIs
 *
 * @package ATD_Integration
 * @since 2.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ATD API Client Class
 *
 * Centralized API communication with ATD's SOAP services
 */
class ATD_API_Client {

	/**
	 * ATD username
	 *
	 * @var string|null
	 */
	private ?string $username;

	/**
	 * ATD password
	 *
	 * @var string|null
	 */
	private ?string $password;

	/**
	 * ATD API key
	 *
	 * @var string|null
	 */
	private ?string $api_key;

	/**
	 * ATD location number
	 *
	 * @var string
	 */
	private string $location_number;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->username = defined( 'ATD_USERNAME' ) ? ATD_USERNAME : null;
		$this->password = defined( 'ATD_PASSWORD' ) ? ATD_PASSWORD : null;
		$this->api_key = defined( 'ATD_API_KEY' ) ? ATD_API_KEY : null;
		$this->location_number = '1320769'; // TODO: Make configurable
	}

	/**
	 * Check if API credentials are properly configured
	 *
	 * @return bool True if all credentials are available
	 */
	public function is_configured(): bool {
		return ! empty( $this->username ) && ! empty( $this->password ) && ! empty( $this->api_key );
	}

	/**
	 * Get missing configuration items
	 *
	 * @return array Array of missing configuration constant names
	 */
	public function get_missing_config(): array {
		$missing = array();
		if ( empty( $this->username ) ) $missing[] = 'ATD_USERNAME';
		if ( empty( $this->password ) ) $missing[] = 'ATD_PASSWORD';
		if ( empty( $this->api_key ) ) $missing[] = 'ATD_API_KEY';
		return $missing;
	}

	/**
	 * Make a secure cURL request to ATD API
	 *
	 * @param string $url      The API endpoint URL
	 * @param string $xml_data The SOAP XML request data
	 *
	 * @return string|WP_Error The response string or WP_Error on failure
	 */
	private function make_curl_request( string $url, string $xml_data ): string|WP_Error {
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $xml_data );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, array( 'Content-Type: text/xml; charset=utf-8' ) );

		$response = curl_exec( $ch );
		$curl_error = curl_error( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );

		if ( $curl_error ) {
			return new WP_Error( 'curl_error', $curl_error );
		}

		if ( $http_code >= 400 ) {
			return new WP_Error( 'http_error', 'HTTP ' . $http_code );
		}

		return $response;
	}

	/**
	 * Clean and parse SOAP XML response
	 *
	 * @param string $response The raw SOAP XML response
	 *
	 * @return SimpleXMLElement|WP_Error Parsed XML or WP_Error on failure
	 */
	private function parse_soap_response( string $response ): WP_Error|SimpleXMLElement {
		$clean_xml = str_ireplace(
			array( 'SOAP-ENV:', 'SOAP:', 'ns2:', 'ns3:', 'ns4:', 'pc:', 'c:' ),
			'',
			$response
		);

		$parsed = simplexml_load_string( $clean_xml );
		if ( false === $parsed ) {
			return new WP_Error( 'xml_parse_error', 'Failed to parse XML response' );
		}

		return $parsed;
	}

	/**
	 * Get order details from ATD
	 *
	 * @param string $confirmation_number The ATD order confirmation number
	 *
	 * @return SimpleXMLElement|WP_Error Order details or WP_Error on failure
	 */
	public function get_order_details( string $confirmation_number ): SimpleXMLElement|WP_Error {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', 'API credentials not configured: ' . implode( ', ', $this->get_missing_config() ) );
		}

		$xml_request = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ord="http://api.atdconnect.com/atd/3_4/orderStatus">
      <soapenv:Header>
      <wsse:Security soapenv:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
         <wsse:UsernameToken atd:clientId="IAPDI_ASAP" xmlns:atd="http://api.atdconnect.com/atd">
            <wsse:Username>{$this->username}</wsse:Username>
            <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">{$this->password}</wsse:Password>
         </wsse:UsernameToken>
      </wsse:Security>
   </soapenv:Header>
   <soapenv:Body>
      <ord:getOrderDetailRequest>
		<ord:locationNumber>{$this->location_number}</ord:locationNumber>
		<ord:confirmationNumber>{$confirmation_number}</ord:confirmationNumber>
      </ord:getOrderDetailRequest>
   </soapenv:Body>
</soapenv:Envelope>
XML;

		$response = $this->make_curl_request( 'https://ws.atdconnect.com/ws/3_4/orderStatus.wsdl', $xml_request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_soap_response( $response );
	}

	/**
	 * Get product information by SKU
	 *
	 * @param string $sku The product SKU to look up
	 *
	 * @return SimpleXMLElement|WP_Error Product information or WP_Error on failure
	 */
	public function get_product_by_sku( string $sku ): SimpleXMLElement|WP_Error {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', 'API credentials not configured: ' . implode( ', ', $this->get_missing_config() ) );
		}

		$xml_request = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:prod="http://sth.atdconnect.com/atd/1_1/productShipToHome" xmlns:com="http://sth.atdconnect.com/atd/1_1/commonShipToHome" xmlns:prod1="http://sth.atdconnect.com/atd/1_1/productShipToHomeCommon">
      <soapenv:Header>
      <wsse:Security soapenv:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
         <wsse:UsernameToken atd:clientId="IAPDI_ASAP" xmlns:atd="http://api.atdconnect.com/atd">
            <wsse:Username>{$this->username}</wsse:Username>
            <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">{$this->password}</wsse:Password>
         </wsse:UsernameToken>
      </wsse:Security>
   </soapenv:Header>
   <soapenv:Body>
      <prod:getProductByCriteriaRequest>
         <prod:locationNumber>{$this->location_number}</prod:locationNumber>
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

		$response = $this->make_curl_request( 'https://sth.atdconnect.com/ws/1_1/products.wsdl', $xml_request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $this->parse_soap_response( $response );
	}

	/**
	 * Generate JWT token for order placement
	 *
	 * @param array $shipping_data Array of shipping information
	 *
	 * @return string The JWT token
	 */
	private function generate_jwt_token( array $shipping_data ): string {
		$header = array(
			'alg' => 'HS256',
			'typ' => 'JWT'
		);

		$payload = array(
			'location'   => $this->location_number,
			'name'       => trim( $shipping_data['first_name'] . ' ' . $shipping_data['last_name'] ),
			'address1'   => trim( $shipping_data['address_1'] . ' ' . $shipping_data['address_2'] ),
			'city'       => trim( $shipping_data['city'] ),
			'state'      => trim( $shipping_data['state'] ),
			'postalCode' => trim( $shipping_data['postcode'] ),
			'country'    => 'US'
		);

		$header_encoded = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( json_encode( $header ) ) );
		$payload_encoded = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( json_encode( $payload ) ) );
		$signature = hash_hmac( 'sha256', $header_encoded . '.' . $payload_encoded, $this->api_key, true );
		$signature_encoded = str_replace( array( '+', '/', '=' ), array( '-', '_', '' ), base64_encode( $signature ) );

		return $header_encoded . '.' . $payload_encoded . '.' . $signature_encoded;
	}

	/**
	 * Place an order with ATD
	 *
	 * @param array $order_data Array containing order information
	 *
	 * @return SimpleXMLElement|WP_Error Order response or WP_Error on failure
	 */
	public function place_order( array $order_data ): SimpleXMLElement|WP_Error {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'not_configured', 'API credentials not configured: ' . implode( ', ', $this->get_missing_config() ) );
		}

		$auth_token = $this->generate_jwt_token( $order_data['shipping'] );

		$xml_request = <<<XML
<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ord="http://sth.atdconnect.com/atd/1_1/orderShipToHome" xmlns:com="http://sth.atdconnect.com/atd/1_1/commonShipToHome">
      <soapenv:Header>
      <wsse:Security soapenv:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
         <wsse:UsernameToken atd:clientId="IAPDI_ASAP" atd:authToken="Bearer {$auth_token}" xmlns:atd="http://api.atdconnect.com/atd">
            <wsse:Username>{$this->username}</wsse:Username>
            <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordText">{$this->password}</wsse:Password>
         </wsse:UsernameToken>
      </wsse:Security>
   </soapenv:Header>
   <soapenv:Body>
	<ord:placeOrderRequest>
		<ord:locationNumber>{$this->location_number}</ord:locationNumber>
		<ord:order>
			<ord:customerPONumber>{$order_data['order_number']}</ord:customerPONumber>
			<ord:consumerData>
				<ord:name>{$order_data['shipping']['first_name']} {$order_data['shipping']['last_name']}</ord:name>
				<ord:phoneNumber>{$order_data['shipping']['phone']}</ord:phoneNumber>
				<ord:emailAddress>{$order_data['shipping']['email']}</ord:emailAddress>
				<ord:address>
					<com:address1>{$order_data['shipping']['address_1']} {$order_data['shipping']['address_2']}</com:address1>
					<com:city>{$order_data['shipping']['city']}</com:city>
					<com:state>{$order_data['shipping']['state']}</com:state>
					<com:postalCode>{$order_data['shipping']['postcode']}</com:postalCode>
					<com:country>US</com:country>
				</ord:address>
				<ord:deliveryInstructions>**MUST SHIP SIGNATURE REQUIRED**</ord:deliveryInstructions>
			</ord:consumerData>
			<ord:shippingMethod>CHEAPEST_FREIGHT</ord:shippingMethod>
			<ord:signatureRequired>DirectSignature</ord:signatureRequired>
			<ord:lineItems>
				<ord:lineItem>
					<ord:cartLineNumber>001</ord:cartLineNumber>
					<ord:ATDProductNumber>{$order_data['sku']}</ord:ATDProductNumber>
					<ord:quantity>{$order_data['quantity']}</ord:quantity>
				</ord:lineItem>
			</ord:lineItems>
		</ord:order>
	</ord:placeOrderRequest>
   </soapenv:Body>
</soapenv:Envelope>
XML;

		$response = $this->make_curl_request( 'https://sth.atdconnect.com/ws/1_1/orderShipToHome.wsdl', $xml_request );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$parsed_response = $this->parse_soap_response( $response );
		if ( is_wp_error( $parsed_response ) ) {
			return $parsed_response;
		}

		// Check for SOAP faults
		if ( isset( $parsed_response->Body->Fault ) ) {
			return new WP_Error( 'soap_fault', (string) $parsed_response->Body->Fault->faultstring );
		}

		// Validate response structure
		if ( ! isset( $parsed_response->Body->placeOrderResponse->order->orderNumber ) ) {
			return new WP_Error( 'invalid_response', 'Missing order number in API response' );
		}

		return $parsed_response;
	}
}
