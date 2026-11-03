<?php
/**
 * Reference data: concerns, areas, quiz options and well-known treatment profiles.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Finder;

/**
 * Pure data used by the finder, the seeder and demo mode.
 */
final class Catalog {

	/**
	 * Default Concern terms (slug => label). The taxonomy is editable; these seed it.
	 *
	 * @return array<string, string>
	 */
	public static function concerns(): array {
		return array(
			'lines-wrinkles'  => __( 'Fine lines & wrinkles', 'spa-ai-concierge' ),
			'volume-loss'     => __( 'Volume loss', 'spa-ai-concierge' ),
			'dull-skin'       => __( 'Dull or uneven skin', 'spa-ai-concierge' ),
			'acne-scarring'   => __( 'Acne & scarring', 'spa-ai-concierge' ),
			'pigmentation'    => __( 'Sun damage & pigmentation', 'spa-ai-concierge' ),
			'unwanted-hair'   => __( 'Unwanted hair', 'spa-ai-concierge' ),
			'body-contouring' => __( 'Stubborn fat & body shape', 'spa-ai-concierge' ),
			'wellness'        => __( 'Energy & wellness', 'spa-ai-concierge' ),
		);
	}

	/**
	 * Treatment areas (slug => label).
	 *
	 * @return array<string, string>
	 */
	public static function areas(): array {
		return array(
			'face'     => __( 'Face & neck', 'spa-ai-concierge' ),
			'body'     => __( 'Body', 'spa-ai-concierge' ),
			'wellness' => __( 'Whole-body wellness', 'spa-ai-concierge' ),
		);
	}

	/**
	 * Downtime tolerance options (value => [label, max days]). -1 means any.
	 *
	 * @return array<string, array{label: string, days: int}>
	 */
	public static function downtime_options(): array {
		return array(
			'none' => array(
				'label' => __( 'None, I need to get straight back to my day', 'spa-ai-concierge' ),
				'days'  => 0,
			),
			'short' => array(
				'label' => __( 'A day or two is fine', 'spa-ai-concierge' ),
				'days'  => 2,
			),
			'week' => array(
				'label' => __( 'Up to a week', 'spa-ai-concierge' ),
				'days'  => 7,
			),
			'any' => array(
				'label' => __( 'Whatever it takes for the best result', 'spa-ai-concierge' ),
				'days'  => -1,
			),
		);
	}

	/**
	 * Budget options per session (value => [label, max price]). -1 means no limit.
	 *
	 * @return array<string, array{label: string, max: int}>
	 */
	public static function budget_options(): array {
		return array(
			'under-200' => array(
				'label' => __( 'Under $200', 'spa-ai-concierge' ),
				'max'   => 200,
			),
			'under-500' => array(
				'label' => __( '$200 to $500', 'spa-ai-concierge' ),
				'max'   => 500,
			),
			'under-1000' => array(
				'label' => __( '$500 to $1,000', 'spa-ai-concierge' ),
				'max'   => 1000,
			),
			'any' => array(
				'label' => __( 'Flexible, show me the best fit', 'spa-ai-concierge' ),
				'max'   => -1,
			),
		);
	}

	/**
	 * Well-known treatments matched by title, with finder data for the seeder.
	 *
	 * @return array<string, array{pattern: string, concerns: string[], areas: string[], downtime: int, price: int}>
	 */
	public static function profiles(): array {
		return array(
			'neurotoxin'      => array(
				'pattern'  => '/\b(botox|dysport|xeomin|daxxify|jeuveau|neurotoxins?|neuromodulators?|wrinkle relax\w*|anti-wrinkle injections?)\b/i',
				'concerns' => array( 'lines-wrinkles' ),
				'areas'    => array( 'face' ),
				'downtime' => 0,
				'price'    => 300,
			),
			'filler'          => array(
				'pattern'  => '/\b(dermal fillers?|fillers?|juvederm|juvéderm|restylane|rha|lip (filler|augmentation)|cheek filler)\b/i',
				'concerns' => array( 'volume-loss', 'lines-wrinkles' ),
				'areas'    => array( 'face' ),
				'downtime' => 2,
				'price'    => 650,
			),
			'hydrafacial'     => array(
				'pattern'  => '/\bhydra\s?-?facials?\b/i',
				'concerns' => array( 'dull-skin', 'acne-scarring' ),
				'areas'    => array( 'face' ),
				'downtime' => 0,
				'price'    => 199,
			),
			'laser-hair'      => array(
				'pattern'  => '/\blaser hair( removal)?\b/i',
				'concerns' => array( 'unwanted-hair' ),
				'areas'    => array( 'body', 'face' ),
				'downtime' => 0,
				'price'    => 150,
			),
			'chemical-peel'   => array(
				'pattern'  => '/\b(chemical )?peels?\b/i',
				'concerns' => array( 'pigmentation', 'dull-skin', 'acne-scarring' ),
				'areas'    => array( 'face' ),
				'downtime' => 5,
				'price'    => 175,
			),
			'microneedling'   => array(
				'pattern'  => '/\b(micro-?needling|collagen induction|morpheus8|rf microneedling)\b/i',
				'concerns' => array( 'acne-scarring', 'lines-wrinkles', 'dull-skin' ),
				'areas'    => array( 'face' ),
				'downtime' => 3,
				'price'    => 350,
			),
			'iv-therapy'      => array(
				'pattern'  => '/\b(iv (therapy|drips?|infusions?|hydration)|vitamin drips?|nad\+?)\b/i',
				'concerns' => array( 'wellness' ),
				'areas'    => array( 'wellness' ),
				'downtime' => 0,
				'price'    => 175,
			),
			'body-contouring' => array(
				'pattern'  => '/\b(body contouring|body sculpting|coolsculpting|emsculpt\w*|fat reduction|cryolipolysis|sculpt\w*)\b/i',
				'concerns' => array( 'body-contouring' ),
				'areas'    => array( 'body' ),
				'downtime' => 1,
				'price'    => 750,
			),
		);
	}

	/**
	 * Profile key for a treatment title, or null if none matches.
	 *
	 * @param string $title Post title.
	 */
	public static function match_profile( string $title ): ?string {
		foreach ( self::profiles() as $key => $profile ) {
			if ( preg_match( $profile['pattern'], $title ) ) {
				return $key;
			}
		}
		return null;
	}
}
