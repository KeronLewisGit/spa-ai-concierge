<?php
/**
 * Demo mode output.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Tests\Unit;

use KeronLewis\SpaAiConcierge\Ai\DemoResponder;
use KeronLewis\SpaAiConcierge\Ai\ResponseParser;
use KeronLewis\SpaAiConcierge\Ai\Tasks;

final class DemoResponderTest extends TestCase {

	public static function titles(): array {
		return array(
			array( 'Botox & Neurotoxins', 'frown lines' ),
			array( 'Dermal Fillers', 'volume' ),
			array( 'HydraFacial', 'serums' ),
			array( 'Laser Hair Removal', 'follicle' ),
			array( 'Chemical Peels', 'sun spots' ),
			array( 'Microneedling', 'collagen' ),
			array( 'IV Therapy', 'IV line' ),
			array( 'Body Contouring', 'stubborn fat' ),
			array( 'Brow Lamination', 'Brow Lamination' ),
		);
	}

	/**
	 * @dataProvider titles
	 */
	public function test_faqs_are_specific_to_the_title_and_pass_validation( string $title, string $expected ): void {
		$context = array(
			'title'     => $title,
			'site_name' => 'Verbena Aesthetics',
		);
		$raw     = DemoResponder::respond( Tasks::FAQS, $context );
		$valid   = ResponseParser::validate( $raw, Tasks::FAQS );

		$this->assertGreaterThanOrEqual( 4, count( $valid['faqs'] ) );
		$this->assertLessThanOrEqual( 6, count( $valid['faqs'] ) );
		$this->assertStringContainsString( $expected, json_encode( $valid['faqs'] ) );
	}

	public function test_meta_uses_title_and_site_and_shows_a_length_warning(): void {
		$valid = ResponseParser::validate(
			DemoResponder::respond(
				Tasks::META,
				array(
					'title'     => 'Chemical Peels',
					'site_name' => 'Verbena Aesthetics',
				)
			),
			Tasks::META
		);

		$this->assertSame( 'Chemical Peels | Verbena Aesthetics', $valid['titles'][0]['text'] );
		$this->assertStringContainsString( 'chemical peels', $valid['descriptions'][0]['text'] );
		$this->assertContains( true, array_column( $valid['descriptions'], 'over' ), 'one demo description runs long so the warning shows' );
	}

	public function test_lower_title_keeps_brand_capitals(): void {
		$meta = DemoResponder::meta( array( 'title' => 'HydraFacial Platinum', 'site_name' => 'X' ) );
		$this->assertStringContainsString( 'about HydraFacial platinum?', $meta['descriptions'][0] );
	}

	public function test_alt_text_prefers_caption_then_filename(): void {
		$this->assertSame( 'Calm treatment lounge', DemoResponder::alt_text( array( 'title' => 'IV Therapy', 'image' => array( 'caption' => 'Calm treatment lounge' ) ) ) );
		$this->assertSame(
			'Nurse preparing iv drip during an IV therapy appointment',
			DemoResponder::alt_text( array( 'title' => 'IV Therapy', 'image' => array( 'filename' => 'nurse-preparing-iv-drip-1024x683.jpg' ) ) )
		);
		$this->assertSame(
			'Client during a chemical peel appointment in a calm treatment room',
			DemoResponder::alt_text( array( 'title' => 'Chemical Peels', 'image' => array( 'filename' => 'treatment-peel.webp' ) ) )
		);
		$this->assertSame(
			'Client during a HydraFacial appointment in a calm treatment room',
			DemoResponder::alt_text( array( 'title' => 'HydraFacial', 'image' => array( 'filename' => 'IMG_2041.JPG' ) ) )
		);
	}

	public function test_faq_questions_read_naturally_for_plural_titles(): void {
		$faqs = DemoResponder::faqs( array( 'title' => 'Chemical Peels', 'site_name' => 'X' ) );
		$this->assertSame( 'What can chemical peels do for my skin?', $faqs[0]['question'] );
		$generic = DemoResponder::faqs( array( 'title' => 'Brow Laminations', 'site_name' => 'X' ) );
		$this->assertSame( 'Who is a good candidate for brow laminations?', $generic[1]['question'] );
	}

	public function test_humanise_filename(): void {
		$this->assertSame( 'treatment hydrafacial', DemoResponder::humanise_filename( 'treatment-hydrafacial.webp' ) );
		$this->assertSame( '', DemoResponder::humanise_filename( 'DSC0042.jpg' ) );
		$this->assertSame( 'laser room', DemoResponder::humanise_filename( 'laser_room-scaled.jpg' ) );
	}
}
