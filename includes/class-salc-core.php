<?php
if (!defined('ABSPATH')) {
	exit;
}

class SALC_Core {

	public function init(): void {
		add_action('plugins_loaded', [$this, 'load_textdomain']);

		// Init components.
		SALC_CPT::init();
		SALC_DB::init();

		$admin = new SALC_Admin();
		$admin->init();

		$frontend = new SALC_Frontend();
		$frontend->init();
	}

	public function load_textdomain(): void {
		load_plugin_textdomain('salc-pro', false, dirname(SALC_PRO_BASENAME) . '/languages');
	}
}
