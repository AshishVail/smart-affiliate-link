<?php
if (!defined('ABSPATH')) {
	exit;
}

class SALC_Frontend {

	public function init(): void {
		add_action('init', [__CLASS__, 'add_rewrite_rules']);
		add_filter('query_vars', [$this, 'register_query_vars']);
		add_action('template_redirect', [$this, 'handle_redirect'], 0);

		// Apply for normal content + many builders that call the_content.
		add_filter('the_content', [$this, 'auto_replace_keywords'], 20);
	}

	public static function add_rewrite_rules(): void {
		$prefix = get_option('salc_redirect_prefix', 'go');
		$prefix = sanitize_title($prefix);

		add_rewrite_rule(
			'^' . preg_quote($prefix, '/') . '/([^/]+)/?$',
			'index.php?salc_slug=$matches[1]',
			'top'
		);
	}

	public function register_query_vars(array $vars): array {
		$vars[] = 'salc_slug';
		return $vars;
	}

	public function handle_redirect(): void {
		$slug = get_query_var('salc_slug');
		if (empty($slug)) {
			return;
		}

		$slug = sanitize_title($slug);

		$link_id = $this->get_link_id_by_slug($slug);
		if (!$link_id) {
			global $wp_query;
			$wp_query->set_404();
			status_header(404);
			nocache_headers();
			return;
		}

		$target_url = get_post_meta($link_id, '_salc_target_url', true);
		if (empty($target_url) || !filter_var($target_url, FILTER_VALIDATE_URL)) {
			wp_die(esc_html__('Invalid target URL.', 'salc-pro'));
		}

		SALC_DB::log_click((int) $link_id);

		wp_redirect(esc_url_raw($target_url), 302);
		exit;
	}

	private function get_link_id_by_slug(string $slug): int {
		$q = new WP_Query([
			'post_type'      => 'smart_aff_link',
			'post_status'    => ['publish', 'draft'],
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => '_salc_cloaked_slug',
					'value' => $slug,
				],
			],
			'no_found_rows'  => true,
		]);

		return !empty($q->posts[0]) ? (int) $q->posts[0] : 0;
	}

	public function auto_replace_keywords(string $content): string {
		if (is_admin() || empty($content)) {
			return $content;
		}

		if (!is_singular()) {
			return $content;
		}

		$max_replacements = (int) get_option('salc_max_replacements_per_post', 3);
		$max_replacements = max(1, min(50, $max_replacements));

		$links = get_posts([
			'post_type'      => 'smart_aff_link',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		]);

		if (empty($links)) {
			return $content;
		}

		$prefix = sanitize_title(get_option('salc_redirect_prefix', 'go'));
		$total_replaced = 0;

		foreach ($links as $link_id) {
			if ($total_replaced >= $max_replacements) {
				break;
			}

			$slug      = get_post_meta($link_id, '_salc_cloaked_slug', true);
			$keywords  = get_post_meta($link_id, '_salc_keywords', true);
			$new_tab   = get_post_meta($link_id, '_salc_new_tab', true) === '1';
			$nofollow  = get_post_meta($link_id, '_salc_nofollow', true) === '1';
			$sponsored = get_post_meta($link_id, '_salc_sponsored', true) === '1';

			if (empty($slug) || empty($keywords)) {
				continue;
			}

			$link_url = home_url('/' . $prefix . '/' . sanitize_title($slug) . '/');

			$rel_parts = [];
			if ($nofollow) {
				$rel_parts[] = 'nofollow';
			}
			if ($sponsored) {
				$rel_parts[] = 'sponsored';
			}
			$rel = implode(' ', array_unique($rel_parts));

			$target_attr = $new_tab ? ' target="_blank" rel="noopener noreferrer' . (!empty($rel) ? ' ' . esc_attr($rel) : '') . '"' : (!empty($rel) ? ' rel="' . esc_attr($rel) . '"' : '');

			$keyword_list = array_filter(array_map('trim', explode(',', (string) $keywords)));

			foreach ($keyword_list as $keyword) {
				if ($total_replaced >= $max_replacements) {
					break 2;
				}

				$keyword_escaped = preg_quote($keyword, '/');
				$pattern = '/\b(' . $keyword_escaped . ')\b(?![^<]*>|[^<>]*<\/a>)/i';

				$replaced_this_round = 0;

				$content = preg_replace_callback(
					$pattern,
					function ($matches) use ($link_url, $target_attr, &$replaced_this_round) {
						if ($replaced_this_round > 0) {
							return $matches[0];
						}
						$replaced_this_round++;
						return '<a href="' . esc_url($link_url) . '"' . $target_attr . '>' . esc_html($matches[1]) . '</a>';
					},
					$content,
					1
				);

				if ($replaced_this_round > 0) {
					$total_replaced++;
				}
			}
		}

		return $content;
	}
}
