<?php
/**
 * Turns a Messages API response body into validated task output.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Ai;

/**
 * Pure logic: no WordPress calls, so it is unit-tested against recorded fixtures.
 */
final class ResponseParser {

	/**
	 * Decode a raw HTTP body and parse it.
	 *
	 * @param string $body Raw JSON body.
	 * @param string $task Task name.
	 * @return array<string, mixed> Clean task output.
	 * @throws AiException On anything unexpected.
	 */
	public static function parse_body( string $body, string $task ): array {
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			throw new AiException( 'invalid_response', __( 'The AI service returned a response that was not valid JSON.', 'spa-ai-concierge' ), 502, substr( $body, 0, 500 ) );
		}
		return self::parse( $decoded, $task );
	}

	/**
	 * Extract the tool input for a task from a decoded Messages API response and validate it.
	 *
	 * @param array<string, mixed> $response Decoded response.
	 * @param string               $task     Task name.
	 * @return array<string, mixed> Clean task output.
	 * @throws AiException On refusal, truncation, missing tool call or schema mismatch.
	 */
	public static function parse( array $response, string $task ): array {
		if ( isset( $response['type'] ) && 'error' === $response['type'] ) {
			$message = (string) ( $response['error']['message'] ?? 'Unknown error' );
			throw new AiException( 'api_error', __( 'The AI service returned an error. Try again in a minute.', 'spa-ai-concierge' ), 502, $message );
		}

		$stop_reason = (string) ( $response['stop_reason'] ?? '' );
		if ( 'refusal' === $stop_reason ) {
			throw new AiException( 'refusal', __( 'The model declined to write this content. Edit the page text and try again, or write it by hand.', 'spa-ai-concierge' ), 422 );
		}
		if ( 'max_tokens' === $stop_reason ) {
			throw new AiException( 'truncated', __( 'The response was cut off before it finished. Raise “Max output tokens” in Settings → Spa AI Concierge and try again.', 'spa-ai-concierge' ), 502 );
		}

		$content = $response['content'] ?? null;
		if ( ! is_array( $content ) || array() === $content ) {
			throw new AiException( 'empty_response', __( 'The AI service returned an empty response.', 'spa-ai-concierge' ), 502 );
		}

		$tool  = Tasks::tool( $task );
		$input = self::find_tool_input( $content, $tool['name'] );

		if ( null === $input ) {
			// Fallback for models that answered in text: accept a single JSON object in the text.
			$input = self::find_json_in_text( $content );
		}
		if ( null === $input ) {
			throw new AiException( 'no_structured_output', __( 'The AI response did not include the expected structured data. Nothing was changed. Try again.', 'spa-ai-concierge' ), 502 );
		}

		return self::validate( $input, $task );
	}

	/**
	 * Validate and clean task output (also used for demo output so both paths share one gate).
	 *
	 * @param mixed  $input Decoded tool input.
	 * @param string $task  Task name.
	 * @return array<string, mixed>
	 * @throws AiException When the input does not match the schema.
	 */
	public static function validate( $input, string $task ): array {
		$tool   = Tasks::tool( $task );
		$errors = JsonValidator::validate( $input, $tool['input_schema'] );
		if ( array() !== $errors ) {
			throw new AiException(
				'invalid_output',
				__( 'The AI response did not match the expected format, so it was rejected. Nothing was changed. Try again.', 'spa-ai-concierge' ),
				502,
				implode( '; ', array_slice( $errors, 0, 5 ) )
			);
		}

		switch ( $task ) {
			case Tasks::FAQS:
				$faqs = self::clean_faqs( $input['faqs'] );
				if ( count( $faqs ) < 4 ) {
					throw new AiException( 'invalid_output', __( 'The AI returned too few distinct FAQs. Nothing was changed. Try again.', 'spa-ai-concierge' ), 502, 'duplicates removed below 4' );
				}
				return array( 'faqs' => $faqs );

			case Tasks::META:
				return Tasks::annotate_meta(
					array_map( array( self::class, 'clean_text' ), $input['titles'] ),
					array_map( array( self::class, 'clean_text' ), $input['descriptions'] )
				);

			case Tasks::ALT_TEXT:
				$alt = self::clean_text( $input['alt_text'] );
				$alt = (string) preg_replace( '/^(an?\s+)?(image|photo|picture)\s+of\s+/i', '', $alt );
				$alt = ucfirst( $alt );
				return array(
					'alt_text' => $alt,
					'length'   => mb_strlen( $alt ),
					'over'     => mb_strlen( $alt ) > Tasks::ALT_LIMIT,
				);
		}

		return $input;
	}

	/**
	 * First tool_use block with the given name.
	 *
	 * @param array<int, mixed> $content Content blocks.
	 * @param string            $name    Tool name.
	 * @return array<string, mixed>|null
	 */
	private static function find_tool_input( array $content, string $name ): ?array {
		foreach ( $content as $block ) {
			if ( is_array( $block ) && 'tool_use' === ( $block['type'] ?? '' ) && ( $block['name'] ?? '' ) === $name ) {
				return is_array( $block['input'] ?? null ) ? $block['input'] : null;
			}
		}
		return null;
	}

	/**
	 * Parse a JSON object out of the concatenated text blocks, if there is one.
	 *
	 * @param array<int, mixed> $content Content blocks.
	 * @return array<string, mixed>|null
	 */
	private static function find_json_in_text( array $content ): ?array {
		$text = '';
		foreach ( $content as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}
		$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );
		return is_array( $decoded ) && ! array_is_list( $decoded ) ? $decoded : null;
	}

	/**
	 * Trim, strip markup and de-duplicate FAQs.
	 *
	 * @param array<int, array{question: string, answer: string}> $faqs Raw FAQs.
	 * @return array<int, array{question: string, answer: string}>
	 */
	private static function clean_faqs( array $faqs ): array {
		$seen  = array();
		$clean = array();
		foreach ( $faqs as $faq ) {
			$question = self::clean_text( $faq['question'] );
			$answer   = self::clean_text( $faq['answer'] );
			$key      = strtolower( preg_replace( '/[^a-z0-9]+/i', '', $question ) );
			if ( '' === $key || isset( $seen[ $key ] ) || '' === $answer ) {
				continue;
			}
			$seen[ $key ] = true;
			$clean[]      = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}
		return $clean;
	}

	/**
	 * Plain text: no tags, no markdown emphasis, single spaces.
	 *
	 * @param string $text Raw text.
	 */
	public static function clean_text( string $text ): string {
		$text = strip_tags( $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- pure helper, runs without WordPress in unit tests.
		$text = str_replace( array( '**', '__', '`' ), '', $text );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}
}
