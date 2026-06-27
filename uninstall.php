<?php
/**
 * Uninstall script for Smart Affiliate Link Cloaker & Auto-Replacer Pro.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

global $wpdb;

// Delete options.
delete_option('salc_redirect_prefix');
delete_option('salc_max_replacements_per_post');

// Delete custom table.
$table_name = $wpdb->prefix . 'smart_aff_clicks';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}"); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

// Delete CPT posts + meta.
$ids = get_posts([
	'post_type'      => 'smart_aff_link',
	'post_status'    => 'any',
	'posts_per_page' => -1,
	'fields'         => 'ids',
]);

if (!empty($ids)) {
	foreach ($ids as $id) {
		wp_delete_post((int) $id, true);
	}
}
