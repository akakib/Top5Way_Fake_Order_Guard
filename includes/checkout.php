<?php
/**
 * Checkout side: remember the device, and send blocked customers to WhatsApp instead of creating an order.
 */

defined( 'ABSPATH' ) || exit;

// Device id: a random id kept in a cookie (and localStorage, so clearing cookies alone does not reset it).
add_action( 'wp_enqueue_scripts', function () {
	if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
		$deps = has_block( 'woocommerce/checkout' ) ? array( 'jquery', 'wp-api-fetch' ) : array( 'jquery' );
		wp_enqueue_script( 't5fob-device', T5FOB_URL . 'assets/device.js', $deps, T5FOB_VERSION, true );
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

/**
 * The visitor's IP from the connection itself. Headers like X-Forwarded-For can be typed by anyone,
 * so they count only when the owner says a proxy sits in front, and then only the proxy's own entry.
 */
function t5fob_ip() {
	$ip = $_SERVER['REMOTE_ADDR'] ?? '';
	if ( t5fob_settings()['behind_proxy'] ) {
		$list = explode( ',', (string) ( $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '' ) );
		$real = trim( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? end( $list ) );
		$ip   = filter_var( $real, FILTER_VALIDATE_IP ) ? $real : $ip;
	}
	return strtolower( (string) $ip );
}

function t5fob_current_device() {
	return t5fob_clean_device( $_COOKIE['t5fob_did'] ?? '' );
}

add_action( 'woocommerce_checkout_create_order', function ( $order ) {
	$did = t5fob_current_device();
	if ( $did ) {
		$order->update_meta_data( '_t5fob_device', $did );
	}
	$order->update_meta_data( '_t5fob_ip', t5fob_ip() );
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
	$ip     = t5fob_ip();

	$rule = t5fob_allowed_ip( $ip ) ? null : t5fob_find_rule( $phone, $device, $ip );
	if ( ! $rule ) {
		return;
	}

	$items   = t5fob_cart_items();
	$name    = trim( ( $data['billing_first_name'] ?? '' ) . ' ' . ( $data['billing_last_name'] ?? '' ) );
	$address = trim( implode( ', ', array_filter( array( $data['billing_address_1'] ?? '', $data['billing_address_2'] ?? '', $data['billing_city'] ?? '' ) ) ) );
	$total   = (float) WC()->cart->get_total( 'edit' );
	t5fob_log_attempt( $rule, $name, $data['billing_phone'] ?? '', $address, $items, $total, $ip, $device, t5fob_cart_snapshot( $data['payment_method'] ?? '' ) );

	// No WhatsApp number to send them to: still no order, and a plain message instead of an empty link.
	if ( ! t5fob_whatsapp_number() ) {
		$errors->add( 't5fob_blocked', T5FOB_NO_WHATSAPP );
		return;
	}

	$url = t5fob_whatsapp_url( $name, $address, $data['billing_phone'] ?? '', $items, t5fob_shipping_label(), $total );

	// Classic checkout submits by AJAX and follows "redirect" on success.
	if ( wp_doing_ajax() ) {
		// device.js sees "t5fob", opens WhatsApp and cancels WooCommerce's redirect. WooCommerce then shows
		// "messages" with its own error handler, which also stops the loading spinner.
		// Without device.js, "redirect" still sends the customer to WhatsApp.
		wp_send_json( array( 'result' => 'success', 'redirect' => $url, 't5fob' => 1, 'messages' => t5fob_whatsapp_box( $url ) ) );
	}
	wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- external WhatsApp link on purpose.
	exit;
}

const T5FOB_NO_WHATSAPP = 'We could not place this order online. Please contact us to order.';

function t5fob_whatsapp_message() {
	return t5fob_settings()['wa_message'] ?: "Please confirm your order on WhatsApp.\nTap Send in WhatsApp and our team will reply.";
}

function t5fob_whatsapp_box( $url ) {
	return '<div class="t5fob-wa-box" role="alert" style="margin:0 0 16px;padding:14px 16px;border:1px solid #25d366;border-radius:10px;background:#f0fbf4;color:#1d2320;font-size:15px;line-height:1.5">'
		. nl2br( esc_html( t5fob_whatsapp_message() ) )
		. '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener" style="display:block;margin-top:10px;padding:12px;border-radius:8px;background:#25d366;color:#fff;text-align:center;font-weight:600;text-decoration:none">Open WhatsApp</a></div>';
}

/** What the customer had in the cart, kept with the attempt so staff can turn it into an order later. */
function t5fob_cart_snapshot( $payment ) {
	$cart  = WC()->cart;
	$lines = array();
	foreach ( $cart->get_cart() as $line ) {
		$lines[] = array(
			'id'       => (int) ( $line['variation_id'] ?: $line['product_id'] ),
			'qty'      => (int) $line['quantity'],
			'subtotal' => (float) $line['line_subtotal'],
			'total'    => (float) $line['line_total'],
		);
	}
	return array(
		'lines'    => $lines,
		'shipping' => array( 'label' => t5fob_shipping_label(), 'cost' => (float) $cart->get_shipping_total() ),
		'payment'  => sanitize_key( $payment ),
	);
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
	$ip     = t5fob_ip();
	$order->update_meta_data( '_t5fob_ip', $ip );
	$rule   = t5fob_allowed_ip( $ip ) ? null : t5fob_find_rule( t5fob_phone( $order->get_billing_phone() ), $device, $ip );
	if ( ! $rule ) {
		return;
	}
	$items   = t5fob_cart_items();
	$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	$address = trim( implode( ', ', array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city() ) ) ) );
	$total   = (float) WC()->cart->get_total( 'edit' );
	t5fob_log_attempt( $rule, $name, $order->get_billing_phone(), $address, $items, $total, $ip, $device, t5fob_cart_snapshot( $order->get_payment_method() ) );
	if ( ! t5fob_whatsapp_number() ) {
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 't5fob_blocked', T5FOB_NO_WHATSAPP, 400 ); // phpcs:ignore
	}
	$url = t5fob_whatsapp_url( $name, $address, $order->get_billing_phone(), $items, t5fob_shipping_label(), $total );
	// Plain text with the number, in case the button below cannot be added; device.js adds it from the extra data.
	throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 't5fob_blocked', preg_replace( '/\s+/', ' ', t5fob_whatsapp_message() ) . ' WhatsApp: +' . t5fob_whatsapp_number(), 400, array( 't5fob_url' => $url, 't5fob_box' => t5fob_whatsapp_box( $url ) ) ); // phpcs:ignore
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

function t5fob_whatsapp_url( $name, $address, $phone, $items, $shipping, $total ) {
	$lines = array(
		'Please confirm my order:',
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
