<?php
/**
 * The Steadfast record of each new order, and the repeat-order check that reads it.
 * Moving orders to Record Verified belongs to Top5Way Tracking, not here.
 */

defined( 'ABSPATH' ) || exit;

/** Past parcels and delivery % from a Steadfast record (exact counts when sent, else ratio + volume "50+"). */
function t5fob_sf_numbers( $sf ) {
	if ( null !== $sf['delivered'] || null !== $sf['cancelled'] ) {
		$parcels = (int) $sf['delivered'] + (int) $sf['cancelled'];
		return array( $parcels, $parcels ? $sf['delivered'] / $parcels * 100 : 0 );
	}
	return array( (int) $sf['volume'], (float) $sf['ratio'] );
}

/** The badge used in the order box and the Score column. */
function t5fob_score_html( $sf ) {
	if ( null === $sf ) {
		return '<span class="t5fob-muted">Not available</span>';
	}
	if ( $sf['delivered'] || $sf['cancelled'] ) {
		$all  = $sf['delivered'] + $sf['cancelled'];
		$html = '<span class="t5fob-badge ' . t5fob_ratio_class( $sf['delivered'] / $all * 100 ) . '">' . $sf['delivered'] . ' of ' . $all . ' delivered</span>';
	} elseif ( null !== $sf['ratio'] && '' !== $sf['volume'] ) {
		$html = '<span class="t5fob-badge ' . t5fob_ratio_class( $sf['ratio'] ) . '">' . round( $sf['ratio'] ) . '% of ' . esc_html( $sf['volume'] ) . '</span>';
	} else {
		$html = '<span class="t5fob-badge">No record</span>';
	}
	if ( $sf['reports'] ) {
		$html .= ' <span class="t5fob-muted">' . $sf['reports'] . ' report' . ( $sf['reports'] > 1 ? 's' : '' ) . '</span>';
	}
	return $html;
}

/** The record already saved on the order: ours, or the one Top5Way Tracking saved, so Steadfast is not asked twice. */
function t5fob_saved_score( $order ) {
	$sf = $order->get_meta( '_t5fob_score' );
	if ( is_array( $sf ) ) {
		return $sf;
	}
	$t = $order->get_meta( '_t5_sf_score' );
	return is_array( $t ) ? array( 'delivered' => (int) $t['delivered'], 'cancelled' => (int) $t['total'] - (int) $t['delivered'], 'ratio' => (float) $t['ratio'], 'volume' => '', 'reports' => (int) $t['reports'] ) : null;
}

/** Fetch the order's Steadfast record and keep it on the order for the Score column. */
function t5fob_score_order( $order ) {
	$sf = t5fob_steadfast( $order->get_billing_phone() );
	if ( null !== $sf ) {
		$order->update_meta_data( '_t5fob_score', $sf );
	}
	return $sf;
}

/** Does a record meet the minimums in Settings? */
function t5fob_record_passes( $sf ) {
	$s = t5fob_settings();
	list( $parcels, $ratio ) = t5fob_sf_numbers( $sf );
	return $parcels >= $s['min_parcels'] && $ratio >= $s['min_ratio'] && $sf['reports'] <= $s['max_reports'];
}

/** One Steadfast request per order, kept on the order. Never changes its status. */
function t5fob_fetch_score( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || $order->get_meta( '_t5fob_checked' ) ) {
		return;
	}
	$order->update_meta_data( '_t5fob_checked', time() );
	t5fob_score_order( $order );
	$order->save();
}

/** Can this site get a Steadfast record at all? Without one, only the counts apply. */
function t5fob_can_score() {
	if ( defined( 'T5_TRACKING_VERSION' ) ) {
		return (bool) t5_trk( 'sf_api_key' );
	}
	$s = t5fob_settings();
	return (bool) ( $s['sf_key'] ?: get_option( 'api_settings_tab_api_key' ) );
}

/**
 * good, none or bad, from the record saved on an earlier order. Never asks Steadfast.
 * Too few parcels to judge counts as none: a new customer is not a bad one.
 */
function t5fob_record_type( $order_id ) {
	if ( ! t5fob_can_score() ) {
		return 'good';
	}
	$order    = wc_get_order( $order_id );
	$tracking = defined( 'T5_TRACKING_VERSION' );
	$score    = $order && $tracking ? $order->get_meta( '_t5_sf_score' ) : null;
	$sf       = $order ? t5fob_saved_score( $order ) : null;
	if ( ! is_array( $sf ) || ( $tracking && ! is_array( $score ) ) ) {
		return 'none';
	}
	if ( $tracking ? t5_score_passes( $score ) : t5fob_record_passes( $sf ) ) {
		return 'good';
	}
	$s = t5fob_settings();
	list( $parcels ) = t5fob_sf_numbers( $sf );
	$min = $tracking ? max( 1, (int) t5_trk( 'min_parcels' ) ) : (int) $s['min_parcels'];
	$max = $tracking ? (int) t5_trk( 'max_reports' ) : (int) $s['max_reports'];
	return $parcels < $min && $sf['reports'] <= $max ? 'none' : 'bad';
}

/**
 * Stops the same phone, device or IP ordering again and again. Returns a rule-like object, or null.
 * A phone holds up to C orders at once and earns one more every g seconds, so one phone
 * places at most C + 86400 / g orders a day, and a customer who waits is never stopped for good.
 */
function t5fob_repeat_rule( $phone, $device, $ip ) {
	global $wpdb;
	$s = t5fob_settings();
	// A repeat customer may be real: with no WhatsApp number there is nowhere to send them, so the order goes through.
	if ( ! $s['repeat_on'] || ! t5fob_whatsapp_number() ) {
		return null;
	}
	$t = t5fob_table( 'recent' );

	// Device and IP: a burst in the short time.
	$burst = $wpdb->get_results( $wpdb->prepare(
		"SELECT phone, device, ip FROM $t WHERE created_at > %s AND ( device = %s OR ip = %s ) LIMIT 200",
		gmdate( 'Y-m-d H:i:s', time() - 60 * (int) $s['repeat_mins'] ), $device ?: '-', $ip ?: '-'
	) );
	$numbers = array();
	$ips     = 0;
	foreach ( $burst as $r ) {
		if ( $device && $r->device === $device && $r->phone !== $phone ) {
			$numbers[ $r->phone ] = 1;
		}
		$ips += $r->ip === $ip ? 1 : 0;
	}
	if ( count( $numbers ) >= (int) $s['repeat_device'] || $ips >= (int) $s['repeat_ip'] ) {
		return (object) array( 'id' => 0, 'type' => 'repeat' );
	}

	// Phone: its own query, so a busy shared IP cannot push its orders out of the list.
	$rows = $phone ? $wpdb->get_results( $wpdb->prepare(
		"SELECT order_id, created_at FROM $t WHERE phone = %s AND created_at > %s ORDER BY id DESC LIMIT 50",
		$phone, gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS )
	) ) : array();
	if ( ! $rows ) {
		return null;
	}
	$type   = t5fob_record_type( $rows[0]->order_id );
	$cap    = (int) $s[ "repeat_{$type}_c" ];
	$gap    = (int) $s[ "repeat_{$type}_h" ] * HOUR_IN_SECONDS;
	$tokens = $cap;
	$prev   = null;
	foreach ( array_reverse( $rows ) as $r ) {
		$at     = strtotime( $r->created_at . ' UTC' );
		$tokens = null === $prev ? $tokens : min( $cap, $tokens + ( $at - $prev ) / $gap );
		$tokens = max( 0, $tokens - 1 );
		$prev   = $at;
	}
	$tokens = min( $cap, $tokens + ( time() - $prev ) / $gap );
	return $tokens < 1 ? (object) array( 'id' => 0, 'type' => 'repeat' ) : null;
}

/** Remember each placed order for the repeat check. */
function t5fob_remember_order( $order ) {
	global $wpdb;
	$order = is_object( $order ) ? $order : wc_get_order( $order );
	if ( $order && t5fob_whatsapp_number() ) {
		$wpdb->insert( t5fob_table( 'recent' ), array(
			'order_id'   => $order->get_id(),
			'phone'      => t5fob_phone( $order->get_billing_phone() ),
			'device'     => t5fob_current_device(),
			'ip'         => t5fob_ip(),
			'created_at' => t5fob_now(),
		) );
	}
}
add_action( 'woocommerce_checkout_order_processed', 't5fob_remember_order', 40 );
add_action( 'woocommerce_store_api_checkout_order_processed', 't5fob_remember_order', 40 );

function t5fob_is_prepaid( $method ) {
	return in_array( (string) $method, (array) t5fob_settings()['paid_methods'], true );
}

/** A paid order proves a real customer, so it does not use up their repeat orders. */
function t5fob_forget_paid_order( $order_id ) {
	global $wpdb;
	$order = wc_get_order( $order_id );
	if ( $order && t5fob_is_prepaid( $order->get_payment_method() ) ) {
		$wpdb->delete( t5fob_table( 'recent' ), array( 'order_id' => $order->get_id() ) );
	}
}
add_action( 'woocommerce_payment_complete', 't5fob_forget_paid_order' );

// Runs after the customer already has the thank-you page, so checkout never waits for Steadfast.
function t5fob_queue_verify( $order ) {
	if ( defined( 'T5_TRACKING_VERSION' ) ) {
		return; // Top5Way Tracking asks Steadfast
	}
	$order_id = is_object( $order ) ? $order->get_id() : (int) $order;
	add_action( 'shutdown', function () use ( $order_id ) {
		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		} elseif ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		t5fob_fetch_score( $order_id );
	} );
}
add_action( 'woocommerce_checkout_order_processed', 't5fob_queue_verify', 50 );
add_action( 'woocommerce_store_api_checkout_order_processed', 't5fob_queue_verify', 50 );

// Score column on the orders list, in place of the Steadfast plugin's own Score column.
function t5fob_score_column( $columns ) {
	if ( defined( 'T5_TRACKING_VERSION' ) ) {
		return $columns; // it has its own Steadfast column
	}
	$out = array();
	foreach ( $columns as $key => $label ) {
		if ( 'courier_score' === $key ) {
			continue;
		}
		$out[ $key ] = $label;
		if ( 'order_status' === $key ) {
			$out['t5fob_score'] = 'Score';
		}
	}
	return $out;
}
add_filter( 'woocommerce_shop_order_list_table_columns', 't5fob_score_column', 99 );
add_filter( 'manage_edit-shop_order_columns', 't5fob_score_column', 99 );

function t5fob_score_cell( $column, $order = null ) {
	if ( 't5fob_score' !== $column ) {
		return;
	}
	$order = $order instanceof WC_Order ? $order : wc_get_order( get_the_ID() );
	$sf    = $order ? $order->get_meta( '_t5fob_score' ) : null;
	echo is_array( $sf ) ? t5fob_score_html( $sf ) : '<span class="t5fob-muted">Not checked</span>'; // phpcs:ignore -- built from ints and escaped text.
}
add_action( 'woocommerce_shop_order_list_table_custom_column', 't5fob_score_cell', 10, 2 );
add_action( 'manage_shop_order_posts_custom_column', 't5fob_score_cell', 10, 1 );
