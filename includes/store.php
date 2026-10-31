<?php
/**
 * Tables, settings and the small helpers every other file uses.
 */

defined( 'ABSPATH' ) || exit;

function t5fob_table( $name ) {
	global $wpdb;
	return $wpdb->prefix . 't5fob_' . $name;
}

function t5fob_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$c = $wpdb->get_charset_collate();

	// One row per blocked phone / device / IP. expires_at NULL = permanent.
	dbDelta( 'CREATE TABLE ' . t5fob_table( 'rules' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		type varchar(10) NOT NULL,
		value varchar(64) NOT NULL,
		reason varchar(255) NOT NULL DEFAULT '',
		order_id bigint(20) unsigned NOT NULL DEFAULT 0,
		hits int(10) unsigned NOT NULL DEFAULT 0,
		created_by bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		expires_at datetime DEFAULT NULL,
		last_hit_at datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY type_value (type,value)
	) $c;" );

	// One row per stopped checkout.
	dbDelta( 'CREATE TABLE ' . t5fob_table( 'attempts' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		rule_id bigint(20) unsigned NOT NULL DEFAULT 0,
		matched varchar(10) NOT NULL DEFAULT '',
		name varchar(191) NOT NULL DEFAULT '',
		phone varchar(32) NOT NULL DEFAULT '',
		address varchar(255) NOT NULL DEFAULT '',
		items text NOT NULL,
		total decimal(12,2) NOT NULL DEFAULT 0,
		ip varchar(64) NOT NULL DEFAULT '',
		device varchar(64) NOT NULL DEFAULT '',
		ua varchar(255) NOT NULL DEFAULT '',
		wa_status varchar(12) NOT NULL DEFAULT 'unchecked',
		cart text NULL,
		order_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY phone (phone),
		KEY created_at (created_at)
	) $c;" );

	// Orders of the last two days, so the repeat check is one indexed query at checkout.
	dbDelta( 'CREATE TABLE ' . t5fob_table( 'recent' ) . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		order_id bigint(20) unsigned NOT NULL DEFAULT 0,
		phone varchar(32) NOT NULL DEFAULT '',
		device varchar(64) NOT NULL DEFAULT '',
		ip varchar(64) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY phone (phone),
		KEY device (device),
		KEY ip (ip),
		KEY created_at (created_at)
	) $c;" );

	update_option( 't5fob_db_version', T5FOB_DB_VERSION );
}

function t5fob_settings() {
	return wp_parse_args( get_option( 't5fob_settings', array() ), array(
		'wa_number'  => '',
		'wa_message' => '',
		'sf_key'     => '',
		'sf_secret'  => '',
		'allow_ips'   => '', // office / team: never blocked, never sent to WhatsApp
		'behind_proxy' => 0, // the site sits behind Cloudflare or another proxy
		'repeat_on'    => 1,
		// One phone: orders at once, then hours until one more. The table keeps 48 hours, so at once x hours stays within that.
		'repeat_good_c' => 3,
		'repeat_good_h' => 6,
		'repeat_none_c' => 2,
		'repeat_none_h' => 12,
		'repeat_bad_c'  => 1,
		'repeat_bad_h'  => 24,
		// Device and IP only catch a burst, in this many minutes.
		'repeat_mins'   => 3,
		'repeat_device' => 2, // different numbers on one device
		'repeat_ip'     => 3, // orders from one IP
		'auto_verify' => 0,
		'min_ratio'   => 60,
		'min_parcels' => 2,
		'max_reports' => 2,
		'max_total'   => 0, // bigger orders always get a call; 0 = no limit
	) );
}

/**
 * Allowed IPs from Settings, one per line: an exact IP, or a start ending in * ("203.0.113.*").
 * A visitor on one of them is never stopped, and these IPs cannot be added to the block list.
 */
function t5fob_allowed_ip( $ip ) {
	$ip = strtolower( trim( (string) $ip ) );
	if ( '' === $ip ) {
		return false;
	}
	foreach ( preg_split( '/[\r\n,]+/', (string) t5fob_settings()['allow_ips'] ) as $line ) {
		$line = strtolower( trim( $line ) );
		if ( '' === $line ) {
			continue;
		}
		if ( '*' === substr( $line, -1 ) ? 0 === strpos( $ip, rtrim( $line, '*' ) ) : $ip === $line ) {
			return true;
		}
	}
	return false;
}

/** BD mobile number: Bangla digits to English, +88 / 88 / 0088 removed. */
function t5fob_phone( $raw ) {
	$raw = strtr( (string) $raw, array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' ) );
	$d   = preg_replace( '/\D/', '', $raw );
	if ( strlen( $d ) === 15 && 0 === strpos( $d, '0088' ) ) {
		$d = substr( $d, 4 );
	} elseif ( strlen( $d ) === 13 && 0 === strpos( $d, '88' ) ) {
		$d = substr( $d, 2 );
	}
	// The same mobile number with its leading 0 left out, or a 00 in front: 1700000000, 881700000000, 001700000000.
	return preg_match( '/^(?:00)?(?:88)?0?(1\d{9})$/', $d, $m ) ? '0' . $m[1] : $d;
}

function t5fob_clean_device( $v ) {
	return preg_match( '/^[a-f0-9]{32}$/', (string) $v ) ? (string) $v : '';
}

function t5fob_clean_value( $type, $value ) {
	if ( 'phone' === $type ) {
		return t5fob_phone( $value );
	}
	if ( 'device' === $type ) {
		return t5fob_clean_device( $value );
	}
	if ( 'ip' === $type ) {
		$value = trim( (string) $value );
		return filter_var( $value, FILTER_VALIDATE_IP ) ? strtolower( $value ) : '';
	}
	return '';
}

function t5fob_now() {
	return current_time( 'mysql', true );
}

/** Active rule for any of the given values; phone wins over device, device over IP. */
function t5fob_find_rule( $phone, $device, $ip ) {
	global $wpdb;
	$t = t5fob_table( 'rules' );
	return $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM $t
		 WHERE ( expires_at IS NULL OR expires_at > %s )
		   AND ( ( type = 'phone' AND value = %s ) OR ( type = 'device' AND value = %s ) OR ( type = 'ip' AND value = %s ) )
		 ORDER BY FIELD( type, 'phone', 'device', 'ip' ) LIMIT 1",
		t5fob_now(), $phone ?: '-', $device ?: '-', $ip ?: '-'
	) );
}

/** Active rules for one value set, keyed by type (used on the order screen). */
function t5fob_active_rules( $phone, $device, $ip ) {
	global $wpdb;
	$t    = t5fob_table( 'rules' );
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM $t
		 WHERE ( expires_at IS NULL OR expires_at > %s )
		   AND ( ( type = 'phone' AND value = %s ) OR ( type = 'device' AND value = %s ) OR ( type = 'ip' AND value = %s ) )",
		t5fob_now(), $phone ?: '-', $device ?: '-', $ip ?: '-'
	) );
	$out = array();
	foreach ( $rows as $r ) {
		$out[ $r->type ] = $r;
	}
	return $out;
}

/** Add a rule, or refresh the active one for the same value. $days 0 = permanent. */
function t5fob_add_rule( $type, $value, $days, $reason = '', $order_id = 0 ) {
	global $wpdb;
	$value = t5fob_clean_value( $type, $value );
	if ( ! $value || ( 'ip' === $type && t5fob_allowed_ip( $value ) ) ) {
		return false;
	}
	$t       = t5fob_table( 'rules' );
	$expires = $days ? gmdate( 'Y-m-d H:i:s', time() + (int) $days * DAY_IN_SECONDS ) : null;
	$current = $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM $t WHERE type = %s AND value = %s AND ( expires_at IS NULL OR expires_at > %s ) LIMIT 1",
		$type, $value, t5fob_now()
	) );
	$data = array( 'reason' => $reason, 'expires_at' => $expires, 'order_id' => (int) $order_id );
	if ( $current ) {
		$wpdb->update( $t, $data, array( 'id' => $current ) );
		return (int) $current;
	}
	$wpdb->insert( $t, $data + array(
		'type'       => $type,
		'value'      => $value,
		'created_by' => get_current_user_id(),
		'created_at' => t5fob_now(),
	) );
	return (int) $wpdb->insert_id;
}

const T5FOB_ATTEMPT_LIMIT = 10; // saved attempts per IP per hour

/** Save one stopped checkout and count the hit on its rule. Returns the attempt code (BLK-1xxx). */
function t5fob_log_attempt( $rule, $name, $phone, $address, array $items, $total, $ip, $device, array $cart = array() ) {
	global $wpdb;
	// A script repeating checkouts must not fill the table: past the limit it is still blocked, but not saved again.
	$limit_key = 't5fob_lim_' . md5( $ip );
	$seen      = get_transient( $limit_key );
	if ( is_array( $seen ) && $seen['n'] >= T5FOB_ATTEMPT_LIMIT ) {
		return t5fob_attempt_code( $seen['id'] );
	}
	$wpdb->query( $wpdb->prepare(
		'UPDATE ' . t5fob_table( 'rules' ) . ' SET hits = hits + 1, last_hit_at = %s WHERE id = %d',
		t5fob_now(), $rule->id
	) );
	$wpdb->insert( t5fob_table( 'attempts' ), array(
		'rule_id'    => $rule->id,
		'matched'    => $rule->type,
		'name'       => mb_substr( $name, 0, 190 ),
		'phone'      => t5fob_phone( $phone ) ?: (string) $phone,
		'address'    => mb_substr( $address, 0, 250 ),
		'items'      => implode( ', ', $items ),
		'total'      => (float) $total,
		'ip'         => $ip,
		'device'     => $device,
		'ua'         => mb_substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 250 ),
		'cart'       => $cart ? wp_json_encode( $cart ) : null,
		'created_at' => t5fob_now(),
	) );
	$id = (int) $wpdb->insert_id;
	set_transient( $limit_key, array( 'n' => ( $seen['n'] ?? 0 ) + 1, 'id' => $id ), HOUR_IN_SECONDS );
	return t5fob_attempt_code( $id );
}

/** Daily tidy-up: attempts older than 180 days and rules that ended over 90 days ago. */
add_action( 't5fob_cleanup', function () {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . t5fob_table( 'attempts' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS ) ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . t5fob_table( 'recent' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ) ) );
	$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . t5fob_table( 'rules' ) . ' WHERE expires_at IS NOT NULL AND expires_at < %s', gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) );
} );

/** The same phone in the ways it may have been typed on older orders (017..., 88017..., +88017...). */
function t5fob_phone_variants( $phone ) {
	$p = t5fob_phone( $phone );
	return $p ? array_values( array_unique( array( $p, '88' . $p, '+88' . $p, (string) $phone ) ) ) : array();
}

function t5fob_attempt_code( $id ) {
	return 'BLK-' . ( 1000 + (int) $id );
}

/** "BLK-1023" or "1023" -> attempt id, else 0. */
function t5fob_code_to_id( $q ) {
	if ( preg_match( '/^(?:BLK-?)?(\d{4,})$/i', trim( $q ), $m ) ) {
		return max( 0, (int) $m[1] - 1000 );
	}
	return 0;
}

function t5fob_type_label( $type ) {
	$l = array( 'phone' => 'Phone', 'device' => 'Device', 'ip' => 'IP', 'repeat' => 'Repeat order' );
	return $l[ $type ] ?? $type;
}

/** Steadfast delivery record (score endpoint), cached a day. Null when not available. */
function t5fob_steadfast( $phone ) {
	$phone = t5fob_phone( $phone );
	if ( ! preg_match( '/^01\d{9}$/', $phone ) ) {
		return null;
	}
	$cache = get_transient( 't5fob_sf2_' . $phone );
	if ( is_array( $cache ) ) {
		return $cache;
	}
	if ( 'fail' === $cache ) {
		return null; // Steadfast did not answer a moment ago: do not make the order screen wait again
	}
	$s      = t5fob_settings();
	$key    = $s['sf_key'] ?: trim( (string) get_option( 'api_settings_tab_api_key', '' ) );
	$secret = $s['sf_secret'] ?: trim( (string) get_option( 'api_settings_tab_api_secret_key', '' ) );
	if ( ! $key || ! $secret ) {
		return null;
	}
	$res = wp_remote_get( 'https://portal.packzy.com/api/v1/fraud_check/score/' . $phone, array(
		'timeout' => 5,
		'headers' => array( 'api-key' => $key, 'secret-key' => $secret, 'content-type' => 'application/json' ),
	) );
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		// 429 = the account's daily search limit is used up: do not ask again for an hour.
		set_transient( 't5fob_sf2_' . $phone, 'fail', 429 === wp_remote_retrieve_response_code( $res ) ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
		return null;
	}
	// Steadfast sometimes sends exact counts and sometimes only a ratio plus a volume range ("5", "100+").
	$b   = json_decode( wp_remote_retrieve_body( $res ), true );
	$out = array(
		'delivered' => isset( $b['delivered_count'] ) ? (int) $b['delivered_count'] : null,
		'cancelled' => isset( $b['cancelled_count'] ) ? (int) $b['cancelled_count'] : null,
		'ratio'     => isset( $b['delivery_ratio'] ) ? (float) $b['delivery_ratio'] : null,
		'volume'    => (string) ( $b['volume_range'] ?? '' ),
		'reports'   => (int) ( $b['total_reports'] ?? 0 ),
	);
	set_transient( 't5fob_sf2_' . $phone, $out, DAY_IN_SECONDS );
	return $out;
}

/** "Samsung SM-A346E · Facebook app" from a user agent. Model only, many people share a model. */
function t5fob_device_label( $ua ) {
	$ua = (string) $ua;
	if ( '' === $ua ) {
		return 'Unknown device';
	}
	if ( preg_match( '/; ([^;)]+?) Build\//', $ua, $m ) || preg_match( '/Android [\d.]+; ([^;)]+)\)/', $ua, $m ) ) {
		$model  = trim( $m[1] );
		$brands = array( '/^SM-/' => 'Samsung', '/^(CPH|PH[A-Z]{2})\d/' => 'OPPO', '/^RMX/' => 'Realme', '/^V2\d{3}/' => 'vivo', '/^(M2|2\d{4}|Redmi|POCO|Mi )/i' => 'Xiaomi', '/^(TECNO|Infinix|itel)/i' => '', '/^(Pixel)/' => 'Google', '/^(moto)/i' => 'Motorola', '/^(Nokia)/i' => '' );
		foreach ( $brands as $re => $brand ) {
			if ( preg_match( $re, $model ) ) {
				$model = trim( $brand . ' ' . $model );
				break;
			}
		}
		if ( 'K' === $model ) {
			$model = 'Android phone';
		}
	} elseif ( false !== strpos( $ua, 'iPhone' ) ) {
		$model = 'iPhone';
	} elseif ( false !== strpos( $ua, 'iPad' ) ) {
		$model = 'iPad';
	} elseif ( false !== strpos( $ua, 'Windows' ) ) {
		$model = 'Windows PC';
	} elseif ( false !== strpos( $ua, 'Macintosh' ) ) {
		$model = 'Mac';
	} else {
		$model = 'Other device';
	}
	if ( preg_match( '/FB_IAB|FBAN|FBAV/', $ua ) ) {
		$app = 'Facebook app';
	} elseif ( false !== strpos( $ua, 'Instagram' ) ) {
		$app = 'Instagram app';
	} elseif ( false !== strpos( $ua, 'SamsungBrowser' ) ) {
		$app = 'Samsung Internet';
	} elseif ( preg_match( '/EdgA?\//', $ua ) ) {
		$app = 'Edge';
	} elseif ( preg_match( '/Chrome\/|CriOS/', $ua ) ) {
		$app = 'Chrome';
	} elseif ( false !== strpos( $ua, 'Safari' ) ) {
		$app = 'Safari';
	} else {
		$app = 'browser';
	}
	return $model . ' · ' . $app;
}
