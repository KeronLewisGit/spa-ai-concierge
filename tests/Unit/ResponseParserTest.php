<?php
/**
 * Response parser and validator against recorded Messages API responses.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Tests\Unit;

use KeronLewis\SpaAiConcierge\Ai\AiException;
use KeronLewis\SpaAiConcierge\Ai\ResponseParser;
use KeronLewis\SpaAiConcierge\Ai\Tasks;

final class ResponseParserTest extends TestCase {

	public function test_extracts_faqs_from_tool_use_block_and_cleans_markup(): void {
		$result = ResponseParser::parse( $this->fixture( 'faqs-tool-use' ), Tasks::FAQS );

		$this->assertCount( 5, $result['faqs'] );
		$this->assertSame( 'How long does a HydraFacial take?', $result['faqs'][0]['question'] );
		$this->assertSame( 'Is it suitable for sensitive skin?', $result['faqs'][2]['question'], 'markdown emphasis is stripped' );
		$this->assertStringNotContainsString( '<p>', $result['faqs'][2]['answer'], 'HTML is stripped' );
	}

	public function test_falls_back_to_json_in_a_text_block(): void {
		$result = ResponseParser::parse( $this->fixture( 'faqs-text-json' ), Tasks::FAQS );
		$this->assertCount( 4, $result['faqs'] );
		$this->assertSame( 'What is microneedling?', $result['faqs'][0]['question'] );
	}

	public function test_parse_body_decodes_raw_json(): void {
		$body   = (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/faqs-tool-use.json' );
		$result = ResponseParser::parse_body( $body, Tasks::FAQS );
		$this->assertCount( 5, $result['faqs'] );
	}

	public function test_rejects_non_json_body(): void {
		$this->expectException( AiException::class );
		try {
			ResponseParser::parse_body( '<html>502 Bad Gateway</html>', Tasks::FAQS );
		} catch ( AiException $e ) {
			$this->assertSame( 'invalid_response', $e->error_code() );
			throw $e;
		}
	}

	/**
	 * @dataProvider rejected_fixtures
	 */
	public function test_rejects_bad_responses_with_a_clear_code( string $fixture, string $task, string $code ): void {
		try {
			ResponseParser::parse( $this->fixture( $fixture ), $task );
			$this->fail( 'Expected AiException' );
		} catch ( AiException $e ) {
			$this->assertSame( $code, $e->error_code() );
			$this->assertNotSame( '', $e->getMessage() );
		}
	}

	public static function rejected_fixtures(): array {
		return array(
			'fewer than four FAQs'         => array( 'faqs-too-few', Tasks::FAQS, 'invalid_output' ),
			'duplicates leave too few'     => array( 'faqs-duplicates', Tasks::FAQS, 'invalid_output' ),
			'extra and missing properties' => array( 'wrong-shape', Tasks::FAQS, 'invalid_output' ),
			'refusal stop reason'          => array( 'refusal', Tasks::FAQS, 'refusal' ),
			'truncated by max_tokens'      => array( 'max-tokens', Tasks::FAQS, 'truncated' ),
			'API error object'             => array( 'api-error', Tasks::FAQS, 'api_error' ),
			'tool for another task'        => array( 'alt-tool-use', Tasks::FAQS, 'no_structured_output' ),
		);
	}

	public function test_invalid_output_detail_lists_schema_errors_for_logs(): void {
		try {
			ResponseParser::parse( $this->fixture( 'wrong-shape' ), Tasks::FAQS );
			$this->fail( 'Expected AiException' );
		} catch ( AiException $e ) {
			$this->assertStringContainsString( '$.faqs[0].source is not allowed', $e->detail() );
			$this->assertStringContainsString( '$.faqs[1].question is required', $e->detail() );
			$this->assertStringNotContainsString( 'faqs[0].source', $e->getMessage(), 'editor message stays generic' );
		}
	}

	public function test_meta_options_are_annotated_with_lengths_and_warnings(): void {
		$result = ResponseParser::parse( $this->fixture( 'meta-tool-use' ), Tasks::META );

		$this->assertCount( 3, $result['titles'] );
		$this->assertCount( 3, $result['descriptions'] );
		$this->assertFalse( $result['titles'][0]['over'] );
		$this->assertTrue( $result['titles'][2]['over'], 'title longer than 60 is flagged' );
		$this->assertSame( mb_strlen( $result['descriptions'][2]['text'] ), $result['descriptions'][2]['length'] );
		$this->assertTrue( $result['descriptions'][2]['over'], 'description longer than 155 is flagged' );
		$this->assertSame( array( 'title' => 60, 'description' => 155 ), $result['limits'] );
	}

	public function test_alt_text_drops_image_of_prefix(): void {
		$result = ResponseParser::parse( $this->fixture( 'alt-tool-use' ), Tasks::ALT_TEXT );
		$this->assertSame( 'A nurse preparing an IV drip in a softly lit lounge', $result['alt_text'] );
		$this->assertFalse( $result['over'] );
	}

	public function test_meta_requires_exactly_three_options(): void {
		$this->expectException( AiException::class );
		ResponseParser::validate(
			array(
				'titles'       => array( 'Only one title option here' ),
				'descriptions' => array( str_repeat( 'Description text. ', 5 ), str_repeat( 'Another one here. ', 5 ), str_repeat( 'Third option now. ', 5 ) ),
			),
			Tasks::META
		);
	}
}
