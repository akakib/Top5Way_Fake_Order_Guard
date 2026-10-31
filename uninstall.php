<?php
/**
 * Deleting the plugin removes its tables, settings and cached Steadfast records.
 * (Deactivating keeps everything.)
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}t5fob_rules, {$wpdb->prefix}t5fob_attempts, {$wpdb->prefix}t5fob_recent" ); // phpcs:ignore
delete_option( 't5fob_settings' );
delete_option( 't5fob_db_version' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_t5fob\\_%' OR option_name LIKE '\\_transient\\_timeout\\_t5fob\\_%'" ); // phpcs:ignore
wp_clear_scheduled_hook( 't5fob_cleanup' );
