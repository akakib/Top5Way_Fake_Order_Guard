<?php
/**
 * WooCommerce > Fake Order Guard: Blocked attempts, Block list, Settings.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_submenu_page( 'woocommerce', 'Fake Order Guard', 'Fake Order Guard', 'manage_woocommerce', 't5fob', 't5fob_render_page' );
}, 60 );

add_action( 'admin_enqueue_scripts', function () {
	$screen = get_current_screen();
	if ( ! $screen || ( 'woocommerce_page_t5fob' !== $screen->id && t5fob_order_screen() !== $screen->id ) ) {
		return;
	}
	wp_enqueue_style( 't5fob-admin', T5FOB_URL . 'assets/admin.css', array(), T5FOB_VERSION );
	wp_enqueue_script( 't5fob-admin', T5FOB_URL . 'assets/admin.js', array(), T5FOB_VERSION, true );
} );

function t5fob_page_url( $args = array() ) {
	return add_query_arg( $args, admin_url( 'admin.php?page=t5fob' ) );
}

/** UTC datetime from the DB -> site time, short. */
function t5fob_when( $utc ) {
	return $utc ? wp_date( 'j M, g:i A', strtotime( $utc . ' UTC' ) ) : '';
}

function t5fob_pagination( $total, $paged, $per ) {
	$pages = (int) ceil( $total / $per );
	if ( $pages < 2 ) {
		return;
	}
	echo '<div class="t5fob-pages">';
	echo wp_kses_post( paginate_links( array(
		'base'      => add_query_arg( 'paged', '%#%' ),
		'format'    => '',
		'current'   => $paged,
		'total'     => $pages,
		'prev_text' => '‹ Prev',
		'next_text' => 'Next ›',
	) ) );
	echo '</div>';
}

function t5fob_render_page() {
	$tab  = sanitize_key( $_GET['tab'] ?? 'attempts' );
	$tabs = array( 'attempts' => 'Blocked attempts', 'rules' => 'Block list', 'settings' => 'Settings' );
	if ( ! isset( $tabs[ $tab ] ) ) {
		$tab = 'attempts';
	}
	echo '<div class="wrap t5fob-wrap"><h1>Fake Order Guard</h1>';
	if ( ! t5fob_whatsapp_number() ) {
		echo '<div class="notice notice-warning"><p>Add your WhatsApp number in <a href="' . esc_url( t5fob_page_url( array( 'tab' => 'settings' ) ) ) . '">Settings</a>. Until then blocked customers only see a message asking them to contact you.</p></div>';
	}
	if ( isset( $_GET['saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
	}
	echo '<nav class="t5fob-subnav">';
	foreach ( $tabs as $k => $label ) {
		echo '<a class="' . ( $k === $tab ? 'on' : '' ) . '" href="' . esc_url( t5fob_page_url( array( 'tab' => $k ) ) ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</nav>';
	call_user_func( 't5fob_tab_' . $tab );
	echo '</div>';
}

function t5fob_tab_attempts() {
	global $wpdb;
	$t     = t5fob_table( 'attempts' );
	$q     = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
	$per   = 20;
	$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
	$where = '1=1';
	$args  = array();
	if ( '' !== $q ) {
		$like  = '%' . $wpdb->esc_like( $q ) . '%';
		$where = '( name LIKE %s OR phone LIKE %s OR id = %d )';
		$args  = array( $like, '%' . $wpdb->esc_like( t5fob_phone( $q ) ?: $q ) . '%', t5fob_code_to_id( $q ) );
	}
	$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $where", $args ) : "SELECT COUNT(*) FROM $t" );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, ( $paged - 1 ) * $per ) ) ) );
	?>
	<form class="t5fob-tools" method="get">
		<input type="hidden" name="page" value="t5fob"><input type="hidden" name="tab" value="attempts">
		<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="Search name, phone or code (e.g. BLK-1023)">
		<button class="button t5fob-btn-green">Search</button>
		<a class="button" href="<?php echo esc_url( t5fob_page_url( array( 'tab' => 'attempts' ) ) ); ?>">Clear</a>
	</form>
	<?php if ( ! $rows ) : ?>
		<p class="t5fob-empty"><?php echo $q ? 'Nothing matches this search.' : 'No blocked attempts yet. When a blocked customer tries to order, it shows up here with a code like BLK-1001.'; ?></p>
	<?php endif; ?>
	<div class="t5fob-list">
		<?php
		$labels = array( 'unchecked' => array( 'WhatsApp: not checked', 'warn' ), 'came' => array( 'WhatsApp: came', 'ok' ), 'not_came' => array( 'WhatsApp: did not come', 'bad' ) );
		foreach ( $rows as $r ) :
			$st = $labels[ $r->wa_status ] ?? $labels['unchecked'];
			?>
			<div class="t5fob-item">
				<div class="t5fob-main">
					<div><code><?php echo esc_html( t5fob_attempt_code( $r->id ) ); ?></code> <strong><?php echo esc_html( $r->name ); ?></strong> · <?php echo esc_html( $r->phone ); ?></div>
					<div class="t5fob-muted"><?php echo esc_html( $r->items . ' · ' . html_entity_decode( wp_strip_all_tags( wc_price( $r->total, array( 'decimals' => 0 ) ) ) ) . ' · ' . $r->address ); ?></div>
					<div class="t5fob-muted"><?php echo esc_html( t5fob_when( $r->created_at ) . ' · Matched: ' . strtolower( t5fob_type_label( $r->matched ) ) . ' · ' . t5fob_device_label( $r->ua ) . ' · IP ' . $r->ip ); ?></div>
				</div>
				<div class="t5fob-side">
					<span class="t5fob-badge <?php echo esc_attr( $st[1] ); ?>"><?php echo esc_html( $st[0] ); ?></span>
					<?php if ( $r->order_id ) : ?>
						<a class="button" href="<?php echo esc_url( t5fob_order_url( $r->order_id ) ); ?>">Order #<?php echo (int) $r->order_id; ?></a>
					<?php elseif ( $r->cart ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="t5fob-inline">
							<input type="hidden" name="action" value="t5fob_attempt_order"><input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
							<?php wp_nonce_field( 't5fob_attempt_order' ); ?>
							<button class="button t5fob-btn-green">Create order</button>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="t5fob-inline">
						<input type="hidden" name="action" value="t5fob_attempt_status"><input type="hidden" name="id" value="<?php echo (int) $r->id; ?>">
						<?php wp_nonce_field( 't5fob_attempt_status' ); ?>
						<?php if ( 'came' !== $r->wa_status ) : ?><button class="button" name="status" value="came">Came</button><?php endif; ?>
						<?php if ( 'not_came' !== $r->wa_status ) : ?><button class="button" name="status" value="not_came">Did not come</button><?php endif; ?>
					</form>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
	t5fob_pagination( $total, $paged, $per );
}

function t5fob_tab_rules() {
	global $wpdb;
	$t     = t5fob_table( 'rules' );
	$q     = sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) );
	$type  = sanitize_key( $_GET['type'] ?? '' );
	$per   = 20;
	$paged = max( 1, absint( $_GET['paged'] ?? 1 ) );
	$where = array( '( expires_at IS NULL OR expires_at > %s )' );
	$args  = array( t5fob_now() );
	if ( in_array( $type, array( 'phone', 'device', 'ip' ), true ) ) {
		$where[] = 'type = %s';
		$args[]  = $type;
	}
	if ( '' !== $q ) {
		$where[] = '( value LIKE %s OR value LIKE %s OR reason LIKE %s )';
		$like    = '%' . $wpdb->esc_like( $q ) . '%';
		array_push( $args, $like, '%' . $wpdb->esc_like( t5fob_phone( $q ) ?: $q ) . '%', $like );
	}
	$w     = implode( ' AND ', $where );
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $w", $args ) );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $w ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $args, array( $per, ( $paged - 1 ) * $per ) ) ) );
	?>
	<form class="t5fob-tools" method="get">
		<input type="hidden" name="page" value="t5fob"><input type="hidden" name="tab" value="rules">
		<input type="search" name="q" value="<?php echo esc_attr( $q ); ?>" placeholder="Search phone, IP or reason">
		<span class="t5fob-pills">
			<?php foreach ( array( '' => 'All', 'phone' => 'Phone', 'device' => 'Device', 'ip' => 'IP' ) as $k => $label ) : ?>
				<label><input type="radio" name="type" value="<?php echo esc_attr( $k ); ?>" <?php checked( $type, $k ); ?> onchange="this.form.submit()"><span><?php echo esc_html( $label ); ?></span></label>
			<?php endforeach; ?>
		</span>
		<button class="button t5fob-btn-green">Search</button>
		<a class="button" href="<?php echo esc_url( t5fob_page_url( array( 'tab' => 'rules' ) ) ); ?>">Clear</a>
	</form>

	<details class="t5fob-add">
		<summary class="button t5fob-btn-green">+ Add manually</summary>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="t5fob_add_rule">
			<?php wp_nonce_field( 't5fob_add_rule' ); ?>
			<div class="t5fob-seg">
				<label><input type="radio" name="type" value="phone" checked><span>Phone</span></label>
				<label><input type="radio" name="type" value="ip"><span>IP</span></label>
			</div>
			<input type="text" name="value" required placeholder="01XXXXXXXXX or IP address">
			<div class="t5fob-seg">
				<label><input type="radio" name="days" value="7"><span>7 days</span></label>
				<label><input type="radio" name="days" value="30"><span>30 days</span></label>
				<label><input type="radio" name="days" value="0" checked><span>Permanent</span></label>
			</div>
			<span class="t5fob-muted">An IP block lasts 7 days at most, because mobile IPs are shared.</span>
			<input type="text" name="reason" placeholder="Reason (optional), e.g. real number from WhatsApp, BLK-1023">
			<p><button class="button t5fob-btn-red">Block</button></p>
		</form>
	</details>

	<?php if ( ! $rows ) : ?>
		<p class="t5fob-empty"><?php echo ( $q || $type ) ? 'Nothing matches this search.' : 'Nobody is blocked. Open a fake order and tap "Block customer", or add a number here.'; ?></p>
	<?php endif; ?>
	<div class="t5fob-list">
		<?php foreach ( $rows as $r ) : ?>
			<div class="t5fob-item">
				<div class="t5fob-main">
					<div><strong><?php echo esc_html( t5fob_type_label( $r->type ) ); ?></strong>
						<?php echo ( 'device' === $r->type && $r->order_id ) ? 'of order #' . (int) $r->order_id : '<code>' . esc_html( 'device' === $r->type ? substr( $r->value, 0, 8 ) : $r->value ) . '</code>'; ?></div>
					<div class="t5fob-muted">
						<?php if ( $r->order_id ) : ?>From <a href="<?php echo esc_url( t5fob_order_url( $r->order_id ) ); ?>">order #<?php echo (int) $r->order_id; ?></a><?php else : ?>Added manually<?php endif; ?>
						<?php echo $r->reason ? ' · ' . esc_html( $r->reason ) : ''; ?>
					</div>
					<div class="t5fob-muted">Blocked <?php echo esc_html( t5fob_when( $r->created_at ) ); ?> · <?php echo (int) $r->hits; ?> attempt<?php echo 1 === (int) $r->hits ? '' : 's'; ?> since</div>
				</div>
				<div class="t5fob-side">
					<span class="t5fob-badge <?php echo $r->expires_at ? 'warn' : 'bad'; ?>"><?php echo $r->expires_at ? 'Until ' . esc_html( wp_date( 'j M', strtotime( $r->expires_at . ' UTC' ) ) ) : 'Permanent'; ?></span>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=t5fob_unblock&rule_id=' . (int) $r->id ), 't5fob_unblock' ) ); ?>">Unblock</a>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
	t5fob_pagination( $total, $paged, $per );
}

function t5fob_tab_settings() {
	$s       = t5fob_settings();
	$sf_ours = get_option( 'api_settings_tab_api_key' ) ? 'Leave empty to use the keys from the Steadfast plugin.' : 'Needed for the Steadfast record on the order screen.';
	$sf_set  = fn( $k ) => $s[ $k ] ? 'saved, leave empty to keep' : 'not set';
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="t5fob-settings">
		<input type="hidden" name="action" value="t5fob_save_settings">
		<?php wp_nonce_field( 't5fob_save_settings' ); ?>
		<p><label for="t5fob-wa"><strong>WhatsApp number</strong></label><br>
			<input id="t5fob-wa" type="text" name="wa_number" value="<?php echo esc_attr( $s['wa_number'] ); ?>" placeholder="01XXXXXXXXX"><br>
			<span class="t5fob-muted">Blocked customers are sent to this WhatsApp with their order already typed.</span></p>
		<p><label for="t5fob-wm"><strong>Message to blocked customers</strong></label><br>
			<textarea id="t5fob-wm" name="wa_message" rows="3" class="regular-text" placeholder="Please confirm your order on WhatsApp."><?php echo esc_textarea( $s['wa_message'] ); ?></textarea><br>
			<span class="t5fob-muted">Shown above the Open WhatsApp button. Leave empty for the standard message.</span></p>
		<p><label for="t5fob-k"><strong>Steadfast API key</strong></label><br>
			<input id="t5fob-k" type="password" name="sf_key" placeholder="<?php echo esc_attr( $sf_set( 'sf_key' ) ); ?>" autocomplete="off"></p>
		<p><label for="t5fob-s"><strong>Steadfast secret key</strong></label><br>
			<input id="t5fob-s" type="password" name="sf_secret" placeholder="<?php echo esc_attr( $sf_set( 'sf_secret' ) ); ?>" autocomplete="off"><br>
			<span class="t5fob-muted"><?php echo esc_html( $sf_ours ); ?></span></p>
		<p><label for="t5fob-allow"><strong>Never block these IPs</strong></label><br>
			<textarea id="t5fob-allow" name="allow_ips" rows="3" class="regular-text" placeholder="203.0.113.*"><?php echo esc_textarea( $s['allow_ips'] ); ?></textarea><br>
			<span class="t5fob-muted">Office and team connections, one per line. End with * for a whole range (203.0.113.*). Orders from these are never stopped.</span></p>
			<?php if ( defined( 'T5_TRACKING_VERSION' ) ) : ?>
				<p class="t5fob-muted">Auto verify is handled by Top5Way Tracking: WooCommerce &gt; Top5Way Tracking &gt; Courier.</p>
				<p><button class="button t5fob-btn-green">Save</button></p>
		</form>
				<?php
				return;
			elseif ( ! wc_is_order_status( 'wc-record-verified' ) ) :
				?>
				<p class="t5fob-muted">Auto verify needs a Record Verified order status. Top5Way Tracking adds it.</p>
				<p><button class="button t5fob-btn-green">Save</button></p>
		</form>
				<?php
				return;
			endif;
			?>
			<h2>Auto Record Verified</h2>
		<p><label><input type="checkbox" name="auto_verify" value="1" <?php checked( $s['auto_verify'] ); ?>> <strong>Move good-history orders to Record Verified on their own</strong></label><br>
			<span class="t5fob-muted">Checked on Steadfast right after the order is placed. Orders that do not pass stay as they are for a phone call.</span></p>
		<p><label for="t5fob-r"><strong>Minimum delivery success (%)</strong></label><br>
			<input id="t5fob-r" type="number" name="min_ratio" min="0" max="100" value="<?php echo esc_attr( $s['min_ratio'] ); ?>"></p>
		<p><label for="t5fob-n"><strong>Minimum past parcels</strong></label><br>
			<input id="t5fob-n" type="number" name="min_parcels" min="1" value="<?php echo esc_attr( $s['min_parcels'] ); ?>"></p>
		<p><label for="t5fob-f"><strong>Maximum fraud reports</strong></label><br>
			<input id="t5fob-f" type="number" name="max_reports" min="0" value="<?php echo esc_attr( $s['max_reports'] ); ?>"></p>
		<p><label for="t5fob-t"><strong>Always call orders above this total</strong></label><br>
			<input id="t5fob-t" type="number" name="max_total" min="0" value="<?php echo esc_attr( $s['max_total'] ); ?>"><br>
			<span class="t5fob-muted">0 means no limit.</span></p>
		<p><button class="button t5fob-btn-green">Save</button></p>
	</form>
	<?php
}

function t5fob_admin_guard( $nonce ) {
	check_admin_referer( $nonce );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Not allowed.' );
	}
}

add_action( 'admin_post_t5fob_save_settings', function () {
	t5fob_admin_guard( 't5fob_save_settings' );
	$old = t5fob_settings();
	update_option( 't5fob_settings', array(
		'wa_number' => preg_replace( '/[^\d+]/', '', wp_unslash( $_POST['wa_number'] ?? '' ) ),
		'wa_message' => sanitize_textarea_field( wp_unslash( $_POST['wa_message'] ?? '' ) ),
		'sf_key'    => sanitize_text_field( wp_unslash( $_POST['sf_key'] ?? '' ) ) ?: $old['sf_key'], // never printed back, so empty keeps it
		'sf_secret' => sanitize_text_field( wp_unslash( $_POST['sf_secret'] ?? '' ) ) ?: $old['sf_secret'],
		'allow_ips' => implode( "\n", array_filter( array_map( fn( $l ) => preg_replace( '/[^0-9a-fA-F:.*]/', '', $l ), preg_split( '/[\r\n,]+/', wp_unslash( $_POST['allow_ips'] ?? '' ) ) ) ) ),
		'auto_verify' => empty( $_POST['auto_verify'] ) ? 0 : 1,
		'min_ratio'   => max( 0, min( 100, (int) ( $_POST['min_ratio'] ?? $old['min_ratio'] ) ) ),
		'min_parcels' => max( 1, (int) ( $_POST['min_parcels'] ?? $old['min_parcels'] ) ),
		'max_reports' => max( 0, (int) ( $_POST['max_reports'] ?? $old['max_reports'] ) ),
		'max_total'   => max( 0, (int) ( $_POST['max_total'] ?? $old['max_total'] ) ),
	), false );
	wp_safe_redirect( t5fob_page_url( array( 'tab' => 'settings', 'saved' => 1 ) ) );
	exit;
} );

add_action( 'admin_post_t5fob_add_rule', function () {
	t5fob_admin_guard( 't5fob_add_rule' );
	$type = in_array( $_POST['type'] ?? '', array( 'phone', 'ip' ), true ) ? $_POST['type'] : 'phone';
	$days = in_array( (int) ( $_POST['days'] ?? 0 ), array( 0, 7, 30 ), true ) ? (int) $_POST['days'] : 0;
	if ( 'ip' === $type ) {
		$days = $days ? min( $days, 7 ) : 7;
	}
	t5fob_add_rule( $type, wp_unslash( $_POST['value'] ?? '' ), $days, sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) ) );
	wp_safe_redirect( t5fob_page_url( array( 'tab' => 'rules', 'saved' => 1 ) ) );
	exit;
} );

add_action( 'admin_post_t5fob_attempt_status', function () {
	t5fob_admin_guard( 't5fob_attempt_status' );
	$status = in_array( $_POST['status'] ?? '', array( 'came', 'not_came', 'unchecked' ), true ) ? $_POST['status'] : 'unchecked';
	global $wpdb;
	$wpdb->update( t5fob_table( 'attempts' ), array( 'wa_status' => $status ), array( 'id' => absint( $_POST['id'] ?? 0 ) ) );
	wp_safe_redirect( wp_get_referer() ?: t5fob_page_url() );
	exit;
} );

// The customer came on WhatsApp and is real: make the order they tried to place.
add_action( 'admin_post_t5fob_attempt_order', function () {
	check_admin_referer( 't5fob_attempt_order' );
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( 'Not allowed.' );
	}
	global $wpdb;
	$t  = t5fob_table( 'attempts' );
	$id = absint( $_POST['id'] ?? 0 );
	$r  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $id ) );
	if ( ! $r || $r->order_id || ! $r->cart ) {
		wp_safe_redirect( t5fob_page_url() );
		exit;
	}
	$order = t5fob_order_from_attempt( $r );
	if ( is_wp_error( $order ) ) {
		wp_die( esc_html( $order->get_error_message() ), '', array( 'back_link' => true ) );
	}
	$wpdb->update( $t, array( 'order_id' => $order->get_id(), 'wa_status' => 'came' ), array( 'id' => $id ) );
	wp_safe_redirect( t5fob_order_url( $order->get_id() ) );
	exit;
} );

/** Prices stay as they were at checkout. The address arrives as one line, so check it on the order. */
function t5fob_order_from_attempt( $r ) {
	$cart     = json_decode( $r->cart, true );
	$products = array();
	foreach ( (array) ( $cart['lines'] ?? array() ) as $line ) {
		$product = wc_get_product( $line['id'] );
		if ( ! $product ) {
			return new WP_Error( 't5fob_product', 'A product from this attempt no longer exists, so the order was not created. Create it by hand.' );
		}
		$products[] = array( $product, $line );
	}
	$order = wc_create_order( array( 'created_via' => 't5fob' ) );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	foreach ( $products as list( $product, $line ) ) {
		$order->add_product( $product, $line['qty'], array( 'subtotal' => $line['subtotal'], 'total' => $line['total'] ) );
	}
	if ( ! empty( $cart['shipping']['label'] ) ) {
		$ship = new WC_Order_Item_Shipping();
		$ship->set_method_title( $cart['shipping']['label'] );
		$ship->set_total( $cart['shipping']['cost'] );
		$order->add_item( $ship );
	}
	$order->set_billing_first_name( $r->name );
	$order->set_billing_phone( $r->phone );
	$order->set_billing_address_1( $r->address );
	$order->set_billing_country( WC()->countries->get_base_country() );
	$order->set_shipping_first_name( $r->name );
	$order->set_shipping_address_1( $r->address );
	$order->set_shipping_country( WC()->countries->get_base_country() );
	$gateways = WC()->payment_gateways()->payment_gateways();
	if ( ! empty( $cart['payment'] ) && isset( $gateways[ $cart['payment'] ] ) ) {
		$order->set_payment_method( $gateways[ $cart['payment'] ] );
	}
	if ( $r->device ) {
		$order->update_meta_data( '_t5fob_device', $r->device );
	}
	$order->calculate_totals( false );
	$order->update_status( 'on-hold', 'Created from blocked attempt ' . t5fob_attempt_code( $r->id ) . ' after the customer came on WhatsApp.' );
	return $order;
}

function t5fob_order_url( $order_id ) {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
		return \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( (int) $order_id );
	}
	return admin_url( 'post.php?action=edit&post=' . (int) $order_id );
}
