<?php
/**
 * Duplicate FAQPage / meta description removal.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Tests\Unit;

use KeronLewis\SpaAiConcierge\Support\OutputGuard;

final class OutputGuardTest extends TestCase {

	private const OURS_SCHEMA = '<script type="application/ld+json" class="spa-ai-concierge-faq-schema">{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[]}</script>';
	private const OURS_META   = '<meta name="description" content="Ours" data-spa-ai-concierge>';

	public function test_keeps_our_output_when_nothing_else_prints_it(): void {
		$html = '<html><head>' . self::OURS_META . '</head><body>' . self::OURS_SCHEMA . '</body></html>';
		$this->assertSame( $html, OutputGuard::dedupe( $html ) );
	}

	public function test_removes_our_schema_when_theme_graph_has_faq_page(): void {
		$theme = '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"WebPage"},{"@type": "FAQPage","mainEntity":[]}]}</script>';
		$html  = '<head>' . $theme . '</head><body>' . self::OURS_SCHEMA . '</body>';
		$out   = OutputGuard::dedupe( $html );

		$this->assertStringNotContainsString( 'spa-ai-concierge-faq-schema', $out );
		$this->assertStringContainsString( '"FAQPage"', $out );
	}

	public function test_detects_microdata_and_type_arrays(): void {
		$this->assertTrue( OutputGuard::contains_faq_page( '<div itemscope itemtype="https://schema.org/FAQPage">' ) );
		$this->assertTrue( OutputGuard::contains_faq_page( '{"@type":["WebPage","FAQPage"]}' ) );
		$this->assertFalse( OutputGuard::contains_faq_page( '{"@type":"WebPage","name":"FAQPage tips"}' ) );
	}

	public function test_removes_our_meta_description_when_another_exists(): void {
		$html = '<head><meta name="description" content="Theme">' . self::OURS_META . '</head>';
		$this->assertSame( '<head><meta name="description" content="Theme"></head>', OutputGuard::dedupe( $html ) );
	}
}
