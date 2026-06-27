<?php
if (!defined('ABSPATH')) {
	exit;
}

class SALC_CPT {

	public static function init(): void {
		add_action('init', [__CLASS__, 'register_cpt']);
		add_action('add_meta_boxes', [__CLASS__, 'add_meta_boxes']);
		add_action('save_post_smart_aff_link', [__CLASS__, 'save_meta'], 10, 2);

		// Admin notices for duplicate slug validation feedback.
		add_action('admin_notices', [__CLASS__, 'maybe_show_admin_notice']);
	}

	public static function register_cpt(): void {
		$labels = [
			'name'               => __('Affiliate Links', 'salc-pro'),
			'singular_name'      => __('Affiliate Link', 'salc-pro'),
			'menu_name'          => __('Affiliate Links', 'salc-pro'),
			'add_new'            => __('Add New', 'salc-pro'),
			'add_new_item'       => __('Add New Affiliate Link', 'salc-pro'),
			'edit_item'          => __('Edit Affiliate Link', 'salc-pro'),
			'new_item'           => __('New Affiliate Link', 'salc-pro'),
			'view_item'          => __('View Affiliate Link', 'salc-pro'),
			'search_items'       => __('Search Affiliate Links', 'salc-pro'),
			'not_found'          => __('No links found.', 'salc-pro'),
			'not_found_in_trash' => __('No links found in Trash.', 'salc-pro'),
		];

		register_post_type('smart_aff_link', [
			'labels'          => $labels,
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false, // shown under custom top-level menu
			'show_in_rest'    => true,  // Gutenberg compatibility
			'supports'        => ['title'],
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'menu_icon'       => 'dashicons-admin-links',
		]);
	}

	public static function add_meta_boxes(): void {
		add_meta_box(
			'salc_aff_link_meta',
			__('Affiliate Link Details', 'salc-pro'),
			[__CLASS__, 'render_meta_box'],
			'smart_aff_link',
			'normal',
			'high'
		);
	}

	public static function render_meta_box(WP_Post $post): void {
		wp_nonce_field('salc_save_aff_link_meta', 'salc_aff_link_meta_nonce');

		$target_url   = (string) get_post_meta($post->ID, '_salc_target_url', true);
		$cloaked_slug = (string) get_post_meta($post->ID, '_salc_cloaked_slug', true);
		$keywords     = (string) get_post_meta($post->ID, '_salc_keywords', true);
		$new_tab      = (string) get_post_meta($post->ID, '_salc_new_tab', true);
		$nofollow     = (string) get_post_meta($post->ID, '_salc_nofollow', true);
		$sponsored    = (string) get_post_meta($post->ID, '_salc_sponsored', true);
		?>
		<p>
			<label for="salc_target_url"><strong><?php esc_html_e('Target URL', 'salc-pro'); ?></strong></label><br>
			<input type="url" id="salc_target_url" name="salc_target_url" class="widefat" value="<?php echo esc_attr($target_url); ?>" required>
		</p>
		<p>
			<label for="salc_cloaked_slug"><strong><?php esc_html_e('Cloaked Slug', 'salc-pro'); ?></strong></label><br>
			<input type="text" id="salc_cloaked_slug" name="salc_cloaked_slug" class="widefat" value="<?php echo esc_attr($cloaked_slug); ?>" placeholder="product-name" required>
			<?php if ('' !== $cloaked_slug) : ?>
				<?php $prefix = sanitize_title((string) get_option('salc_redirect_prefix', 'go')); ?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: Example cloaked URL. */
							__('Cloaked URL preview: %s', 'salc-pro'),
							home_url('/' . $prefix . '/' . $cloaked_slug . '/')
						)
					);
					?>
				</p>
			<?php endif; ?>
		</p>
		<p>
			<label for="salc_keywords"><strong><?php esc_html_e('Auto-Replace Keywords (comma-separated)', 'salc-pro'); ?></strong></label><br>
			<input type="text" id="salc_keywords" name="salc_keywords" class="widefat" value="<?php echo esc_attr($keywords); ?>" placeholder="best hosting,hosting deal,cheap hosting">
		</p>
		<p>
			<label>
				<input type="checkbox" name="salc_new_tab" value="1" <?php checked($new_tab, '1'); ?>>
				<?php esc_html_e('Open in New Tab', 'salc-pro'); ?>
			</label>
		</p>
		<p>
			<label>
				<input type="checkbox" name="salc_nofollow" value="1" <?php checked($nofollow, '1'); ?>>
				<?php esc_html_e('Add rel="nofollow"', 'salc-pro'); ?>
			</label><br>
			<label>
				<input type="checkbox" name="salc_sponsored" value="1" <?php checked($sponsored, '1'); ?>>
				<?php esc_html_e('Add rel="sponsored"', 'salc-pro'); ?>
			</label>
		</p>
		<?php
	}

	public static function save_meta(int $post_id, WP_Post $post): void {
		if (!isset($_POST['salc_aff_link_meta_nonce'])) {
			return;
		}

		$nonce = sanitize_text_field(wp_unslash($_POST['salc_aff_link_meta_nonce']));
		if (!wp_verify_nonce($nonce, 'salc_save_aff_link_meta')) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if ('smart_aff_link' !== $post->post_type) {
			return;
		}

		if (!current_user_can('manage_options')) {
			return;
		}

		$target_url = isset($_POST['salc_target_url']) ? esc_url_raw(wp_unslash($_POST['salc_target_url'])) : '';
		$slug       = isset($_POST['salc_cloaked_slug']) ? sanitize_title(wp_unslash($_POST['salc_cloaked_slug'])) : '';
		$keywords   = isset($_POST['salc_keywords']) ? sanitize_text_field(wp_unslash($_POST['salc_keywords'])) : '';
		$new_tab    = isset($_POST['salc_new_tab']) ? '1' : '0';
		$nofollow   = isset($_POST['salc_nofollow']) ? '1' : '0';
		$sponsored  = isset($_POST['salc_sponsored']) ? '1' : '0';

		// Validate URL presence.
		if ('' === $target_url) {
			self::set_duplicate_slug_notice($post_id, __('Target URL is required.', 'salc-pro'));
			return;
		}

		// Validate slug presence.
		if ('' === $slug) {
			self::set_duplicate_slug_notice($post_id, __('Cloaked slug is required and must be URL-safe.', 'salc-pro'));
			return;
		}

		// Duplicate slug protection (core requirement).
		if (self::slug_exists_elsewhere($slug, $post_id)) {
			self::set_duplicate_slug_notice(
				$post_id,
				sprintf(
					/* translators: %s: Cloaked slug */
					__('The cloaked slug "%s" is already used by another affiliate link. Please use a unique slug.', 'salc-pro'),
					$slug
				)
			);

			// Do not persist invalid slug; keep previous meta untouched for slug.
			// Still allow other fields to update safely.
			update_post_meta($post_id, '_salc_target_url', $target_url);
			update_post_meta($post_id, '_salc_keywords', $keywords);
			update_post_meta($post_id, '_salc_new_tab', $new_tab);
			update_post_meta($post_id, '_salc_nofollow', $nofollow);
			update_post_meta($post_id, '_salc_sponsored', $sponsored);
			return;
		}

		// All valid, persist all meta.
		update_post_meta($post_id, '_salc_target_url', $target_url);
		update_post_meta($post_id, '_salc_cloaked_slug', $slug);
		update_post_meta($post_id, '_salc_keywords', $keywords);
		update_post_meta($post_id, '_salc_new_tab', $new_tab);
		update_post_meta($post_id, '_salc_nofollow', $nofollow);
		update_post_meta($post_id, '_salc_sponsored', $sponsored);

		// Clear any old notice for this post.
		delete_transient(self::notice_transient_key($post_id));
	}

	private static function slug_exists_elsewhere(string $slug, int $current_post_id): bool {
		$q = new WP_Query([
			'post_type'      => 'smart_aff_link',
			'post_status'    => ['publish', 'draft', 'pending', 'private', 'future'],
			'posts_per_page' => 1,
			'post__not_in'   => [$current_post_id],
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => '_salc_cloaked_slug',
					'value' => $slug,
				],
			],
			'no_found_rows'  => true,
		]);

		return !empty($q->posts);
	}

	private static function set_duplicate_slug_notice(int $post_id, string $message): void {
		set_transient(
			self::notice_transient_key($post_id),
			sanitize_text_field($message),
			60 // short-lived notice
		);
	}

	private static function notice_transient_key(int $post_id): string {
		return 'salc_admin_notice_' . $post_id;
	}

	public static function maybe_show_admin_notice(): void {
		if (!is_admin()) {
			return;
		}

		if (!current_user_can('manage_options')) {
			return;
		}

		$screen = get_current_screen();
		if (!$screen || 'smart_aff_link' !== $screen->post_type) {
			return;
		}

		$post_id = 0;

		if (isset($_GET['post'])) {
			$post_id = absint(wp_unslash($_GET['post']));
		} elseif (isset($_POST['post_ID'])) {
			$post_id = absint(wp_unslash($_POST['post_ID']));
		}

		if ($post_id <= 0) {
			return;
		}

		$key     = self::notice_transient_key($post_id);
		$message = get_transient($key);

		if (!$message) {
			return;
		}

		delete_transient($key);

		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html((string) $message) . '</p></div>';
	}
}
