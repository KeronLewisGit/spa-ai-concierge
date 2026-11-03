<?php
/**
 * Seeds Concern terms, finder fields, demo FAQs and the Treatment Finder page.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge;

use KeronLewis\SpaAiConcierge\Ai\DemoResponder;
use KeronLewis\SpaAiConcierge\Ai\ResponseParser;
use KeronLewis\SpaAiConcierge\Ai\Tasks;
use KeronLewis\SpaAiConcierge\Faq\FaqRepository;
use KeronLewis\SpaAiConcierge\Finder\Catalog;
use KeronLewis\SpaAiConcierge\Finder\Finder;
use KeronLewis\SpaAiConcierge\Finder\TreatmentFields;
use KeronLewis\SpaAiConcierge\Finder\TreatmentRepository;
use KeronLewis\SpaAiConcierge\Integrations\ThemeFields;

/**
 * Runs on activation and lazily on init until treatments exist.
 *
 * The lazy path covers hosts that activate plugins with WP-CLI before the theme that registers
 * the treatment post type: on init it checks at most once a minute until treatments exist,
 * seeds once, then sets a done flag. Every step only fills empty values, so re-running is safe.
 */
final class Seeder {

	public const DONE_OPTION    = 'spa_ai_concierge_seeded';
	public const LOCK_OPTION    = 'spa_ai_concierge_seed_lock';
	public const WAIT_TRANSIENT = 'spa_ai_concierge_seed_wait';
	public const PAGE_OPTION    = 'spa_ai_concierge_finder_page';
	private const LOCK_TTL      = 300;
	private const RETRY_EVERY   = MINUTE_IN_SECONDS;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		// After themes (priority 10) and the Concern taxonomy (priority 20) are registered.
		add_action( 'init', array( self::class, 'maybe_run' ), 99 );
	}

	/**
	 * Lazy entry point on init.
	 */
	public static function maybe_run(): void {
		if ( get_option( self::DONE_OPTION ) || get_transient( self::WAIT_TRANSIENT ) ) {
			return;
		}
		self::run();
	}

	/**
	 * Seed if treatments exist. Returns a status string for logging and tests.
	 *
	 * @return string done, waiting or locked
	 */
	public static function run(): string {
		if ( ! self::acquire_lock() ) {
			return 'locked';
		}

		try {
			self::ensure_concern_terms();
			self::ensure_finder_page();

			$type = Settings::finder_post_type();
			if ( ! post_type_exists( $type ) ) {
				set_transient( self::WAIT_TRANSIENT, 1, self::RETRY_EVERY );
				return 'waiting';
			}

			$posts = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded one-off seeding query.
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);
			if ( array() === $posts ) {
				set_transient( self::WAIT_TRANSIENT, 1, self::RETRY_EVERY );
				return 'waiting';
			}

			$seeded = 0;
			foreach ( $posts as $post_id ) {
				$seeded += self::seed_treatment( (int) $post_id ) ? 1 : 0;
			}

			update_option(
				self::DONE_OPTION,
				array(
					'version'   => SPA_AI_CONCIERGE_VERSION,
					'time'      => time(),
					'post_type' => $type,
					'seeded'    => $seeded,
				),
				false
			);
			delete_transient( self::WAIT_TRANSIENT );
			TreatmentRepository::flush();
			return 'done';
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Fill a single treatment's concerns, fields and demo FAQs where empty.
	 *
	 * @param int $post_id Post ID.
	 * @return bool Whether the title matched a known profile.
	 */
	public static function seed_treatment( int $post_id ): bool {
		$title = (string) get_the_title( $post_id );
		$key   = Catalog::match_profile( $title );
		if ( null === $key ) {
			return false;
		}
		$profile = Catalog::profiles()[ $key ];

		$existing = wp_get_object_terms( $post_id, TreatmentFields::TAXONOMY, array( 'fields' => 'ids' ) );
		if ( is_array( $existing ) && array() === $existing ) {
			wp_set_object_terms( $post_id, $profile['concerns'], TreatmentFields::TAXONOMY, false );
		}

		$areas = get_post_meta( $post_id, TreatmentFields::META_AREAS, true );
		if ( ! is_array( $areas ) || array() === $areas ) {
			update_post_meta( $post_id, TreatmentFields::META_AREAS, $profile['areas'] );
		}
		// Prefer values the theme already stores (ACF-style fields), else the profile defaults.
		$theme = TreatmentFields::theme_values( $post_id );
		if ( '' === (string) get_post_meta( $post_id, TreatmentFields::META_DOWNTIME, true ) ) {
			update_post_meta( $post_id, TreatmentFields::META_DOWNTIME, $theme['downtime'] ?? $profile['downtime'] );
		}
		if ( '' === (string) get_post_meta( $post_id, TreatmentFields::META_PRICE, true ) ) {
			update_post_meta( $post_id, TreatmentFields::META_PRICE, $theme['price'] ?? $profile['price'] );
		}

		if ( array() === FaqRepository::get( $post_id ) ) {
			// Adopt FAQs the theme already has so the page shows one consistent set.
			$theme_faqs = ThemeFields::get_faqs( $post_id );
			if ( array() !== $theme_faqs ) {
				FaqRepository::save( $post_id, $theme_faqs, 'theme' );
				return true;
			}

			$context = array(
				'title'     => wp_specialchars_decode( $title, ENT_QUOTES ),
				'site_name' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			);
			$faqs    = ResponseParser::validate( DemoResponder::respond( Tasks::FAQS, $context ), Tasks::FAQS );
			FaqRepository::save( $post_id, $faqs['faqs'], 'demo' );
		}

		return true;
	}

	/**
	 * Create default Concern terms that do not exist yet.
	 */
	public static function ensure_concern_terms(): void {
		if ( ! taxonomy_exists( TreatmentFields::TAXONOMY ) ) {
			TreatmentFields::register_taxonomy();
		}
		foreach ( Catalog::concerns() as $slug => $name ) {
			if ( ! term_exists( $slug, TreatmentFields::TAXONOMY ) ) {
				wp_insert_term( $name, TreatmentFields::TAXONOMY, array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * Create a published "Treatment Finder" page unless some page already contains the finder.
	 *
	 * @return int Page ID (existing or new), 0 on failure.
	 */
	public static function ensure_finder_page(): int {
		$stored = (int) get_option( self::PAGE_OPTION );
		if ( $stored > 0 && 'publish' === get_post_status( $stored ) ) {
			return $stored;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off lookup during seeding.
		$existing = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post','wp_template','wp_block') AND (post_content LIKE %s OR post_content LIKE %s) LIMIT 1",
				'%' . $wpdb->esc_like( '<!-- wp:' . Finder::BLOCK ) . '%',
				'%' . $wpdb->esc_like( '[' . Finder::SHORTCODE ) . '%'
			)
		);
		if ( $existing > 0 ) {
			update_option( self::PAGE_OPTION, $existing, false );
			return $existing;
		}

		// A page already using the slug (e.g. created by the theme) gets the block appended.
		$by_slug = get_page_by_path( 'treatment-finder' );
		if ( $by_slug instanceof \WP_Post && 'publish' === $by_slug->post_status ) {
			wp_update_post(
				array(
					'ID'           => $by_slug->ID,
					'post_content' => rtrim( $by_slug->post_content ) . "\n\n<!-- wp:" . Finder::BLOCK . ' {"align":"wide"} /-->',
				)
			);
			update_option( self::PAGE_OPTION, $by_slug->ID, false );
			return $by_slug->ID;
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Treatment Finder', 'spa-ai-concierge' ),
				'post_name'    => 'treatment-finder',
				'post_content' => '<!-- wp:' . Finder::BLOCK . ' {"align":"wide"} /-->',
				'post_excerpt' => __( 'Answer four quick questions to see which treatments fit your goals, downtime and budget.', 'spa-ai-concierge' ),
			),
			true
		);
		if ( is_wp_error( $page_id ) ) {
			return 0;
		}
		update_option( self::PAGE_OPTION, (int) $page_id, false );
		return (int) $page_id;
	}

	/**
	 * Take the seeding lock. add_option() only inserts when the row does not exist, which the
	 * options table's unique key makes atomic across concurrent requests.
	 */
	private static function acquire_lock(): bool {
		if ( add_option( self::LOCK_OPTION, time(), '', false ) ) {
			return true;
		}
		$since = (int) get_option( self::LOCK_OPTION );
		if ( $since > 0 && time() - $since > self::LOCK_TTL ) {
			// Stale lock from a crashed request.
			delete_option( self::LOCK_OPTION );
			return add_option( self::LOCK_OPTION, time(), '', false );
		}
		return false;
	}

	/**
	 * Release the lock.
	 */
	private static function release_lock(): void {
		delete_option( self::LOCK_OPTION );
	}
}
