<?php
/**
 * Plugin Name: Smart Affiliate Link Cloaker & Auto-Replacer Pro
 * Description: Cloak affiliate links, auto-replace keywords in content, track clicks, and view analytics dashboard.
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: salc-pro
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
	exit;
}

define('SALC_PRO_VERSION', '1.0.0');
define('SALC_PRO_FILE', __FILE__);
define('SALC_PRO_PATH', plugin_dir_path(__FILE__));
define('SALC_PRO_URL', plugin_dir_url(__FILE__));
define('SALC_PRO_BASENAME', plugin_basename(__FILE__));

require_once SALC_PRO_PATH . 'includes/class-salc-activator.php';
require_once SALC_PRO_PATH . 'includes/class-salc-core.php';
require_once SALC_PRO_PATH . 'includes/class-salc-cpt.php';
require_once SALC_PRO_PATH . 'includes/class-salc-db.php';
require_once SALC_PRO_PATH . 'includes/class-salc-admin.php';
require_once SALC_PRO_PATH . 'includes/class-salc-frontend.php';

register_activation_hook(SALC_PRO_FILE, ['SALC_Activator', 'activate']);
register_deactivation_hook(SALC_PRO_FILE, ['SALC_Activator', 'deactivate']);

function salc_pro_run(): void {
	$core = new SALC_Core();
	$core->init();
}
salc_pro_run();
