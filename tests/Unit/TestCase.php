<?php
/**
 * Base test case with Brain Monkey set up and WordPress i18n/escaping stubbed.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

abstract class TestCase extends \PHPUnit\Framework\TestCase {

	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Decoded fixture.
	 *
	 * @param string $name File name without extension.
	 * @return array<string, mixed>
	 */
	protected function fixture( string $name ): array {
		return json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/' . $name . '.json' ), true );
	}
}
