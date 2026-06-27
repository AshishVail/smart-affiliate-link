<?php
if (!defined('ABSPATH')) {
	exit;
}

class SALC_Admin {

	public function init(): void {
		add_action('admin_menu', [$this, 'register_admin_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
		add_filter('manage_smart_aff_link_posts_columns', [$this, 'columns']);
		add_action('manage_smart_aff_link_posts_custom_column', [$this, 'columns_content'], 10, 2);
	}

	public function register_admin_menu(): void {
		add_menu_page(
			__('Smart Affiliate', 'salc-pro'),
			__('Smart Affiliate', 'salc-pro'),
			'manage_options',
			'salc-dashboard',
			[$this, 'render_dashboard'],
			'dashicons-admin-links',
			58
		);

		add_submenu_page(
			'salc-dashboard',
			__('Analytics Dashboard', 'salc-pro'),
			__('Dashboard', 'salc-pro'),
			'manage_options',
			'salc-dashboard',
			[$this, 'render_dashboard']
		);

		add_submenu_page(
			'salc-dashboard',
			__('All Affiliate Links', 'salc-pro'),
			__('All Links', 'salc-pro'),
			'manage_options',
			'edit.php?post_type=smart_aff_link'
		);

		add_submenu_page(
			'salc-dashboard',
			__('Add New Affiliate Link', 'salc-pro'),
			__('Add New Link', 'salc-pro'),
			'manage_options',
			'post-new.php?post_type=smart_aff_link'
		);

		add_submenu_page(
			'salc-dashboard',
			__('Settings', 'salc-pro'),
			__('Settings', 'salc-pro'),
			'manage_options',
			'salc-settings',
			[$this, 'render_settings']
		);
	}

	public function register_settings(): void {
		register_setting('salc_settings_group', 'salc_redirect_prefix', [
			'type'              => 'string',
			'sanitize_callback' => [$this, 'sanitize_prefix'],
			'default'           => 'go',
		]);

		register_setting('salc_settings_group', 'salc_max_replacements_per_post', [
			'type'              => 'integer',
			'sanitize_callback' => [$this, 'sanitize_max_replacements'],
			'default'           => 3,
		]);

		add_settings_section(
			'salc_main_settings',
			__('General Settings', 'salc-pro'),
			'__return_false',
			'salc-settings'
		);

		add_settings_field(
			'salc_redirect_prefix',
			__('Global Redirect Prefix', 'salc-pro'),
			[$this, 'prefix_field_cb'],
			'salc-settings',
			'salc_main_settings'
		);

		add_settings_field(
			'salc_max_replacements_per_post',
			__('Max Auto Replacements Per Post', 'salc-pro'),
			[$this, 'max_replacements_field_cb'],
			'salc-settings',
			'salc_main_settings'
		);
	}

	/**
	 * Sanitize redirect prefix and flush rewrite rules only when value changes.
	 */
	public function sanitize_prefix(string $value): string {
		$old = (string) get_option('salc_redirect_prefix', 'go');

		$sanitized = sanitize_title($value);
		if ('' === $sanitized) {
			$sanitized = 'go';
		}

		if ($old !== $sanitized) {
			flush_rewrite_rules();
		}

		return $sanitized;
	}

	public function sanitize_max_replacements($value): int {
		$value = absint($value);
		return max(1, min(50, $value));
	}

	public function prefix_field_cb(): void {
		$prefix = (string) get_option('salc_redirect_prefix', 'go');
		echo '<input type="text" name="salc_redirect_prefix" value="' . esc_attr($prefix) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__('Examples: go, out, recommend (no slashes).', 'salc-pro') . '</p>';
	}

	public function max_replacements_field_cb(): void {
		$max = (int) get_option('salc_max_replacements_per_post', 3);
		echo '<input type="number" min="1" max="50" name="salc_max_replacements_per_post" value="' . esc_attr((string) $max) . '" />';
	}

	public function enqueue_assets(string $hook): void {
		$allowed_hooks = [
			'toplevel_page_salc-dashboard',
		];

		if (!in_array($hook, $allowed_hooks, true)) {
			return;
		}

		wp_enqueue_style('salc-admin', SALC_PRO_URL . 'assets/css/admin.css', [], SALC_PRO_VERSION);
		wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.3', true);
		wp_enqueue_script('salc-admin', SALC_PRO_URL . 'assets/js/admin.js', ['chart-js'], SALC_PRO_VERSION, true);

		$series = SALC_DB::get_clicks_over_time(30);

		wp_localize_script('salc-admin', 'salcDashboardData', [
			'series' => $series,
		]);
	}

	public function render_dashboard(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'salc-pro'));
		}

		$top_links = SALC_DB::get_top_links(10);
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Smart Affiliate Analytics Dashboard', 'salc-pro'); ?></h1>

			<div class="salc-card">
				<h2><?php esc_html_e('Clicks Over Time (Last 30 Days)', 'salc-pro'); ?></h2>
				<canvas id="salcClicksChart" height="100"></canvas>
			</div>

			<div class="salc-card">
				<h2><?php esc_html_e('Top Performing Links', 'salc-pro'); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e('Link Title', 'salc-pro'); ?></th>
							<th><?php esc_html_e('Total Clicks', 'salc-pro'); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php if (!empty($top_links)) : ?>
						<?php foreach ($top_links as $row) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url(admin_url('post.php?post=' . absint($row['link_id']) . '&action=edit')); ?>">
										<?php echo esc_html($row['post_title']); ?>
									</a>
								</td>
								<td><?php echo esc_html((string) $row['total_clicks']); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php else : ?>
						<tr><td colspan="2"><?php esc_html_e('No click data yet.', 'salc-pro'); ?></td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	public function render_settings(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'salc-pro'));
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Smart Affiliate Settings', 'salc-pro'); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields('salc_settings_group');
				do_settings_sections('salc-settings');
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function columns(array $columns): array {
		$columns['salc_cloaked'] = __('Cloaked URL', 'salc-pro');
		$columns['salc_clicks']  = __('Clicks (Total/Unique)', 'salc-pro');
		return $columns;
	}

	public function columns_content(string $column, int $post_id): void {
		if ('salc_cloaked' === $column) {
			$slug   = (string) get_post_meta($post_id, '_salc_cloaked_slug', true);
			$prefix = (string) get_option('salc_redirect_prefix', 'go');
			if ('' !== $slug) {
				$url = home_url('/' . $prefix . '/' . $slug . '/');
				echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($url) . '</a>';
			} else {
				echo '—';
			}
		}

		if ('salc_clicks' === $column) {
			$total  = SALC_DB::get_total_clicks($post_id);
			$unique = SALC_DB::get_unique_clicks($post_id);
			echo esc_html($total . ' / ' . $unique);
		}
	}
}
