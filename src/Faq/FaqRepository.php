<?php
/**
 * Stores reviewed FAQs in post meta.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Faq;

/**
 * FAQs are saved only from the editor meta box after a person has reviewed them.
 */
final class FaqRepository {

	public const META_KEY    = '_spa_ai_faqs';
	public const SOURCE_KEY  = '_spa_ai_faqs_source';
	private const MAX_ITEMS  = 20;

	/**
	 * Saved FAQs for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function get( int $post_id ): array {
		$faqs = get_post_meta( $post_id, self::META_KEY, true );
		return is_array( $faqs ) ? self::sanitize( $faqs ) : array();
	}

	/**
	 * Save FAQs. An empty list deletes the meta.
	 *
	 * @param int                     $post_id Post ID.
	 * @param array<int, mixed>       $faqs    Raw rows.
	 * @param string                  $source  manual, ai, demo or theme.
	 */
	public static function save( int $post_id, array $faqs, string $source = 'manual' ): void {
		$clean = self::sanitize( $faqs );
		if ( array() === $clean ) {
			delete_post_meta( $post_id, self::META_KEY );
			delete_post_meta( $post_id, self::SOURCE_KEY );
			return;
		}
		update_post_meta( $post_id, self::META_KEY, $clean );
		update_post_meta( $post_id, self::SOURCE_KEY, in_array( $source, array( 'manual', 'ai', 'demo', 'theme' ), true ) ? $source : 'manual' );
	}

	/**
	 * Sanitize rows: plain-text question, answer with basic inline markup allowed.
	 *
	 * @param array<int, mixed> $faqs Raw rows.
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function sanitize( array $faqs ): array {
		$clean = array();
		foreach ( $faqs as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$question = sanitize_text_field( (string) ( $row['question'] ?? '' ) );
			$answer   = trim( wp_kses( (string) ( $row['answer'] ?? '' ), self::allowed_answer_html() ) );
			if ( '' === $question || '' === $answer ) {
				continue;
			}
			$clean[] = array(
				'question' => mb_substr( $question, 0, 300 ),
				'answer'   => mb_substr( $answer, 0, 2000 ),
			);
			if ( count( $clean ) >= self::MAX_ITEMS ) {
				break;
			}
		}
		return $clean;
	}

	/**
	 * Inline tags allowed in answers.
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_answer_html(): array {
		return array(
			'a'      => array(
				'href' => true,
			),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);
	}
}
