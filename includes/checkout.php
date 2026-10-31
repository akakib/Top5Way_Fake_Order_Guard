<?php
/**
 * Checkout side: remember the device, and send blocked customers to WhatsApp instead of creating an order.
 */

defined( 'ABSPATH' ) || exit;

// Device id: a random id kept in a cookie (and localStorage, so clearing cookies alone does not reset it).
add_action( 'wp_enqueue_scripts', function () {
	if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
		wp_enqueue_script( 't5fob-device', T5FOB_URL . 'assets/device.js', array( 'jquery' ), T5FOB_VERSION . '.2', true );
	}
} );

// Server-side fallback in case a cache or JS optimizer delays device.js (checkout is never page-cached).
add_action( 'template_redirect', function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || t5fob_current_device() || headers_sent() ) {
		return;
	}
	$id = bin2hex( random_bytes( 16 ) );
	setcookie( 't5fob_did', $id, array( 'expires' => time() + 2 * YEAR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'samesite' => 'Lax' ) );
	$_COOKIE['t5fob_did'] = $id;
} );

function t5fob_current_device() {
	return t5fob_clean_device( $_COOKIE['t5fob_did'] ?? '' );
}

add_action( 'woocommerce_checkout_create_order', function ( $order ) {
	$did = t5fob_current_device();
	if ( $did ) {
		$order->update_meta_data( '_t5fob_device', $did );
	}
} );

// Runs after WooCommerce's own checks and before the order exists.
add_action( 'woocommerce_after_checkout_validation', 't5fob_check_checkout', 999, 2 );

function t5fob_check_checkout( $data, $errors ) {
	// Let the customer fix normal form errors first (wrong phone etc.).
	if ( $errors->has_errors() || wc_notice_count( 'error' ) > 0 ) {
		return;
	}
	$phone  = t5fob_phone( $data['billing_phone'] ?? '' );
	$device = t5fob_current_device();
	$ip     = strtolower( (string) WC_Geolocation::get_ip_address() );

	$rule = t5fob_allowed_ip( $ip ) ? null : t5fob_find_rule( $phone, $device, $ip );
	if ( ! $rule ) {
		return;
	}

	$items   = t5fob_cart_items();
	$name    = trim( ( $data['billing_first_name'] ?? '' ) . ' ' . ( $data['billing_last_name'] ?? '' ) );
	$address = trim( implode( ', ', array_filter( array( $data['billing_address_1'] ?? '', $data['billing_address_2'] ?? '', $data['billing_city'] ?? '' ) ) ) );
	$total   = (float) WC()->cart->get_total( 'edit' );
	$code    = t5fob_log_attempt( $rule, $name, $data['billing_phone'] ?? '', $address, $items, $total, $ip, $device );

	$url = t5fob_whatsapp_url( $code, $name, $address, $data['billing_phone'] ?? '', $items, t5fob_shipping_label(), $total );

	// Classic checkout submits by AJAX and follows "redirect" on success.
	if ( wp_doing_ajax() ) {
		// device.js sees "t5fob", opens WhatsApp and cancels WooCommerce's redirect. WooCommerce then shows
		// "messages" with its own error handler, which also stops the loading spinner.
		// Without device.js, "redirect" still sends the customer to WhatsApp.
		$box = '<div class="t5fob-wa-box" role="alert" style="margin:0 0 16px;padding:14px 16px;border:1px solid #25d366;border-radius:10px;background:#f0fbf4;color:#1d2320;font-size:15px;line-height:1.5">'
			. '<strong>Please confirm your order on WhatsApp.</strong><br>Tap Send in WhatsApp and our team will reply.'
			. '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" style="display:block;margin-top:10px;padding:12px;border-radius:8px;background:#25d366;color:#fff;text-align:center;font-weight:600;text-decoration:none">Open WhatsApp</a></div>';
		wp_send_json( array( 'result' => 'success', 'redirect' => $url, 't5fob' => 1, 'messages' => $box ) );
	}
	wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- external WhatsApp link on purpose.
	exit;
}

function t5fob_cart_items() {
	$cart = WC()->cart;
	$cart->calculate_totals();
	$items = array();
	foreach ( $cart->get_cart() as $line ) {
		$items[] = $line['data']->get_name() . ' x' . (int) $line['quantity'];
	}
	return $items;
}

// Block (Store API) checkout: same check, before the order is placed. The customer sees the
// message with the WhatsApp link instead of an order.
add_action( 'woocommerce_store_api_checkout_update_order_from_request', function ( $order ) {
	$device = t5fob_current_device();
	$ip     = strtolower( (string) WC_Geolocation::get_ip_address() );
	$rule   = t5fob_allowed_ip( $ip ) ? null : t5fob_find_rule( t5fob_phone( $order->get_billing_phone() ), $device, $ip );
	if ( ! $rule ) {
		return;
	}
	$items   = t5fob_cart_items();
	$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	$address = trim( implode( ', ', array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city() ) ) ) );
	$total   = (float) WC()->cart->get_total( 'edit' );
	$code    = t5fob_log_attempt( $rule, $name, $order->get_billing_phone(), $address, $items, $total, $ip, $device );
	$url     = t5fob_whatsapp_url( $code, $name, $address, $order->get_billing_phone(), $items, t5fob_shipping_label(), $total );
	throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 't5fob_blocked', 'Please confirm your order on WhatsApp: ' . $url, 400 ); // phpcs:ignore
} );

function t5fob_shipping_label() {
	$chosen = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods' ) : array();
	foreach ( WC()->shipping()->get_packages() as $i => $pkg ) {
		$id = $chosen[ $i ] ?? '';
		if ( $id && isset( $pkg['rates'][ $id ] ) ) {
			return $pkg['rates'][ $id ]->get_label();
		}
	}
	return '';
}

function t5fob_whatsapp_number() {
	$n = preg_replace( '/\D/', '', t5fob_settings()['wa_number'] );
	if ( 0 === strpos( $n, '01' ) ) {
		$n = '88' . $n;
	}
	return $n;
}

function t5fob_whatsapp_url( $code, $name, $address, $phone, $items, $shipping, $total ) {
	$lines = array(
		$code,
		'Order Now',
		'Name: ' . $name,
		'Address: ' . $address,
		'Phone: ' . $phone,
		'Product: ' . implode( ', ', $items ),
	);
	if ( $shipping ) {
		$lines[] = 'Delivery: ' . $shipping;
	}
	$symbol  = trim( str_replace( "\xC2\xA0", '', html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ) );
	$lines[] = 'Total: ' . $symbol . number_format( $total );
	return 'https://wa.me/' . t5fob_whatsapp_number() . '?text=' . rawurlencode( implode( "\n", $lines ) );
}
