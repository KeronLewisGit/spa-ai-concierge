<?php
/**
 * JSON Schema subset validator.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Tests\Unit;

use KeronLewis\SpaAiConcierge\Ai\JsonValidator;

final class JsonValidatorTest extends TestCase {

	private function schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'items' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 2,
					'items'    => array(
						'type'      => 'string',
						'minLength' => 2,
						'maxLength' => 5,
					),
				),
				'mode'  => array(
					'type' => 'string',
					'enum' => array( 'a', 'b' ),
				),
			),
			'required'             => array( 'items' ),
			'additionalProperties' => false,
		);
	}

	public function test_valid_value_has_no_errors(): void {
		$this->assertSame( array(), JsonValidator::validate( array( 'items' => array( 'ok', 'fine' ), 'mode' => 'a' ), $this->schema() ) );
	}

	public function test_reports_each_problem_with_a_path(): void {
		$errors = JsonValidator::validate( array( 'items' => array( 'x', 'toolong', 'z' ), 'mode' => 'c', 'extra' => 1 ), $this->schema() );

		$this->assertContains( '$.items allows at most 2 items, got 3', $errors );
		$this->assertContains( '$.items[0] is too short (1 characters, minimum 2)', $errors );
		$this->assertContains( '$.items[1] is too long (7 characters, maximum 5)', $errors );
		$this->assertContains( '$.mode is not one of the allowed values', $errors );
		$this->assertContains( '$.extra is not allowed', $errors );
	}

	public function test_distinguishes_lists_from_objects(): void {
		$this->assertSame( array( '$ should be object, got array' ), JsonValidator::validate( array( 'a', 'b' ), $this->schema() ) );
		$this->assertSame( array( '$.items should be array, got object' ), JsonValidator::validate( array( 'items' => array( 'k' => 'v' ) ), $this->schema() ) );
	}

	public function test_missing_required_property(): void {
		$this->assertSame( array( '$.items is required' ), JsonValidator::validate( array( 'mode' => 'a' ), $this->schema() ) );
	}

	public function test_scalar_types(): void {
		$this->assertSame( array(), JsonValidator::validate( 3, array( 'type' => 'integer' ) ) );
		$this->assertNotSame( array(), JsonValidator::validate( '3', array( 'type' => 'integer' ) ) );
		$this->assertSame( array(), JsonValidator::validate( 2.5, array( 'type' => 'number' ) ) );
		$this->assertSame( array(), JsonValidator::validate( true, array( 'type' => 'boolean' ) ) );
	}
}
