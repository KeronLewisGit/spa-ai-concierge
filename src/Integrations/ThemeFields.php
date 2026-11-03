<?php
/**
 * Reads and writes ACF-style theme fields: an FAQ repeater and SEO title/description fields.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Integrations;

/**
 * Many salon/spa themes keep FAQs in an ACF repeater (e.g. `treatment_faqs` with `question` and
 * `answer` sub fields) and print their own FAQ schema, and keep SEO text in `seo_title` /
 * `seo_description`. When such fields exist on a post, the plugin imports from them and writes
 * reviewed changes back in ACF's storage layout, so the theme keeps rendering one consistent set.
 *
 * Works without ACF loaded: repeaters are stored as `name` = row count, `name_{i}_{sub}` = value,
 * and `_name…` = field key reference, which is what ACF itself writes.
 */
final class ThemeFields {

	/**
	 * Candidate FAQ field names.
	 *
	 * @return string[]
	 */
	private static function faq_candidates(): array {
		/**
		 * Filters the ACF/post-meta field names checked for theme FAQs. Return an empty array to
		 * stop the plugin reading or writing theme FAQ fields.
		 *
		 * @param string[] $names Field names.
		 */
		return (array) apply_filters( 'spa_ai_concierge_theme_faq_fields', array( 'treatment_faqs', 'service_faqs', 'faqs', 'faq' ) );
	}

	/**
	 * Candidate SEO field names.
	 *
	 * @return array{title: string[], description: string[]}
	 */
	private static function seo_candidates(): array {
		/**
		 * Filters the post-meta keys checked for theme SEO title/description fields.
		 *
		 * @param array{title: string[], description: string[]} $names Keys.
		 */
		return (array) apply_filters(
			'spa_ai_concierge_theme_seo_fields',
			array(
				'title'       => array( 'seo_title', 'meta_title' ),
				'description' => array( 'seo_description', 'meta_description' ),
			)
		);
	}

	/**
	 * Name of the theme FAQ field present on a post, or ''.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function faq_field( int $post_id ): string {
		foreach ( self::faq_candidates() as $name ) {
			$name = sanitize_key( (string) $name );
			if ( '' === $name || ! metadata_exists( 'post', $post_id, $name ) ) {
				continue;
			}
			$value = get_post_meta( $post_id, $name, true );
			if ( is_array( $value ) || is_numeric( $value ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * FAQ rows from the theme field.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function get_faqs( int $post_id ): array {
		$name = self::faq_field( $post_id );
		if ( '' === $name ) {
			return array();
		}

		$value = get_post_meta( $post_id, $name, true );
		$rows  = array();
		if ( is_array( $value ) ) {
			$rows = $value;
		} else {
			$count = min( 50, (int) $value );
			for ( $i = 0; $i < $count; $i++ ) {
				$rows[] = array(
					'question' => (string) get_post_meta( $post_id, "{$name}_{$i}_question", true ),
					'answer'   => (string) get_post_meta( $post_id, "{$name}_{$i}_answer", true ),
				);
			}
		}

		$clean = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && '' !== trim( (string) ( $row['question'] ?? '' ) ) && '' !== trim( (string) ( $row['answer'] ?? '' ) ) ) {
				$clean[] = array(
					'question' => (string) $row['question'],
					'answer'   => (string) $row['answer'],
				);
			}
		}
		return $clean;
	}

	/**
	 * Write FAQ rows back to the theme field, keeping ACF field-key references.
	 *
	 * @param int                                                 $post_id Post ID.
	 * @param array<int, array{question: string, answer: string}> $rows    Rows.
	 * @return bool Whether a theme field was updated.
	 */
	public static function write_faqs( int $post_id, array $rows ): bool {
		$name = self::faq_field( $post_id );
		if ( '' === $name ) {
			return false;
		}

		$rows    = array_values( $rows );
		$current = get_post_meta( $post_id, $name, true );

		if ( is_array( $current ) ) {
			update_post_meta( $post_id, $name, $rows );
			return true;
		}

		$old_count = (int) $current;
		$refs      = array(
			'question' => (string) get_post_meta( $post_id, "_{$name}_0_question", true ),
			'answer'   => (string) get_post_meta( $post_id, "_{$name}_0_answer", true ),
		);

		update_post_meta( $post_id, $name, count( $rows ) );
		foreach ( $rows as $i => $row ) {
			foreach ( array( 'question', 'answer' ) as $sub ) {
				update_post_meta( $post_id, "{$name}_{$i}_{$sub}", (string) $row[ $sub ] );
				if ( '' !== $refs[ $sub ] ) {
					update_post_meta( $post_id, "_{$name}_{$i}_{$sub}", $refs[ $sub ] );
				}
			}
		}
		for ( $i = count( $rows ); $i < $old_count; $i++ ) {
			foreach ( array( 'question', 'answer' ) as $sub ) {
				delete_post_meta( $post_id, "{$name}_{$i}_{$sub}" );
				delete_post_meta( $post_id, "_{$name}_{$i}_{$sub}" );
			}
		}
		return true;
	}

	/**
	 * Theme SEO keys present on a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array{title?: string, description?: string}
	 */
	public static function seo_keys( int $post_id ): array {
		$found = array();
		foreach ( self::seo_candidates() as $field => $keys ) {
			foreach ( (array) $keys as $key ) {
				if ( metadata_exists( 'post', $post_id, (string) $key ) ) {
					$found[ $field ] = (string) $key;
					break;
				}
			}
		}
		return $found;
	}

	/**
	 * Whether a meta key is part of a theme FAQ repeater (used to keep rows out of prompts).
	 *
	 * @param string $key Meta key.
	 */
	public static function is_repeater_row_key( string $key ): bool {
		return (bool) preg_match( '/_\d+_[a-z0-9_]+$/', $key ) || in_array( $key, self::faq_candidates(), true );
	}
}
