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

		$target_url = (string) get_post_meta($link_id, '_salc_target_url', true);
		$target_url = esc_url_raw($target_url);

		// Hard validation of destination.
		if (empty($target_url) || !wp_http_validate_url($target_url)) {
			wp_die(esc_html__('Invalid target URL.', 'salc-pro'));
		}

		// Track click before redirect.
		SALC_DB::log_click((int) $link_id);

		/**
		 * Prefer safe redirect. If target host is external, whitelist it for this redirect.
		 * This preserves affiliate redirect behavior while staying hardened.
		 */
		$host = wp_parse_url($target_url, PHP_URL_HOST);
		if ($host) {
			add_filter(
				'allowed_redirect_hosts',
				static function (array $hosts) use ($host): array {
					$hosts[] = $host;
					return array_unique($hosts);
				}
			);
		}

		wp_safe_redirect($target_url, 302, 'SALC-Pro');
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
		if (is_admin() || empty($content) || !is_singular()) {
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

		$prefix = sanitize_title((string) get_option('salc_redirect_prefix', 'go'));

		$entries = [];
		foreach ($links as $link_id) {
			$slug      = (string) get_post_meta($link_id, '_salc_cloaked_slug', true);
			$keywords  = (string) get_post_meta($link_id, '_salc_keywords', true);
			$new_tab   = get_post_meta($link_id, '_salc_new_tab', true) === '1';
			$nofollow  = get_post_meta($link_id, '_salc_nofollow', true) === '1';
			$sponsored = get_post_meta($link_id, '_salc_sponsored', true) === '1';

			if ('' === $slug || '' === $keywords) {
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
			if ($new_tab) {
				$rel_parts[] = 'noopener';
				$rel_parts[] = 'noreferrer';
			}

			$keyword_list = array_values(array_filter(array_map('trim', explode(',', $keywords))));
			if (empty($keyword_list)) {
				continue;
			}

			$entries[] = [
				'url'      => $link_url,
				'new_tab'  => $new_tab,
				'rel'      => implode(' ', array_unique($rel_parts)),
				'keywords' => $keyword_list,
			];
		}

		if (empty($entries)) {
			return $content;
		}

		// Use HTML API when available (WP 6.2+), fallback to safe-ish segmentation strategy.
		if (class_exists('WP_HTML_Tag_Processor')) {
			return $this->replace_keywords_with_html_api($content, $entries, $max_replacements);
		}

		return $this->replace_keywords_with_fallback($content, $entries, $max_replacements);
	}

	/**
	 * Robust replacement using WordPress HTML API:
	 * - Collect existing anchor ranges.
	 * - Replace in text portions only (outside tags/anchors).
	 */
	private function replace_keywords_with_html_api(string $content, array $entries, int $max_replacements): string {
		$anchor_ranges = $this->collect_anchor_ranges($content);

		$pattern_map = [];
		foreach ($entries as $idx => $entry) {
			foreach ($entry['keywords'] as $kw) {
				$trimmed = trim((string) $kw);
				if ('' === $trimmed) {
					continue;
				}
				$pattern_map[$trimmed] = $idx;
			}
		}

		if (empty($pattern_map)) {
			return $content;
		}

		// Longest keyword first to avoid partial overshadowing.
		uksort(
			$pattern_map,
			static function (string $a, string $b): int {
				return strlen($b) <=> strlen($a);
			}
		);

		$keywords_escaped = array_map(
			static function (string $k): string {
				return preg_quote($k, '/');
			},
			array_keys($pattern_map)
		);

		$pattern = '/\b(' . implode('|', $keywords_escaped) . ')\b/i';

		$chunks = preg_split('/(<[^>]+>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
		if (!is_array($chunks)) {
			return $content;
		}

		$result          = '';
		$current_offset  = 0;
		$total_replaced  = 0;

		foreach ($chunks as $chunk) {
			if ($total_replaced >= $max_replacements) {
				$result .= $chunk;
				$current_offset += strlen($chunk);
				continue;
			}

			$is_tag = isset($chunk[0]) && '<' === $chunk[0];
			if ($is_tag || '' === $chunk) {
				$result .= $chunk;
				$current_offset += strlen($chunk);
				continue;
			}

			$in_anchor = $this->offset_inside_ranges($current_offset, $anchor_ranges);
			if ($in_anchor) {
				$result .= $chunk;
				$current_offset += strlen($chunk);
				continue;
			}

			$chunk_replaced = preg_replace_callback(
				$pattern,
				function (array $matches) use (&$total_replaced, $max_replacements, $pattern_map, $entries): string {
					if ($total_replaced >= $max_replacements) {
						return $matches[0];
					}

					$matched = (string) $matches[1];
					$entry_index = $this->find_entry_index_for_keyword($matched, $pattern_map);
					if (null === $entry_index || !isset($entries[$entry_index])) {
						return $matches[0];
					}

					$total_replaced++;
					return $this->build_anchor_html($matches[0], $entries[$entry_index]);
				},
				$chunk
			);

			$result .= is_string($chunk_replaced) ? $chunk_replaced : $chunk;
			$current_offset += strlen($chunk);
		}

		return $result;
	}

	/**
	 * Fallback for older environments: split by tags and never replace inside existing anchors.
	 */
	private function replace_keywords_with_fallback(string $content, array $entries, int $max_replacements): string {
		$total_replaced = 0;
		$segments = preg_split('/(<a\b[^>]*>.*?<\/a>)/is', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
		if (!is_array($segments)) {
			return $content;
		}

		foreach ($segments as $i => $segment) {
			if ($total_replaced >= $max_replacements) {
				break;
			}

			if (preg_match('/^<a\b/i', $segment)) {
				continue; // Skip existing anchor blocks entirely.
			}

			foreach ($entries as $entry) {
				foreach ($entry['keywords'] as $keyword) {
					if ($total_replaced >= $max_replacements) {
						break 3;
					}

					$keyword = trim((string) $keyword);
					if ('' === $keyword) {
						continue;
					}

					$pattern = '/\b(' . preg_quote($keyword, '/') . ')\b/i';
					$replaced_this_round = 0;

					$segments[$i] = preg_replace_callback(
						$pattern,
						function (array $matches) use (&$replaced_this_round, &$total_replaced, $max_replacements, $entry): string {
							if ($replaced_this_round > 0 || $total_replaced >= $max_replacements) {
								return $matches[0];
							}

							$replaced_this_round++;
							$total_replaced++;
							return $this->build_anchor_html($matches[0], $entry);
						},
						$segments[$i],
						1
					);
				}
			}
		}

		return implode('', $segments);
	}

	private function build_anchor_html(string $anchor_text, array $entry): string {
		$url = esc_url($entry['url']);

		$attrs = ' href="' . $url . '"';

		if (!empty($entry['new_tab'])) {
			$attrs .= ' target="_blank"';
		}

		if (!empty($entry['rel'])) {
			$attrs .= ' rel="' . esc_attr((string) $entry['rel']) . '"';
		}

		return '<a' . $attrs . '>' . esc_html($anchor_text) . '</a>';
	}

	/**
	 * Collect byte ranges for existing <a>...</a> blocks using the HTML Tag Processor.
	 */
	private function collect_anchor_ranges(string $html): array {
		$ranges = [];

		if (!class_exists('WP_HTML_Tag_Processor')) {
			return $ranges;
		}

		$processor = new WP_HTML_Tag_Processor($html);

		$stack = [];
		while ($processor->next_tag()) {
			if ('A' !== $processor->get_tag()) {
				continue;
			}

			$token_starts_at = $processor->get_token_starts_at();
			$token_length    = $processor->get_token_length();
			if (!is_int($token_starts_at) || !is_int($token_length)) {
				continue;
			}

			$raw_token = substr($html, $token_starts_at, $token_length);
			if (!is_string($raw_token)) {
				continue;
			}

			$is_closing = isset($raw_token[1]) && '/' === $raw_token[1];

			if (!$is_closing) {
				$stack[] = $token_starts_at;
				continue;
			}

			$start = array_pop($stack);
			if (null === $start) {
				continue;
			}

			$end = $token_starts_at + $token_length;
			$ranges[] = [$start, $end];
		}

		return $ranges;
	}

	private function offset_inside_ranges(int $offset, array $ranges): bool {
		foreach ($ranges as $range) {
			$start = (int) ($range[0] ?? -1);
			$end   = (int) ($range[1] ?? -1);
			if ($offset >= $start && $offset < $end) {
				return true;
			}
		}
		return false;
	}

	private function find_entry_index_for_keyword(string $matched, array $pattern_map): ?int {
		$lower = function_exists('mb_strtolower') ? mb_strtolower($matched) : strtolower($matched);

		foreach ($pattern_map as $keyword => $idx) {
			$key = function_exists('mb_strtolower') ? mb_strtolower($keyword) : strtolower($keyword);
			if ($key === $lower) {
				return (int) $idx;
			}
		}
		return null;
	}
}
