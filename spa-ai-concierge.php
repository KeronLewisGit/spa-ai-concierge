<?php
/**
 * Plugin Name:       Spa AI Concierge
 * Plugin URI:        https://keronlewis.dev
 * Description:       AI content assistant for salon, spa and medspa sites (FAQs with FAQPage schema, meta titles and descriptions, image alt text via the Anthropic API) plus a rule-based Treatment Finder quiz.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Keron Lewis
 * Author URI:        https://keronlewis.dev
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       spa-ai-concierge
 * Domain Path:       /languages
 *
 * @package SpaAiConcierge
 */

defined( 'ABSPATH' ) || exit;

define( 'SPA_AI_CONCIERGE_VERSION', '1.0.0' );
define( 'SPA_AI_CONCIERGE_FILE', __FILE__ );
define( 'SPA_AI_CONCIERGE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPA_AI_CONCIERGE_URL', plugin_dir_url( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__( 'Spa AI Concierge needs PHP 8.1 or newer. The plugin is installed but inactive.', 'spa-ai-concierge' );
			echo '</p></div>';
		}
	);
	return;
}

// Composer autoloader when present (development); otherwise a small PSR-4 loader so the
// plugin runs straight from an uploaded zip without `composer install`.
if ( is_readable( SPA_AI_CONCIERGE_DIR . 'vendor/autoload.php' ) ) {
	require_once SPA_AI_CONCIERGE_DIR . 'vendor/autoload.php';
} else {
	require_once SPA_AI_CONCIERGE_DIR . 'src/Autoloader.php';
	\KeronLewis\SpaAiConcierge\Autoloader::register( SPA_AI_CONCIERGE_DIR . 'src/' );
}

register_activation_hook( __FILE__, array( \KeronLewis\SpaAiConcierge\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \KeronLewis\SpaAiConcierge\Plugin::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( \KeronLewis\SpaAiConcierge\Plugin::class, 'boot' ) );
