<?php
/**
 * Catalog matching and theme value parsing.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Tests\Unit;

use KeronLewis\SpaAiConcierge\Finder\Catalog;
use KeronLewis\SpaAiConcierge\Finder\TreatmentFields;

final class FinderDataTest extends TestCase {

	public function test_profiles_match_common_treatment_titles(): void {
		$cases = array(
			'Botox & Neurotoxins'     => 'neurotoxin',
			'Dysport'                 => 'neurotoxin',
			'Dermal Fillers'          => 'filler',
			'Lip Filler'              => 'filler',
			'HydraFacial'             => 'hydrafacial',
			'Hydra-Facial Deluxe'     => 'hydrafacial',
			'Laser Hair Removal'      => 'laser-hair',
			'Chemical Peels'          => 'chemical-peel',
			'Microneedling'           => 'microneedling',
			'RF Microneedling'        => 'microneedling',
			'IV Therapy'              => 'iv-therapy',
			'NAD+ IV Drip'            => 'iv-therapy',
			'Body Contouring'         => 'body-contouring',
			'CoolSculpting'           => 'body-contouring',
			'Gel Manicure'            => null,
		);
		foreach ( $cases as $title => $expected ) {
			$this->assertSame( $expected, Catalog::match_profile( $title ), $title );
		}
	}

	public function test_every_profile_uses_known_concerns_and_areas(): void {
		foreach ( Catalog::profiles() as $key => $profile ) {
			$this->assertSame( array(), array_diff( $profile['concerns'], array_keys( Catalog::concerns() ) ), $key );
			$this->assertSame( array(), array_diff( $profile['areas'], array_keys( Catalog::areas() ) ), $key );
		}
	}

	public function test_parse_days_reads_free_text_downtime(): void {
		$cases = array(
			'None; avoid exercise for 24 hours' => 0,
			'None'                              => 0,
			'1 to 3 days of possible swelling'  => 3,
			'Mild redness for a few hours'      => 0,
			'0 to 5 days of flaking'            => 5,
			'1-2 weeks'                         => 14,
			'48 hours'                          => 2,
			'2'                                 => 2,
			'Varies'                            => null,
			''                                  => null,
		);
		foreach ( $cases as $text => $days ) {
			$this->assertSame( $days, TreatmentFields::parse_days( (string) $text ), (string) $text );
		}
		$this->assertSame( 4, TreatmentFields::parse_days( 4 ) );
	}

	public function test_parse_number_reads_prices(): void {
		$this->assertSame( 350, TreatmentFields::parse_number( 'From $350' ) );
		$this->assertSame( 1200, TreatmentFields::parse_number( '$1,200 per area' ) );
		$this->assertSame( 13, TreatmentFields::parse_number( 13 ) );
		$this->assertNull( TreatmentFields::parse_number( 'Ask us' ) );
		$this->assertNull( TreatmentFields::parse_number( array( 1 ) ) );
	}
}
