<?php
/**
 * Front-end asset registration and blocks.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge;

use KeronLewis\SpaAiConcierge\Faq\Faqs;
use KeronLewis\SpaAiConcierge\Finder\Finder;

/**
 * Registers styles/scripts and the two dynamic blocks.
 */
final class Assets {

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_assets' ), 5 );
		add_action( 'init', array( self::class, 'register_blocks' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'maybe_enqueue_early' ) );
	}

	/**
	 * Register handles used by blocks and shortcodes.
	 */
	public static function register_assets(): void {
		wp_register_style( 'spa-ai-concierge', SPA_AI_CONCIERGE_URL . 'assets/css/frontend.css', array(), SPA_AI_CONCIERGE_VERSION );
		wp_register_script(
			'spa-ai-concierge-finder',
			SPA_AI_CONCIERGE_URL . 'assets/js/finder.js',
			array(),
			SPA_AI_CONCIERGE_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'spa-ai-concierge-finder',
			'spaAiFinder',
			array(
				'error'    => __( 'Sorry, something went wrong loading your matches. Please try again.', 'spa-ai-concierge' ),
				'loading'  => __( 'Finding your matches…', 'spa-ai-concierge' ),
				/* translators: 1: step number, 2: total steps */
				'progress' => __( 'Step %1$d of %2$d', 'spa-ai-concierge' ),
			)
		);
	}

	/**
	 * Register blocks from block.json.
	 */
	public static function register_blocks(): void {
		register_block_type(
			SPA_AI_CONCIERGE_DIR . 'blocks/faqs',
			array( 'render_callback' => array( Faqs::class, 'render_block' ) )
		);
		register_block_type(
			SPA_AI_CONCIERGE_DIR . 'blocks/treatment-finder',
			array( 'render_callback' => array( Finder::class, 'render_block' ) )
		);
	}

	/**
	 * Enqueue in <head> when the current post is known to use the blocks or shortcodes.
	 */
	public static function maybe_enqueue_early(): void {
		$post = get_post();
		if ( ! is_singular() || ! $post instanceof \WP_Post ) {
			return;
		}
		$content = $post->post_content;
		if ( has_block( Finder::BLOCK, $post ) || has_shortcode( $content, Finder::SHORTCODE ) ) {
			self::enqueue_frontend( true );
		} elseif ( has_block( Faqs::BLOCK, $post ) || has_shortcode( $content, Faqs::SHORTCODE ) || Settings::finder_post_type() === $post->post_type ) {
			self::enqueue_frontend( false );
		}
	}

	/**
	 * Enqueue the stylesheet, and the finder script if needed.
	 *
	 * @param bool $finder Whether to load the finder script.
	 */
	public static function enqueue_frontend( bool $finder ): void {
		wp_enqueue_style( 'spa-ai-concierge' );
		if ( $finder ) {
			wp_enqueue_script( 'spa-ai-concierge-finder' );
		}
	}
}
