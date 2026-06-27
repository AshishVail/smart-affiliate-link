<?php
if (!defined('ABSPATH')) {
	exit;
}

class SALC_DB {

	public static function init(): void {
		// Reserved for migrations/cron if needed.
	}

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'smart_aff_clicks';
	}

	public static function create_clicks_table(): void {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			link_id BIGINT UNSIGNED NOT NULL,
			click_time DATETIME NOT NULL,
			referrer_url TEXT NULL,
			user_agent TEXT NULL,
			ip_hash VARCHAR(64) NOT NULL,
			PRIMARY KEY (id),
			KEY link_id (link_id),
			KEY click_time (click_time)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);
	}

	public static function log_click(int $link_id): void {
		global $wpdb;

		$table = self::table_name();

		$referrer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
		$ua       = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
		$ip       = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
		$ip_hash  = hash('sha256', $ip . wp_salt('auth'));

		$wpdb->insert(
			$table,
			[
				'link_id'      => $link_id,
				'click_time'   => current_time('mysql'),
				'referrer_url' => $referrer,
				'user_agent'   => $ua,
				'ip_hash'      => $ip_hash,
			],
			['%d', '%s', '%s', '%s', '%s']
		);
	}

	public static function get_total_clicks(int $link_id): int {
		global $wpdb;
		$table = self::table_name();

		$count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE link_id = %d", $link_id));
		return (int) $count;
	}

	public static function get_unique_clicks(int $link_id): int {
		global $wpdb;
		$table = self::table_name();

		$count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT ip_hash) FROM {$table} WHERE link_id = %d", $link_id));
		return (int) $count;
	}

	public static function get_clicks_over_time(int $days = 30): array {
		global $wpdb;
		$table = self::table_name();

		$sql = $wpdb->prepare(
			"SELECT DATE(click_time) as day, COUNT(*) as clicks
			 FROM {$table}
			 WHERE click_time >= DATE_SUB(NOW(), INTERVAL %d DAY)
			 GROUP BY DATE(click_time)
			 ORDER BY day ASC",
			$days
		);

		return $wpdb->get_results($sql, ARRAY_A) ?: [];
	}

	public static function get_top_links(int $limit = 10): array {
		global $wpdb;

		$table = self::table_name();
		$posts = $wpdb->posts;

		$sql = $wpdb->prepare(
			"SELECT c.link_id, p.post_title, COUNT(*) as total_clicks
			 FROM {$table} c
			 INNER JOIN {$posts} p ON p.ID = c.link_id
			 WHERE p.post_type = 'smart_aff_link' AND p.post_status IN ('publish','draft')
			 GROUP BY c.link_id, p.post_title
			 ORDER BY total_clicks DESC
			 LIMIT %d",
			$limit
		);

		return $wpdb->get_results($sql, ARRAY_A) ?: [];
	}
}
