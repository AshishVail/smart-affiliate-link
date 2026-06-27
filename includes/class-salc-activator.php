<?php
if (!defined('ABSPATH')) {
	exit;
}

class SALC_Activator {

	public static function activate(): void {
		// Default options.
		if (!get_option('salc_redirect_prefix')) {
			update_option('salc_redirect_prefix', 'go');
		}
		if (!get_option('salc_max_replacements_per_post')) {
			update_option('salc_max_replacements_per_post', 3);
		}

		// Register CPT and create table before flushing rewrites.
		SALC_CPT::register_cpt();
		SALC_DB::create_clicks_table();
		SALC_Frontend::add_rewrite_rules();

		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
