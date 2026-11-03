<?php
/**
 * Meta title/description storage, Yoast SEO / Rank Math sync, and fallback output.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Seo;

use KeronLewis\SpaAiConcierge\Integrations\ThemeFields;

/**
 * Saves to the plugin's own keys and mirrors to the active SEO plugin. When no SEO plugin is
 * active, prints the description meta tag and overrides the document title itself.
 */
final class SeoMeta {

	public const TITLE_KEY       = '_spa_ai_meta_title';
	public const DESCRIPTION_KEY = '_spa_ai_meta_description';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_filter( 'pre_get_document_title', array( self::class, 'document_title' ), 20 );
		add_action( 'wp_head', array( self::class, 'print_description' ), 1 );
	}

	/**
	 * Active SEO plugins this plugin can write to.
	 *
	 * @return array<string, string> slug => display name
	 */
	public static function active_plugins(): array {
		$active = array();
		if ( defined( 'WPSEO_VERSION' ) ) {
			$active['yoast'] = 'Yoast SEO';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath' ) ) {
			$active['rank-math'] = 'Rank Math';
		}
		return $active;
	}

	/**
	 * Save title and description; mirror to SEO plugins.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $title       Title ('' deletes).
	 * @param string $description Description ('' deletes).
	 */
	public static function save( int $post_id, string $title, string $description ): void {
		$title       = mb_substr( sanitize_text_field( $title ), 0, 200 );
		$description = mb_substr( sanitize_textarea_field( $description ), 0, 400 );
		$description = trim( (string) preg_replace( '/\s+/', ' ', $description ) );

		$pairs = array(
			self::TITLE_KEY       => $title,
			self::DESCRIPTION_KEY => $description,
		);

		$active = self::active_plugins();
		if ( isset( $active['yoast'] ) ) {
			$pairs['_yoast_wpseo_title']    = $title;
			$pairs['_yoast_wpseo_metadesc'] = $description;
		}
		if ( isset( $active['rank-math'] ) ) {
			$pairs['rank_math_title']       = $title;
			$pairs['rank_math_description'] = $description;
		}

		foreach ( ThemeFields::seo_keys( $post_id ) as $field => $key ) {
			$pairs[ $key ] = 'title' === $field ? $title : $description;
		}

		foreach ( $pairs as $key => $value ) {
			$is_own = in_array( $key, array( self::TITLE_KEY, self::DESCRIPTION_KEY ), true );
			if ( '' !== $value ) {
				update_post_meta( $post_id, $key, $value );
			} elseif ( $is_own && '' !== (string) get_post_meta( $post_id, $key, true ) ) {
				// Only clear SEO plugin values that we previously wrote; never wipe a value set there by hand.
				delete_post_meta( $post_id, $key );
			}
		}
	}

	/**
	 * Saved values.
	 *
	 * @param int $post_id Post ID.
	 * @return array{title: string, description: string}
	 */
	public static function get( int $post_id ): array {
		return array(
			'title'       => (string) get_post_meta( $post_id, self::TITLE_KEY, true ),
			'description' => (string) get_post_meta( $post_id, self::DESCRIPTION_KEY, true ),
		);
	}

	/**
	 * Whether this plugin should print tags itself.
	 */
	private static function should_output(): bool {
		$output = is_singular() && array() === self::active_plugins();
		/**
		 * Filters whether the plugin prints the saved meta description and title tag when no
		 * SEO plugin is active.
		 *
		 * @param bool $output Default decision.
		 */
		return (bool) apply_filters( 'spa_ai_concierge_output_meta_tags', $output );
	}

	/**
	 * Override the document title.
	 *
	 * @param string $title Current title.
	 */
	public static function document_title( $title ) {
		if ( ! self::should_output() ) {
			return $title;
		}
		$saved = self::get( (int) get_queried_object_id() )['title'];
		return '' !== $saved ? esc_html( $saved ) : $title;
	}

	/**
	 * Print the meta description.
	 */
	public static function print_description(): void {
		if ( ! self::should_output() ) {
			return;
		}
		$description = self::get( (int) get_queried_object_id() )['description'];
		if ( '' !== $description ) {
			echo '<meta name="description" content="' . esc_attr( $description ) . '" data-spa-ai-concierge>' . "\n";
		}
	}
}
