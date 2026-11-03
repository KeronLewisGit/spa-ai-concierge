<?php
/**
 * Prompt builder.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Tests\Unit;

use KeronLewis\SpaAiConcierge\Ai\PromptBuilder;
use KeronLewis\SpaAiConcierge\Ai\Tasks;

final class PromptBuilderTest extends TestCase {

	private function context(): array {
		return array(
			'title'       => 'HydraFacial',
			'post_type'   => 'Treatment',
			'content'     => '<!-- wp:paragraph --><p>A deep-cleansing facial.</p><!-- /wp:paragraph -->[spa_faqs]<p>Takes &amp; relaxes.</p>',
			'excerpt'     => 'Glow in an hour.',
			'fields'      => array(
				'Price from' => '$199',
				'Downtime'   => 'None',
			),
			'site_name'   => 'Verbena Aesthetics',
			'brand_voice' => 'Warm and calm. Never say "flawless".',
		);
	}

	public function test_system_prompt_names_business_tool_and_brand_voice(): void {
		$system = PromptBuilder::system( Tasks::FAQS, $this->context() );

		$this->assertStringContainsString( 'Verbena Aesthetics', $system );
		$this->assertStringContainsString( 'record_faqs tool exactly once', $system );
		$this->assertStringContainsString( 'Never say "flawless".', $system );
		$this->assertStringContainsString( 'Do not invent numbers', $system );
	}

	public function test_system_prompt_without_brand_voice_omits_section(): void {
		$context                = $this->context();
		$context['brand_voice'] = '';
		$this->assertStringNotContainsString( 'Brand voice notes', PromptBuilder::system( Tasks::META, $context ) );
	}

	public function test_user_message_contains_clean_page_content_and_fields(): void {
		$blocks = PromptBuilder::user( Tasks::FAQS, $this->context() );
		$text   = $blocks[0]['text'];

		$this->assertSame( 'text', $blocks[0]['type'] );
		$this->assertStringContainsString( "Title: HydraFacial\nPage type: Treatment", $text );
		$this->assertStringContainsString( '- Price from: $199', $text );
		$this->assertStringContainsString( 'A deep-cleansing facial.', $text );
		$this->assertStringContainsString( 'Takes & relaxes.', $text );
		$this->assertStringNotContainsString( 'wp:paragraph', $text );
		$this->assertStringNotContainsString( '[spa_faqs]', $text );
		$this->assertStringContainsString( '4 to 6 FAQs', $text );
	}

	public function test_meta_instruction_mentions_limits(): void {
		$text = PromptBuilder::user( Tasks::META, $this->context() )[0]['text'];
		$this->assertStringContainsString( '50 to 60 characters', $text );
		$this->assertStringContainsString( '140 to 155 characters', $text );
	}

	public function test_alt_text_includes_image_details(): void {
		$context          = $this->context();
		$context['image'] = array(
			'filename' => 'hydrafacial-room.webp',
			'caption'  => '',
			'title'    => 'Treatment room',
		);
		$text = PromptBuilder::user( Tasks::ALT_TEXT, $context )[0]['text'];

		$this->assertStringContainsString( "<image>\nFile name: hydrafacial-room.webp\nTitle: Treatment room\n</image>", $text );
		$this->assertStringContainsString( 'You cannot see the image', $text );
	}

	public function test_long_content_is_capped(): void {
		$context            = $this->context();
		$context['content'] = str_repeat( 'word ', 5000 );
		$text               = PromptBuilder::user( Tasks::FAQS, $context )[0]['text'];
		$this->assertStringContainsString( '[content shortened]', $text );
		$this->assertLessThan( PromptBuilder::MAX_CONTENT_CHARS + 1500, strlen( $text ) );
	}

	public function test_empty_content_is_marked(): void {
		$context            = $this->context();
		$context['content'] = '';
		$this->assertStringContainsString( '(no body content yet)', PromptBuilder::user( Tasks::FAQS, $context )[0]['text'] );
	}
}
