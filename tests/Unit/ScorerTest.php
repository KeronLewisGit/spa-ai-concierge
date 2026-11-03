<?php
/**
 * Treatment Finder scoring.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Tests\Unit;

use KeronLewis\SpaAiConcierge\Finder\Scorer;

final class ScorerTest extends TestCase {

	private function treatments(): array {
		$t = static fn( int $id, string $title, array $concerns, array $areas, ?int $downtime, ?int $price ): array => compact( 'id', 'title', 'concerns', 'areas', 'downtime', 'price' );
		return array(
			$t( 1, 'Botox & Neurotoxins', array( 'lines-wrinkles' ), array( 'face' ), 0, 13 ),
			$t( 2, 'Dermal Fillers', array( 'volume-loss', 'lines-wrinkles' ), array( 'face' ), 3, 695 ),
			$t( 3, 'HydraFacial', array( 'dull-skin', 'acne-scarring' ), array( 'face' ), 0, 199 ),
			$t( 4, 'Laser Hair Removal', array( 'unwanted-hair' ), array( 'body', 'face' ), 0, 95 ),
			$t( 5, 'Chemical Peels', array( 'pigmentation', 'dull-skin', 'acne-scarring' ), array( 'face' ), 5, 150 ),
			$t( 6, 'Microneedling', array( 'acne-scarring', 'lines-wrinkles', 'dull-skin' ), array( 'face' ), 3, 325 ),
			$t( 7, 'IV Therapy', array( 'wellness' ), array( 'wellness' ), 0, 165 ),
			$t( 8, 'Body Contouring', array( 'body-contouring' ), array( 'body' ), 0, 350 ),
		);
	}

	private function answers( string $concern, string $area, string $downtime, string $budget ): array {
		return Scorer::normalise_answers( compact( 'concern', 'area', 'downtime', 'budget' ) );
	}

	public function test_normalise_answers_whitelists_values(): void {
		$a = Scorer::normalise_answers(
			array(
				'concern'  => 'Lines-Wrinkles<script>',
				'area'     => 'elbow',
				'downtime' => 'week',
				'budget'   => 'under-500',
			)
		);
		$this->assertSame( 'lines-wrinklesscript', $a['concern'] );
		$this->assertSame( 'any', $a['area'] );
		$this->assertSame( 7, $a['downtime_days'] );
		$this->assertSame( 500, $a['budget_max'] );

		$b = Scorer::normalise_answers( array() );
		$this->assertSame( array( 'any', 'any', -1, -1 ), array( $b['downtime'], $b['budget'], $b['downtime_days'], $b['budget_max'] ) );
	}

	public function test_wrinkles_no_downtime_small_budget_ranks_botox_first(): void {
		$ranked = Scorer::rank( $this->treatments(), $this->answers( 'lines-wrinkles', 'face', 'none', 'under-200' ) );

		$this->assertSame( array( 'Botox & Neurotoxins', 'Microneedling', 'Dermal Fillers' ), array_column( $ranked, 'title' ) );
		$this->assertSame( 100, $ranked[0]['match'], '50 + 20 + 20 + 15 capped at 100%' );
		$this->assertTrue( $ranked[0]['concern_match'] );
		$this->assertContains( 'No downtime', $ranked[0]['reasons'] );
		$this->assertContains( 'Fits your budget', $ranked[0]['reasons'] );
	}

	public function test_downtime_tolerance_reorders_acne_results(): void {
		$none = Scorer::rank( $this->treatments(), $this->answers( 'acne-scarring', 'face', 'none', 'any' ) );
		$week = Scorer::rank( $this->treatments(), $this->answers( 'acne-scarring', 'face', 'week', 'any' ) );

		$this->assertSame( 'HydraFacial', $none[0]['title'], 'peels (5 days) drop when no downtime is allowed' );
		$this->assertSame( array( 'Chemical Peels', 'HydraFacial', 'Microneedling' ), array_column( $week, 'title' ), 'equal scores break on lower price' );

		$peel = $this->treatments()[4];
		$this->assertLessThan(
			Scorer::score( $peel, $this->answers( 'acne-scarring', 'face', 'week', 'any' ) )['score'],
			Scorer::score( $peel, $this->answers( 'acne-scarring', 'face', 'none', 'any' ) )['score']
		);
	}

	public function test_concern_matches_always_rank_above_non_matches(): void {
		$ranked = Scorer::rank( $this->treatments(), $this->answers( 'unwanted-hair', 'body', 'none', 'under-200' ) );

		$this->assertSame( 'Laser Hair Removal', $ranked[0]['title'] );
		$this->assertTrue( $ranked[0]['concern_match'] );
		$this->assertFalse( $ranked[1]['concern_match'] );
		$this->assertCount( 3, $ranked );
	}

	public function test_is_deterministic(): void {
		$answers = $this->answers( 'dull-skin', 'any', 'short', 'under-500' );
		$this->assertSame( Scorer::rank( $this->treatments(), $answers ), Scorer::rank( array_reverse( $this->treatments() ), $answers ) );
	}

	public function test_score_penalises_over_budget_and_downtime(): void {
		$fillers = $this->treatments()[1];
		$score   = Scorer::score( $fillers, $this->answers( 'volume-loss', 'face', 'none', 'under-200' ) );

		// 50 concern + 20 area - 15 downtime (3 days over) - 25 (more than double the budget).
		$this->assertSame( 30, $score['score'] );
		$this->assertSame( array( 'Targets volume loss', 'Treats your face & neck' ), $score['reasons'] );
	}

	public function test_unknown_fields_get_neutral_points(): void {
		$score = Scorer::score(
			array(
				'concerns' => array( 'wellness' ),
				'areas'    => array(),
				'downtime' => null,
				'price'    => null,
			),
			$this->answers( 'wellness', 'body', 'none', 'under-200' )
		);
		$this->assertSame( 65, $score['score'] );
	}

	public function test_no_positive_scores_returns_empty(): void {
		$expensive = array(
			array(
				'id'       => 1,
				'title'    => 'Surgery-level option',
				'concerns' => array( 'other' ),
				'areas'    => array( 'body' ),
				'downtime' => 14,
				'price'    => 5000,
			),
		);
		$this->assertSame( array(), Scorer::rank( $expensive, $this->answers( 'lines-wrinkles', 'face', 'none', 'under-200' ) ) );
	}
}
