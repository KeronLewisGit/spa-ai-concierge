<?php
/**
 * Deterministic, rule-based treatment scoring for the Treatment Finder.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Finder;

/**
 * Pure logic. No AI call happens on the public front end, so there is no cost or abuse risk
 * and the same answers always give the same ranking.
 *
 * Points:
 *   concern match      +50  (the deciding factor)
 *   area match         +20  (+10 if the visitor picked "not sure", +5 if the treatment has no area)
 *   within downtime    +20  (over tolerance: -5 per extra day, max -30; unknown downtime: +5)
 *   within budget      +15  (over budget: -10, or -25 if more than double; unknown price: +5)
 * Maximum: 105.
 */
final class Scorer {

	public const MAX_SCORE = 105;

	/**
	 * Normalise raw answers (from GET or REST) into scoring inputs. Unknown values become "any".
	 *
	 * @param array<string, mixed> $raw Raw answers: concern, area, downtime, budget.
	 * @return array{concern: string, area: string, downtime: string, budget: string, downtime_days: int, budget_max: int}
	 */
	public static function normalise_answers( array $raw ): array {
		$downtime_options = Catalog::downtime_options();
		$budget_options   = Catalog::budget_options();

		$concern  = self::slug( $raw['concern'] ?? '' );
		$area     = self::slug( $raw['area'] ?? '' );
		$downtime = self::slug( $raw['downtime'] ?? '' );
		$budget   = self::slug( $raw['budget'] ?? '' );

		if ( '' === $area || ( 'any' !== $area && ! isset( Catalog::areas()[ $area ] ) ) ) {
			$area = 'any';
		}
		if ( ! isset( $downtime_options[ $downtime ] ) ) {
			$downtime = 'any';
		}
		if ( ! isset( $budget_options[ $budget ] ) ) {
			$budget = 'any';
		}

		return array(
			'concern'       => $concern,
			'area'          => $area,
			'downtime'      => $downtime,
			'budget'        => $budget,
			'downtime_days' => $downtime_options[ $downtime ]['days'],
			'budget_max'    => $budget_options[ $budget ]['max'],
		);
	}

	/**
	 * Score one treatment.
	 *
	 * @param array{concerns: string[], areas: string[], downtime: int|null, price: int|null} $treatment Treatment data.
	 * @param array{concern: string, area: string, downtime_days: int, budget_max: int}       $answers   Normalised answers.
	 * @return array{score: int, concern_match: bool, reasons: string[]}
	 */
	public static function score( array $treatment, array $answers ): array {
		$score   = 0;
		$reasons = array();

		$concern_match = '' !== $answers['concern'] && in_array( $answers['concern'], $treatment['concerns'], true );
		if ( $concern_match ) {
			$score    += 50;
			$concerns  = Catalog::concerns();
			$reasons[] = sprintf(
				/* translators: %s: concern label, e.g. "Fine lines & wrinkles" */
				__( 'Targets %s', 'spa-ai-concierge' ),
				mb_strtolower( $concerns[ $answers['concern'] ] ?? str_replace( '-', ' ', $answers['concern'] ) )
			);
		}

		if ( array() === $treatment['areas'] ) {
			$score += 5;
		} elseif ( 'any' === $answers['area'] ) {
			$score += 10;
		} elseif ( in_array( $answers['area'], $treatment['areas'], true ) ) {
			$score    += 20;
			$areas     = Catalog::areas();
			$reasons[] = sprintf(
				/* translators: %s: area label, e.g. "Face & neck" */
				__( 'Treats your %s', 'spa-ai-concierge' ),
				mb_strtolower( $areas[ $answers['area'] ] ?? $answers['area'] )
			);
		}

		$downtime = $treatment['downtime'];
		if ( null === $downtime ) {
			$score += 5;
		} elseif ( $answers['downtime_days'] < 0 || $downtime <= $answers['downtime_days'] ) {
			$score    += 20;
			$reasons[] = 0 === $downtime
				? __( 'No downtime', 'spa-ai-concierge' )
				/* translators: %d: number of days */
				: sprintf( _n( 'About %d day of downtime', 'About %d days of downtime', $downtime, 'spa-ai-concierge' ), $downtime );
		} else {
			$score -= min( 30, 5 * ( $downtime - $answers['downtime_days'] ) );
		}

		$price = $treatment['price'];
		if ( null === $price ) {
			$score += 5;
		} elseif ( $answers['budget_max'] < 0 || $price <= $answers['budget_max'] ) {
			$score    += 15;
			$reasons[] = $answers['budget_max'] < 0 ? '' : __( 'Fits your budget', 'spa-ai-concierge' );
		} else {
			$score -= $price > 2 * $answers['budget_max'] ? 25 : 10;
		}

		return array(
			'score'         => $score,
			'concern_match' => $concern_match,
			'reasons'       => array_values( array_filter( $reasons ) ),
		);
	}

	/**
	 * Rank treatments and return the top N with their scores.
	 *
	 * Treatments that match the concern always rank above those that do not. Ties break on
	 * lower price, then title, so results are stable.
	 *
	 * @param array<int, array<string, mixed>> $treatments Treatments (id, title, concerns, areas, downtime, price, …).
	 * @param array<string, mixed>             $answers    Normalised answers.
	 * @param int                              $limit      How many to return.
	 * @return array<int, array<string, mixed>> Treatments with score, match (0-100), concern_match and reasons added.
	 */
	public static function rank( array $treatments, array $answers, int $limit = 3 ): array {
		$scored = array();
		foreach ( $treatments as $treatment ) {
			$result = self::score( $treatment, $answers );
			if ( $result['score'] <= 0 ) {
				continue;
			}
			$scored[] = array_merge(
				$treatment,
				$result,
				array( 'match' => (int) round( 100 * min( self::MAX_SCORE, $result['score'] ) / self::MAX_SCORE ) )
			);
		}

		usort(
			$scored,
			static function ( array $a, array $b ): int {
				return array( $b['concern_match'], $b['score'], $a['price'] ?? PHP_INT_MAX, (string) $a['title'] )
					<=> array( $a['concern_match'], $a['score'], $b['price'] ?? PHP_INT_MAX, (string) $b['title'] );
			}
		);

		return array_slice( $scored, 0, max( 1, $limit ) );
	}

	/**
	 * Lower-case slug without WordPress (keeps this class testable in isolation).
	 *
	 * @param mixed $value Raw value.
	 */
	private static function slug( $value ): string {
		return is_scalar( $value ) ? substr( (string) preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $value ) ), 0, 60 ) : '';
	}
}
