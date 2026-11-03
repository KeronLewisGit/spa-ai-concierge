<?php
/**
 * Removes this plugin's FAQPage JSON-LD or meta description if the theme or another plugin
 * printed the same thing elsewhere on the page.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Support;

use KeronLewis\SpaAiConcierge\Faq\FaqRepository;
use KeronLewis\SpaAiConcierge\Seo\SeoMeta;

/**
 * Buffers singular pages that may carry plugin output and de-duplicates at the end. Pages with
 * nothing from this plugin are not buffered.
 */
final class OutputGuard {

	private const SCHEMA_PATTERN = '#\s*<script type="application/ld\+json" class="spa-ai-concierge-faq-schema">.*?</script>\s*#s';
	private const META_PATTERN   = '#<meta name="description" content="[^"]*" data-spa-ai-concierge>\s*#';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'start' ), 1 );
	}

	/**
	 * Start buffering when this plugin may print FAQ schema or a meta description.
	 */
	public static function start(): void {
		if ( ! is_singular() || is_feed() || wp_doing_ajax() || is_admin() ) {
			return;
		}
		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}
		$meta = SeoMeta::get( $post_id );
		if ( array() === FaqRepository::get( $post_id ) && '' === $meta['description'] ) {
			return;
		}
		ob_start( array( self::class, 'filter' ) );
	}

	/**
	 * Output-buffer callback.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	public static function filter( $html ) {
		return is_string( $html ) ? self::dedupe( $html ) : $html;
	}

	/**
	 * Pure de-duplication rule, unit-tested.
	 *
	 * @param string $html Page HTML.
	 */
	public static function dedupe( string $html ): string {
		if ( str_contains( $html, 'spa-ai-concierge-faq-schema' ) ) {
			$without = (string) preg_replace( self::SCHEMA_PATTERN, "\n", $html );
			if ( self::contains_faq_page( $without ) ) {
				$html = $without;
			}
		}

		if ( str_contains( $html, 'data-spa-ai-concierge>' ) ) {
			$without = (string) preg_replace( self::META_PATTERN, '', $html );
			if ( preg_match( '#<meta\s+name=["\']description["\']#i', $without ) ) {
				$html = $without;
			}
		}

		return $html;
	}

	/**
	 * Whether HTML contains a FAQPage type in JSON-LD (standalone or inside @graph) or microdata.
	 *
	 * @param string $html HTML.
	 */
	public static function contains_faq_page( string $html ): bool {
		return (bool) preg_match( '#"@type"\s*:\s*(\[[^\]]*)?"FAQPage"|itemtype="https?://schema\.org/FAQPage"#i', $html );
	}
}
