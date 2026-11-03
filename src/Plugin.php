<?php
/**
 * Plugin bootstrap.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge;

use KeronLewis\SpaAiConcierge\Admin\AssistantMetaBox;
use KeronLewis\SpaAiConcierge\Faq\Faqs;
use KeronLewis\SpaAiConcierge\Finder\Finder;
use KeronLewis\SpaAiConcierge\Finder\TreatmentFields;
use KeronLewis\SpaAiConcierge\Finder\TreatmentRepository;
use KeronLewis\SpaAiConcierge\Rest\AssistantController;
use KeronLewis\SpaAiConcierge\Seo\SeoMeta;
use KeronLewis\SpaAiConcierge\Support\OutputGuard;

/**
 * Wires every component to WordPress.
 */
final class Plugin {

	/**
	 * Guard against double boot.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Register hooks (plugins_loaded).
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', array( self::class, 'load_textdomain' ), 1 );

		Settings::register();
		Assets::register();
		TreatmentFields::register();
		TreatmentRepository::register();
		Finder::register();
		Faqs::register();
		SeoMeta::register();
		AssistantController::register();
		Seeder::register();
		OutputGuard::register();

		if ( is_admin() ) {
			AssistantMetaBox::register();
		}
	}

	/**
	 * Load translations.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'spa-ai-concierge', false, dirname( plugin_basename( SPA_AI_CONCIERGE_FILE ) ) . '/languages' );
	}

	/**
	 * Activation: register the taxonomy, seed what can be seeded now, flush rewrites.
	 */
	public static function activate(): void {
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults() );
		}
		TreatmentFields::register_taxonomy();
		Seeder::run();
		flush_rewrite_rules( false );
	}

	/**
	 * Deactivation: drop transient state; keep settings and content.
	 */
	public static function deactivate(): void {
		delete_transient( Seeder::WAIT_TRANSIENT );
		delete_option( Seeder::LOCK_OPTION );
		flush_rewrite_rules( false );
	}
}
