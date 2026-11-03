<?php
/**
 * Collects the post data sent to the model: title, content, excerpt, custom fields, terms.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Ai;

use KeronLewis\SpaAiConcierge\Finder\Catalog;
use KeronLewis\SpaAiConcierge\Finder\TreatmentFields;
use KeronLewis\SpaAiConcierge\Integrations\ThemeFields;
use KeronLewis\SpaAiConcierge\Settings;

/**
 * WordPress-facing half of prompt building.
 */
final class ContextBuilder {

	private const MAX_FIELDS      = 20;
	private const MAX_FIELD_CHARS = 500;

	/**
	 * Build a context array for a post.
	 *
	 * @param \WP_Post             $post      Post.
	 * @param array<string, mixed> $overrides Unsaved editor values: title, content, excerpt.
	 * @param int                  $image_id  Attachment ID for alt text (0 = featured image).
	 * @return array<string, mixed>
	 */
	public static function for_post( \WP_Post $post, array $overrides = array(), int $image_id = 0 ): array {
		$type_object = get_post_type_object( $post->post_type );

		$context = array(
			'title'       => '' !== (string) ( $overrides['title'] ?? '' ) ? (string) $overrides['title'] : get_the_title( $post ),
			'content'     => '' !== (string) ( $overrides['content'] ?? '' ) ? (string) $overrides['content'] : (string) $post->post_content,
			'excerpt'     => '' !== (string) ( $overrides['excerpt'] ?? '' ) ? (string) $overrides['excerpt'] : (string) $post->post_excerpt,
			'post_type'   => $type_object ? (string) $type_object->labels->singular_name : $post->post_type,
			'fields'      => self::fields( $post ),
			'site_name'   => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'brand_voice' => (string) Settings::get( 'brand_voice' ),
		);

		$context['title'] = wp_specialchars_decode( wp_strip_all_tags( $context['title'] ), ENT_QUOTES );

		$image_id = $image_id > 0 ? $image_id : (int) get_post_thumbnail_id( $post );
		if ( $image_id > 0 ) {
			$attachment = get_post( $image_id );
			if ( $attachment instanceof \WP_Post && 'attachment' === $attachment->post_type ) {
				$file             = (string) get_attached_file( $image_id );
				$context['image'] = array(
					'id'          => $image_id,
					'filename'    => '' !== $file ? wp_basename( $file ) : wp_basename( (string) wp_get_attachment_url( $image_id ) ),
					'title'       => (string) $attachment->post_title,
					'caption'     => (string) $attachment->post_excerpt,
					'description' => wp_trim_words( (string) $attachment->post_content, 40, '' ),
					'mime_type'   => (string) $attachment->post_mime_type,
				);
			}
		}

		/**
		 * Filters the context array used to build prompts and demo output.
		 *
		 * @param array<string, mixed> $context Context.
		 * @param \WP_Post             $post    Post.
		 */
		return (array) apply_filters( 'spa_ai_concierge_post_context', $context, $post );
	}

	/**
	 * Custom fields as label => value: ACF field objects when ACF is active, otherwise public
	 * post meta, plus taxonomy terms and the plugin's own finder fields.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, string>
	 */
	public static function fields( \WP_Post $post ): array {
		$fields = array();

		if ( function_exists( 'get_field_objects' ) ) {
			$objects = get_field_objects( $post->ID );
			foreach ( is_array( $objects ) ? $objects : array() as $object ) {
				$value = self::stringify( $object['value'] ?? null );
				if ( '' !== $value ) {
					$fields[ (string) ( $object['label'] ?? $object['name'] ?? '' ) ] = $value;
				}
			}
		} else {
			foreach ( get_post_meta( $post->ID ) as $key => $values ) {
				if ( str_starts_with( (string) $key, '_' ) || str_starts_with( (string) $key, 'spa_ai' ) || str_starts_with( (string) $key, 'rank_math' ) || ThemeFields::is_repeater_row_key( (string) $key ) ) {
					continue;
				}
				$value = self::stringify( maybe_unserialize( $values[0] ?? '' ) );
				if ( '' !== $value ) {
					$fields[ ucfirst( str_replace( array( '_', '-' ), ' ', (string) $key ) ) ] = $value;
				}
			}
		}

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public || 'post_format' === $taxonomy->name ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( is_array( $terms ) && array() !== $terms ) {
				$fields[ (string) $taxonomy->labels->name ] = implode( ', ', wp_list_pluck( $terms, 'name' ) );
			}
		}

		$finder = TreatmentFields::get( $post->ID );
		if ( null !== $finder['downtime'] ) {
			$fields['Typical downtime'] = 0 === $finder['downtime'] ? 'None' : $finder['downtime'] . ' day(s)';
		}
		if ( null !== $finder['price'] ) {
			$fields['Price from'] = '$' . number_format_i18n( $finder['price'] );
		}
		if ( array() !== $finder['areas'] ) {
			$labels                    = Catalog::areas();
			$fields['Treatment areas'] = implode( ', ', array_map( static fn( $a ) => $labels[ $a ] ?? $a, $finder['areas'] ) );
		}

		return array_slice( array_filter( $fields, static fn( $v, $k ) => '' !== trim( (string) $k ), ARRAY_FILTER_USE_BOTH ), 0, self::MAX_FIELDS, true );
	}

	/**
	 * Flatten a field value to a short string; arrays of scalars are joined.
	 *
	 * @param mixed $value Value.
	 */
	private static function stringify( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'Yes' : '';
		}
		if ( is_scalar( $value ) ) {
			$string = trim( wp_strip_all_tags( (string) $value ) );
			return mb_strlen( $string ) > self::MAX_FIELD_CHARS ? mb_substr( $string, 0, self::MAX_FIELD_CHARS ) . '…' : $string;
		}
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) && '' !== trim( (string) $item ) ) {
					$parts[] = trim( wp_strip_all_tags( (string) $item ) );
				} elseif ( $item instanceof \WP_Post ) {
					$parts[] = $item->post_title;
				} elseif ( $item instanceof \WP_Term ) {
					$parts[] = $item->name;
				}
			}
			return self::stringify( implode( ', ', $parts ) );
		}
		if ( $value instanceof \WP_Post ) {
			return $value->post_title;
		}
		return '';
	}
}
