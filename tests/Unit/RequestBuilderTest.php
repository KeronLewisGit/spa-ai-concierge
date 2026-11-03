<?php
/**
 * Messages API request bodies.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Tests\Unit;

use KeronLewis\SpaAiConcierge\Ai\RequestBuilder;
use KeronLewis\SpaAiConcierge\Ai\Tasks;

final class RequestBuilderTest extends TestCase {

	public function test_default_model_forces_the_task_tool(): void {
		$body = RequestBuilder::build( 'claude-haiku-4-5', 1024, 'system text', array( array( 'type' => 'text', 'text' => 'hi' ) ), Tasks::FAQS );

		$this->assertSame( 'claude-haiku-4-5', $body['model'] );
		$this->assertSame( 1024, $body['max_tokens'] );
		$this->assertSame( 'system text', $body['system'] );
		$this->assertSame( 'user', $body['messages'][0]['role'] );
		$this->assertSame( array( 'type' => 'tool', 'name' => 'record_faqs' ), $body['tool_choice'] );
		$this->assertSame( 'record_faqs', $body['tools'][0]['name'] );
		$this->assertArrayNotHasKey( 'strict', $body['tools'][0] );
		$this->assertSame( 4, $body['tools'][0]['input_schema']['properties']['faqs']['minItems'] );
		$this->assertArrayNotHasKey( 'temperature', $body );
	}

	public function test_models_without_forced_tool_choice_use_auto_and_strict_schema(): void {
		$body = RequestBuilder::build( 'claude-opus-5-5', 1024, 's', 'u', Tasks::META );

		$this->assertSame( array( 'type' => 'auto' ), $body['tool_choice'] );
		$this->assertTrue( $body['tools'][0]['strict'] );
		$titles = $body['tools'][0]['input_schema']['properties']['titles'];
		$this->assertArrayNotHasKey( 'minItems', $titles, 'unsupported strict-mode keywords are removed' );
		$this->assertArrayNotHasKey( 'maxLength', $titles['items'] );
		$this->assertFalse( $body['tools'][0]['input_schema']['additionalProperties'] );
	}

	public function test_forced_flag_overrides_model_detection(): void {
		$body = RequestBuilder::build( 'claude-haiku-4-5', 512, 's', 'u', Tasks::ALT_TEXT, false );
		$this->assertSame( array( 'type' => 'auto' ), $body['tool_choice'] );
	}

	public function test_forced_tool_choice_support_by_model(): void {
		$this->assertTrue( RequestBuilder::supports_forced_tool_choice( 'claude-haiku-4-5' ) );
		$this->assertTrue( RequestBuilder::supports_forced_tool_choice( 'claude-sonnet-4-6' ) );
		$this->assertFalse( RequestBuilder::supports_forced_tool_choice( 'claude-sonnet-5-5' ) );
		$this->assertFalse( RequestBuilder::supports_forced_tool_choice( 'claude-fable-5-1' ) );
	}

	public function test_headers(): void {
		$this->assertSame(
			array(
				'x-api-key'         => 'sk-test',
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			RequestBuilder::headers( 'sk-test' )
		);
		$this->assertSame( 'https://api.anthropic.com/v1/messages', RequestBuilder::ENDPOINT );
	}

	public function test_body_encodes_to_the_documented_shape(): void {
		$json = json_encode( RequestBuilder::build( 'claude-haiku-4-5', 1024, 's', 'u', Tasks::FAQS ) );
		$back = json_decode( (string) $json, true );
		$this->assertSame( array( 'model', 'max_tokens', 'system', 'messages', 'tools', 'tool_choice' ), array_keys( $back ) );
		$this->assertSame( 'object', $back['tools'][0]['input_schema']['type'] );
	}
}
