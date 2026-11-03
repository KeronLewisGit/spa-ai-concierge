<?php
/**
 * Builds system and user prompts from a post context array.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Ai;

/**
 * Pure logic. The context array is assembled by ContextBuilder from the post.
 *
 * Context keys: title, post_type, content, excerpt, fields (label => value), site_name,
 * brand_voice, image (filename, title, caption, description) for alt text.
 */
final class PromptBuilder {

	/**
	 * Characters of page content sent per request. Treatment pages are far shorter; the cap
	 * bounds cost if someone runs the assistant on a very long post.
	 */
	public const MAX_CONTENT_CHARS = 12000;

	/**
	 * System prompt for a task.
	 *
	 * @param string               $task    Task name.
	 * @param array<string, mixed> $context Post context.
	 */
	public static function system( string $task, array $context ): string {
		$tool      = Tasks::tool( $task );
		$site_name = trim( (string) ( $context['site_name'] ?? '' ) );
		$voice     = trim( (string) ( $context['brand_voice'] ?? '' ) );

		$lines   = array();
		$lines[] = sprintf(
			'You write website copy for %s, a salon, spa or medical spa. Your output is reviewed and edited by a staff member before anything is published.',
			'' !== $site_name ? $site_name : 'a beauty and wellness business'
		);
		$lines[] = '';
		$lines[] = 'Rules:';
		$lines[] = '- Use only facts found in the page content and fields provided. If a price, duration, number of sessions or result is not given, describe it in general terms or say it is confirmed at consultation. Do not invent numbers.';
		$lines[] = '- Do not promise or guarantee results, and do not make medical claims. Medical treatments should mention that suitability is confirmed by a licensed provider at consultation.';
		$lines[] = '- Plain text only: no HTML, markdown, emoji or quotation marks around the whole answer.';
		$lines[] = '- American English, second person ("you"), warm and clear.';
		if ( '' !== $voice ) {
			$lines[] = '';
			$lines[] = 'Brand voice notes from the business (follow them unless they conflict with the rules above):';
			$lines[] = $voice;
		}
		$lines[] = '';
		$lines[] = sprintf( 'Respond by calling the %s tool exactly once. Do not reply with plain text.', $tool['name'] );

		return implode( "\n", $lines );
	}

	/**
	 * User message for a task. Returns content blocks so an image block can be added later
	 * (see the spa_ai_concierge_user_content filter in ContentAssistant).
	 *
	 * @param string               $task    Task name.
	 * @param array<string, mixed> $context Post context.
	 * @return array<int, array{type: string, text: string}>
	 * @throws \InvalidArgumentException For unknown tasks.
	 */
	public static function user( string $task, array $context ): array {
		$page = self::page_block( $context );

		switch ( $task ) {
			case Tasks::FAQS:
				$instruction = 'Write 4 to 6 FAQs a prospective client would ask before booking the service on this page. Cover different topics (for example what it is, who it suits, what it feels like, downtime or aftercare, results timeline, how to prepare). Answers should be 1 to 3 sentences.';
				break;

			case Tasks::META:
				$instruction = sprintf(
					'Suggest 3 SEO title options (aim for 50 to %1$d characters; include the service name and, where it fits, the business name) and 3 meta descriptions (aim for 140 to %2$d characters; describe the benefit and end with a soft call to action). Make the three options in each set noticeably different.',
					Tasks::TITLE_LIMIT,
					Tasks::DESCRIPTION_LIMIT
				);
				break;

			case Tasks::ALT_TEXT:
				$image       = (array) ( $context['image'] ?? array() );
				$page       .= "\n\n<image>\n" . self::lines(
					array(
						'File name'   => (string) ( $image['filename'] ?? '' ),
						'Title'       => (string) ( $image['title'] ?? '' ),
						'Caption'     => (string) ( $image['caption'] ?? '' ),
						'Description' => (string) ( $image['description'] ?? '' ),
					)
				) . "\n</image>";
				$instruction = sprintf(
					'Write alt text for this page\'s featured image. You cannot see the image, so infer what it most likely shows from the file name, title, caption and page topic, and keep it literal and modest; do not describe details you cannot infer. Under %d characters. Do not begin with "Image of" or "Photo of".',
					Tasks::ALT_LIMIT
				);
				break;

			default:
				throw new \InvalidArgumentException( 'Unknown task' );
		}

		return array(
			array(
				'type' => 'text',
				'text' => $page . "\n\n" . $instruction,
			),
		);
	}

	/**
	 * The page section of the user message.
	 *
	 * @param array<string, mixed> $context Post context.
	 */
	private static function page_block( array $context ): string {
		$content = self::normalise( (string) ( $context['content'] ?? '' ) );
		if ( mb_strlen( $content ) > self::MAX_CONTENT_CHARS ) {
			$content = mb_substr( $content, 0, self::MAX_CONTENT_CHARS ) . ' [content shortened]';
		}

		$out  = "<page>\n";
		$out .= self::lines(
			array(
				'Title'     => (string) ( $context['title'] ?? '' ),
				'Page type' => (string) ( $context['post_type'] ?? '' ),
				'Excerpt'   => self::normalise( (string) ( $context['excerpt'] ?? '' ) ),
			)
		);

		$fields = (array) ( $context['fields'] ?? array() );
		if ( array() !== $fields ) {
			$out .= "\nFields:";
			foreach ( $fields as $label => $value ) {
				$out .= "\n- " . $label . ': ' . self::normalise( (string) $value );
			}
		}

		$out .= "\n\nContent:\n" . ( '' !== $content ? $content : '(no body content yet)' );
		$out .= "\n</page>";

		return $out;
	}

	/**
	 * "Label: value" lines, skipping empty values.
	 *
	 * @param array<string, string> $pairs Label => value.
	 */
	private static function lines( array $pairs ): string {
		$lines = array();
		foreach ( $pairs as $label => $value ) {
			$value = trim( $value );
			if ( '' !== $value ) {
				$lines[] = $label . ': ' . $value;
			}
		}
		return implode( "\n", $lines );
	}

	/**
	 * Strip markup, block comments and shortcodes; collapse whitespace.
	 *
	 * @param string $text Raw text.
	 */
	public static function normalise( string $text ): string {
		$text = (string) preg_replace( '/<!--.*?-->/s', ' ', $text );
		$text = (string) preg_replace( '/\[\/?[a-z_\-]+[^\]]*\]/i', ' ', $text );
		$text = (string) preg_replace( '#<(br|/p|/h[1-6]|/li|/div)\b[^>]*>#i', "\n", $text );
		$text = strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- pure helper, unit-tested without WordPress.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t]+/u', ' ', $text );
		$text = (string) preg_replace( '/\s*\n\s*/u', "\n", $text );
		return trim( $text );
	}
}
