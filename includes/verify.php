<?php
/**
 * Auto Record Verified: checks each new order's phone on Steadfast after checkout and moves
 * good-history orders to Record Verified. Also the Score column on the orders list.
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

/** Why an order does not pass the rule, or '' when it passes. */
function t5fob_verify_fail_reason( $sf, $total ) {
	$s = t5fob_settings();
	list( $parcels, $ratio ) = t5fob_sf_numbers( $sf );
	if ( $s['max_total'] > 0 && $total > $s['max_total'] ) {
		return 'Order total is above ' . number_format( $s['max_total'] ) . ', call to confirm.';
	}
	if ( $parcels < $s['min_parcels'] ) {
		return 'Fewer than ' . $s['min_parcels'] . ' past parcels, call to confirm.';
	}
	if ( $ratio < $s['min_ratio'] ) {
		return 'Delivery below ' . $s['min_ratio'] . '%, call to confirm.';
	}
	if ( $sf['reports'] > $s['max_reports'] ) {
		return 'More than ' . $s['max_reports'] . ' reports, call to confirm.';
	}
	return '';
}

function t5fob_auto_verify( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || $order->get_meta( '_t5fob_checked' ) ) {
		return;
	}
	$order->update_meta_data( '_t5fob_checked', time() );
	$sf = t5fob_score_order( $order );
	if ( ! t5fob_settings()['auto_verify'] || ! wc_is_order_status( 'wc-record-verified' ) || ! $order->has_status( array( 'on-hold', 'processing', 'pending' ) ) ) {
		$order->save();
		return;
	}
	if ( null === $sf ) {
		$order->add_order_note( 'Steadfast record not available. Call to confirm.' );
		$order->save();
		return;
	}
	list( $parcels, $ratio ) = t5fob_sf_numbers( $sf );
	$line   = sprintf( 'Steadfast: %s%% delivered of %s parcels, %d reports.', round( $ratio ), $sf['volume'] ?: $parcels, $sf['reports'] );
	$reason = t5fob_verify_fail_reason( $sf, (float) $order->get_total() );
	if ( '' === $reason ) {
		$order->update_status( 'record-verified', $line . ' Auto Record Verified.' );
	} else {
		$order->add_order_note( $line . ' ' . $reason );
		$order->save();
	}
}

// Runs after the customer already has the thank-you page, so checkout never waits for Steadfast.
function t5fob_queue_verify( $order ) {
	if ( defined( 'T5_TRACKING_VERSION' ) ) {
		return; // Top5Way Tracking checks the score and verifies the order
	}
	$order_id = is_object( $order ) ? $order->get_id() : (int) $order;
	add_action( 'shutdown', function () use ( $order_id ) {
		if ( function_exists( 'litespeed_finish_request' ) ) {
			litespeed_finish_request();
		} elseif ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		t5fob_auto_verify( $order_id );
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
