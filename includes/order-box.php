<?php
/**
 * Order screen: a "Fake Order Guard" box with the customer's record and a one-tap Block button.
 */

defined( 'ABSPATH' ) || exit;

function t5fob_order_screen() {
	return function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
}

add_action( 'add_meta_boxes', function () {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	add_meta_box( 't5fob-box', 'Fake Order Guard', 't5fob_render_box', t5fob_order_screen(), 'side', 'high' );
} );

/** Phone, device and IP of an order. */
function t5fob_order_values( $order ) {
	return array(
		'phone'  => t5fob_phone( $order->get_billing_phone() ),
		'device' => t5fob_clean_device( $order->get_meta( '_t5fob_device' ) ),
		'ip'     => strtolower( (string) ( $order->get_meta( '_t5fob_ip' ) ?: $order->get_customer_ip_address() ) ),
	);
}

function t5fob_render_box( $post_or_order ) {
	$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
	if ( ! $order ) {
		return;
	}
	$v     = t5fob_order_values( $order );
	$rules = t5fob_active_rules( $v['phone'], $v['device'], $v['ip'] );

	// Earlier orders with the same phone.
	// Earlier orders, however the phone was typed then (017..., 88017..., +88017...).
	$earlier = array();
	foreach ( t5fob_phone_variants( $order->get_billing_phone() ) as $variant ) {
		foreach ( wc_get_orders( array( 'billing_phone' => $variant, 'exclude' => array( $order->get_id() ), 'limit' => 50 ) ) as $o ) {
			$earlier[ $o->get_id() ] = $o;
		}
	}
	$cancelled = 0;
	foreach ( $earlier as $o ) {
		if ( in_array( $o->get_status(), array( 'cancelled', 'failed', 'refunded', 'no-response' ), true ) ) {
			$cancelled++;
		}
	}

	// Steadfast allows only a few searches a day, so use the record saved at checkout when there is one.
	$sf = t5fob_saved_score( $order );
	if ( ! is_array( $sf ) ) {
		$sf = t5fob_score_order( $order );
		if ( $sf ) {
			$order->save();
		}
	}
	$sf_html = t5fob_score_html( $sf );
	?>
	<div class="t5fob-box">
		<div class="t5fob-row"><span>Steadfast record</span><span><?php echo $sf_html; // phpcs:ignore -- built above from ints. ?></span></div>
		<div class="t5fob-row"><span>Device</span><span><?php echo esc_html( t5fob_device_label( $order->get_customer_user_agent() ) ); ?></span></div>
		<div class="t5fob-row"><span>Earlier orders, same phone</span><span><?php echo count( $earlier ) ? esc_html( count( $earlier ) . ( $cancelled ? " ($cancelled cancelled)" : '' ) ) : 'None'; ?></span></div>
		<div class="t5fob-row"><span>Status</span><span>
			<?php if ( $rules ) : ?>
				<span class="t5fob-badge bad">Blocked: <?php echo esc_html( implode( ', ', array_map( 't5fob_type_label', array_keys( $rules ) ) ) ); ?></span>
			<?php else : ?>
				<span class="t5fob-badge ok">Not blocked</span>
			<?php endif; ?>
		</span></div>
		<p>
			<?php if ( $rules ) : ?>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=t5fob_unblock&order_id=' . $order->get_id() ), 't5fob_unblock' ) ); ?>">Unblock</a>
			<?php else : ?>
				<button type="button" class="button t5fob-btn-red" data-t5fob-open>Block customer</button>
			<?php endif; ?>
		</p>
	</div>
	<?php
	if ( ! $rules ) {
		// The block form lives outside the order form (forms cannot be nested).
		add_action( 'admin_footer', function () use ( $order, $v ) {
			t5fob_render_block_modal( $order, $v );
		} );
	}
}

function t5fob_render_block_modal( $order, $v ) {
	?>
	<div class="t5fob-modal-bg" id="t5fob-modal" hidden>
		<form class="t5fob-modal" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="t5fob_block">
			<input type="hidden" name="order_id" value="<?php echo (int) $order->get_id(); ?>">
			<?php wp_nonce_field( 't5fob_block' ); ?>
			<h3>Block this customer</h3>
			<p class="t5fob-muted">Order #<?php echo esc_html( $order->get_order_number() . ' · ' . $order->get_formatted_billing_full_name() ); ?></p>

			<label class="t5fob-chk"><input type="checkbox" name="types[]" value="phone" <?php checked( (bool) $v['phone'] ); disabled( ! $v['phone'] ); ?>>
				<span>Phone <?php echo esc_html( $v['phone'] ?: 'not found' ); ?><small>Most reliable, belongs to one person</small></span></label>
			<label class="t5fob-chk"><input type="checkbox" name="types[]" value="device" <?php checked( (bool) $v['device'] ); disabled( ! $v['device'] ); ?>>
				<span>This device<small><?php echo $v['device'] ? 'Works even when the IP changes' : 'Not recorded for this order (orders placed before the plugin)'; ?></small></span></label>
			<?php $t5_ip_ok = $v['ip'] && ! t5fob_allowed_ip( $v['ip'] ); ?>
			<label class="t5fob-chk"><input type="checkbox" name="types[]" value="ip" <?php disabled( ! $t5_ip_ok ); ?>>
				<span>IP <?php echo esc_html( $v['ip'] ?: 'not found' ); ?><small><?php echo $v['ip'] && ! $t5_ip_ok ? 'Office / allowed IP, never blocked' : 'Off by default: a mobile IP is shared by many real customers. Tick only for home Wi-Fi'; ?></small></span></label>

			<p><strong>How long</strong></p>
			<div class="t5fob-seg">
				<label><input type="radio" name="days" value="7"><span>7 days</span></label>
				<label><input type="radio" name="days" value="30" checked><span>30 days</span></label>
				<label><input type="radio" name="days" value="0"><span>Permanent</span></label>
			</div>
			<p class="t5fob-muted">IP is always blocked for 7 days at most.</p>
			<textarea name="reason" rows="2" placeholder="Reason (optional)"></textarea>
			<div class="t5fob-actions">
				<button type="button" class="button" data-t5fob-close>Cancel</button>
				<button type="submit" class="button t5fob-btn-red">Block</button>
			</div>
		</form>
	</div>
	<?php
}

add_action( 'admin_post_t5fob_block', function () {
	check_admin_referer( 't5fob_block' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Not allowed.' );
	}
	$order = wc_get_order( absint( $_POST['order_id'] ?? 0 ) );
	if ( ! $order ) {
		wp_die( 'Order not found.' );
	}
	$days   = in_array( (int) ( $_POST['days'] ?? 30 ), array( 0, 7, 30 ), true ) ? (int) $_POST['days'] : 30;
	$reason = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
	$types  = array_intersect( (array) ( $_POST['types'] ?? array() ), array( 'phone', 'device', 'ip' ) );
	$v      = t5fob_order_values( $order );
	$done   = array();
	foreach ( $types as $type ) {
		// Mobile IPs are shared by many people, so an IP block never lasts longer than 7 days.
		$d = 'ip' === $type ? ( $days ? min( $days, 7 ) : 7 ) : $days;
		if ( t5fob_add_rule( $type, $v[ $type ], $d, $reason, $order->get_id() ) ) {
			$done[] = t5fob_type_label( $type ) . ' (' . ( $d ? $d . ' days' : 'permanent' ) . ')';
		}
	}
	if ( $done ) {
		$order->add_order_note( 'Fake Order Guard: blocked ' . implode( ', ', $done ) . ( $reason ? '. Reason: ' . $reason : '' ) . '.', false, true );
	}
	wp_safe_redirect( $order->get_edit_order_url() );
	exit;
} );

// Unblock either one rule (from the Block list) or everything matching an order.
add_action( 'admin_post_t5fob_unblock', function () {
	check_admin_referer( 't5fob_unblock' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Not allowed.' );
	}
	global $wpdb;
	$t = t5fob_table( 'rules' );
	if ( ! empty( $_GET['rule_id'] ) ) {
		$wpdb->delete( $t, array( 'id' => absint( $_GET['rule_id'] ) ) );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=t5fob&tab=rules' ) );
		exit;
	}
	$order = wc_get_order( absint( $_GET['order_id'] ?? 0 ) );
	if ( $order ) {
		foreach ( t5fob_active_rules( ...array_values( t5fob_order_values( $order ) ) ) as $r ) {
			$wpdb->delete( $t, array( 'id' => $r->id ) );
		}
		$order->add_order_note( 'Fake Order Guard: unblocked.', false, true );
		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}
	wp_safe_redirect( admin_url( 'admin.php?page=t5fob' ) );
	exit;
} );

function t5fob_ratio_class( $percent ) {
	return $percent >= 80 ? 'ok' : ( $percent >= 50 ? 'warn' : 'bad' );
}
