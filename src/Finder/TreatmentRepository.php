<?php
/**
 * Loads treatment posts as plain arrays for scoring.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Finder;

use KeronLewis\SpaAiConcierge\Settings;

/**
 * Reads published posts of the finder post type. Results are cached per request and in the
 * object cache, and flushed when a post of that type is saved.
 */
final class TreatmentRepository {

	private const CACHE_GROUP = 'spa_ai_concierge';
	private const CACHE_KEY   = 'treatments_v1';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'save_post', array( self::class, 'flush' ) );
		add_action( 'deleted_post', array( self::class, 'flush' ) );
		add_action( 'set_object_terms', array( self::class, 'flush' ) );
	}

	/**
	 * Clear the cache.
	 */
	public static function flush(): void {
		wp_cache_delete( self::CACHE_KEY . '_' . Settings::resolved_finder_post_type(), self::CACHE_GROUP );
	}

	/**
	 * All treatments.
	 *
	 * @return array<int, array{id: int, title: string, url: string, slug: string, excerpt: string, image: string, concerns: string[], areas: string[], downtime: int|null, price: int|null}>
	 */
	public static function all(): array {
		$type   = Settings::resolved_finder_post_type();
		$key    = self::CACHE_KEY . '_' . $type;
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => $type,
				'post_status'            => 'publish',
				'posts_per_page'         => 200,
				'orderby'                => 'menu_order title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => true,
				'update_post_meta_cache' => true,
			)
		);

		$treatments = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$fields = TreatmentFields::get( $post->ID );

			$treatment = array(
				'id'       => $post->ID,
				'title'    => wp_specialchars_decode( get_the_title( $post ), ENT_QUOTES ),
				'url'      => (string) get_permalink( $post ),
				'slug'     => $post->post_name,
				'excerpt'  => wp_trim_words( '' !== $post->post_excerpt ? $post->post_excerpt : wp_strip_all_tags( strip_shortcodes( $post->post_content ) ), 22 ),
				'image'    => (string) get_the_post_thumbnail_url( $post, 'medium_large' ),
				'concerns' => $fields['concerns'],
				'areas'    => $fields['areas'],
				'downtime' => $fields['downtime'],
				'price'    => $fields['price'],
				'price_unit' => sanitize_text_field( (string) get_post_meta( $post->ID, 'price_unit', true ) ),
			);

			/**
			 * Filters a treatment's finder data, for themes that store areas, downtime or price
			 * under their own field names.
			 *
			 * @param array    $treatment Treatment data.
			 * @param \WP_Post $post      Post.
			 */
			$treatments[] = (array) apply_filters( 'spa_ai_concierge_treatment_data', $treatment, $post );
		}

		wp_cache_set( $key, $treatments, self::CACHE_GROUP, HOUR_IN_SECONDS );
		return $treatments;
	}
}
