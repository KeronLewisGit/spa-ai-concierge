<?php
/**
 * Finder fields on treatment posts (areas, downtime, price) plus the Concern taxonomy.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Finder;

use KeronLewis\SpaAiConcierge\Settings;

/**
 * Registers the taxonomy and meta, renders the "Treatment Finder" meta box and reads values
 * with fallbacks for themes that store similar data under their own keys.
 */
final class TreatmentFields {

	public const TAXONOMY     = 'concern';
	public const META_AREAS   = '_spa_ai_areas';
	public const META_DOWNTIME = '_spa_ai_downtime_days';
	public const META_PRICE   = '_spa_ai_price_from';
	private const NONCE       = 'spa_ai_finder_fields';

	/**
	 * Theme/ACF meta keys checked when the plugin's own fields are empty.
	 */
	private const DOWNTIME_FALLBACK_KEYS = array( 'downtime_days', 'downtime', 'treatment_downtime', 'recovery_time' );
	private const PRICE_FALLBACK_KEYS    = array( 'price_from', 'starting_price', 'from_price', 'price', 'treatment_price' );

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'register_taxonomy' ), 20 );
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );
		add_action( 'save_post', array( self::class, 'save' ), 10, 2 );
	}

	/**
	 * Register the Concern taxonomy on the finder post type, and the meta keys.
	 */
	public static function register_taxonomy(): void {
		$type = Settings::finder_post_type();
		$types = array_unique( array( $type, 'page' ) );

		register_taxonomy(
			self::TAXONOMY,
			$types,
			array(
				'labels'            => array(
					'name'          => __( 'Concerns', 'spa-ai-concierge' ),
					'singular_name' => __( 'Concern', 'spa-ai-concierge' ),
					'menu_name'     => __( 'Concerns', 'spa-ai-concierge' ),
					'all_items'     => __( 'All concerns', 'spa-ai-concierge' ),
					'edit_item'     => __( 'Edit concern', 'spa-ai-concierge' ),
					'add_new_item'  => __( 'Add new concern', 'spa-ai-concierge' ),
					'search_items'  => __( 'Search concerns', 'spa-ai-concierge' ),
					'not_found'     => __( 'No concerns found', 'spa-ai-concierge' ),
				),
				'description'       => __( 'Client concerns used by the Treatment Finder quiz.', 'spa-ai-concierge' ),
				'public'            => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'concern' ),
			)
		);

		foreach ( $types as $post_type ) {
			register_post_meta(
				$post_type,
				self::META_DOWNTIME,
				array(
					'type'              => 'integer',
					'single'            => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => static fn( $allowed, $key, $post_id ) => current_user_can( 'edit_post', $post_id ),
				)
			);
			register_post_meta(
				$post_type,
				self::META_PRICE,
				array(
					'type'              => 'integer',
					'single'            => true,
					'sanitize_callback' => 'absint',
					'auth_callback'     => static fn( $allowed, $key, $post_id ) => current_user_can( 'edit_post', $post_id ),
				)
			);
		}
	}

	/**
	 * Meta box on the finder post type.
	 */
	public static function add_meta_box(): void {
		$type = Settings::resolved_finder_post_type();
		add_meta_box(
			'spa-ai-finder-fields',
			__( 'Treatment Finder', 'spa-ai-concierge' ),
			array( self::class, 'render_meta_box' ),
			$type,
			'side',
			'default'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_meta_box( \WP_Post $post ): void {
		$areas    = (array) get_post_meta( $post->ID, self::META_AREAS, true );
		$downtime = get_post_meta( $post->ID, self::META_DOWNTIME, true );
		$price    = get_post_meta( $post->ID, self::META_PRICE, true );
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		?>
		<div class="spa-ai-finder-box">
			<p class="description"><?php esc_html_e( 'Used to score this treatment in the Treatment Finder. Pick its Concerns in the Concerns box.', 'spa-ai-concierge' ); ?></p>
			<fieldset>
				<legend><strong><?php esc_html_e( 'Treatment areas', 'spa-ai-concierge' ); ?></strong></legend>
				<?php foreach ( Catalog::areas() as $slug => $label ) : ?>
					<label style="display:block"><input type="checkbox" name="spa_ai_finder[areas][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $areas, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</fieldset>
			<p>
				<label for="spa-ai-downtime"><strong><?php esc_html_e( 'Typical downtime (days)', 'spa-ai-concierge' ); ?></strong></label><br>
				<input type="number" min="0" max="60" step="1" id="spa-ai-downtime" name="spa_ai_finder[downtime]" value="<?php echo esc_attr( (string) $downtime ); ?>" class="small-text">
			</p>
			<p>
				<label for="spa-ai-price"><strong><?php esc_html_e( 'Price from (whole dollars)', 'spa-ai-concierge' ); ?></strong></label><br>
				<input type="number" min="0" step="1" id="spa-ai-price" name="spa_ai_finder[price]" value="<?php echo esc_attr( (string) $price ); ?>" class="small-text">
			</p>
		</div>
		<?php
	}

	/**
	 * Save meta box values.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$input = isset( $_POST['spa_ai_finder'] ) && is_array( $_POST['spa_ai_finder'] ) ? wp_unslash( $_POST['spa_ai_finder'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each key sanitized below.

		$areas = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['areas'] ?? array() ) ), array_keys( Catalog::areas() ) ) );
		update_post_meta( $post_id, self::META_AREAS, $areas );

		foreach ( array( 'downtime' => self::META_DOWNTIME, 'price' => self::META_PRICE ) as $field => $key ) {
			$raw = isset( $input[ $field ] ) ? trim( (string) $input[ $field ] ) : '';
			if ( '' === $raw ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, absint( $raw ) );
			}
		}
	}

	/**
	 * Finder data for a post with fallbacks.
	 *
	 * @param int $post_id Post ID.
	 * @return array{areas: string[], downtime: int|null, price: int|null, concerns: string[]}
	 */
	public static function get( int $post_id ): array {
		$areas = get_post_meta( $post_id, self::META_AREAS, true );
		$areas = is_array( $areas ) ? array_values( array_filter( array_map( 'strval', $areas ) ) ) : array();
		if ( array() === $areas ) {
			$areas = self::areas_from_terms( $post_id );
		}

		$downtime = self::number_meta( $post_id, self::META_DOWNTIME, self::DOWNTIME_FALLBACK_KEYS, array( self::class, 'parse_days' ) );
		$price    = self::number_meta( $post_id, self::META_PRICE, self::PRICE_FALLBACK_KEYS, array( self::class, 'parse_number' ) );

		$concerns = wp_get_post_terms( $post_id, self::TAXONOMY, array( 'fields' => 'slugs' ) );

		return array(
			'areas'    => $areas,
			'downtime' => $downtime,
			'price'    => $price,
			'concerns' => is_array( $concerns ) ? array_map( 'strval', $concerns ) : array(),
		);
	}

	/**
	 * First parseable value from the plugin key, then the theme fallback keys.
	 *
	 * @param int      $post_id  Post ID.
	 * @param string   $key      Primary key.
	 * @param string[] $fallback Fallback keys.
	 * @param callable $parser   Parser returning int|null.
	 */
	private static function number_meta( int $post_id, string $key, array $fallback, callable $parser ): ?int {
		foreach ( array_merge( array( $key ), $fallback ) as $meta_key ) {
			$value  = get_post_meta( $post_id, $meta_key, true );
			$parsed = $parser( $value );
			if ( null !== $parsed ) {
				return $parsed;
			}
		}
		return null;
	}

	/**
	 * Theme field values only, ignoring the plugin's own keys (used by the seeder).
	 *
	 * @param int $post_id Post ID.
	 * @return array{downtime: int|null, price: int|null}
	 */
	public static function theme_values( int $post_id ): array {
		$downtime = null;
		foreach ( self::DOWNTIME_FALLBACK_KEYS as $key ) {
			$downtime = self::parse_days( get_post_meta( $post_id, $key, true ) );
			if ( null !== $downtime ) {
				break;
			}
		}
		$price = null;
		foreach ( self::PRICE_FALLBACK_KEYS as $key ) {
			$price = self::parse_number( get_post_meta( $post_id, $key, true ) );
			if ( null !== $price ) {
				break;
			}
		}
		return array(
			'downtime' => $downtime,
			'price'    => $price,
		);
	}

	/**
	 * Downtime in whole days from free text, using the upper end of a range.
	 * "None; avoid exercise for 24 hours" → 0, "1 to 3 days of redness" → 3,
	 * "Mild redness for a few hours" → 0, "1-2 weeks" → 14.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function parse_days( $value ): ?int {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}
		if ( ! is_string( $value ) && ! is_float( $value ) ) {
			return null;
		}
		$text = strtolower( trim( (string) $value ) );
		if ( '' === $text ) {
			return null;
		}
		if ( is_numeric( $text ) ) {
			return max( 0, (int) $text );
		}
		if ( preg_match( '/^(none|no\b|zero|minimal|same[- ]day)/', $text ) ) {
			return 0;
		}
		$unit_days = static function ( int $n, string $unit ): int {
			if ( str_starts_with( $unit, 'week' ) ) {
				return $n * 7;
			}
			if ( str_starts_with( $unit, 'hour' ) ) {
				return $n <= 24 ? 0 : (int) ceil( $n / 24 );
			}
			return $n;
		};
		if ( preg_match( '/(\d+)\s*(?:to|-|–|—)\s*(\d+)\s*(day|week|hour)/u', $text, $m ) ) {
			return $unit_days( (int) $m[2], $m[3] );
		}
		if ( preg_match( '/(\d+)\s*(day|week|hour)/', $text, $m ) ) {
			return $unit_days( (int) $m[1], $m[2] );
		}
		if ( str_contains( $text, 'hour' ) ) {
			return 0;
		}
		return null;
	}

	/**
	 * Parse the first whole number out of a value; "none"/"no downtime" → 0.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function parse_number( $value ): ?int {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}
		if ( ! is_string( $value ) && ! is_float( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( preg_match( '/^(none|no downtime|zero|same day)$/i', $value ) ) {
			return 0;
		}
		if ( preg_match( '/(\d[\d,]*)/', $value, $m ) ) {
			return (int) str_replace( ',', '', $m[1] );
		}
		return null;
	}

	/**
	 * Infer areas from any other taxonomy on the post (e.g. a theme's area taxonomy).
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private static function areas_from_terms( int $post_id ): array {
		$map = array(
			'face'     => '/face|facial|skin|lip|eye|neck|brow|injectable/i',
			'body'     => '/body|leg|arm|underarm|bikini|back|abdomen|contour|sculpt/i',
			'wellness' => '/wellness|iv|drip|vitamin|hydration|weight|hormone/i',
		);
		$areas = array();
		foreach ( get_object_taxonomies( get_post_type( $post_id ) ) as $taxonomy ) {
			if ( self::TAXONOMY === $taxonomy ) {
				continue;
			}
			$terms = get_the_terms( $post_id, $taxonomy );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				foreach ( $map as $area => $pattern ) {
					if ( preg_match( $pattern, $term->slug . ' ' . $term->name ) ) {
						$areas[] = $area;
					}
				}
			}
		}
		return array_values( array_unique( $areas ) );
	}
}
