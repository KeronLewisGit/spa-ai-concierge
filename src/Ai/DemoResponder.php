<?php
/**
 * Canned output for demo mode, built from the post's real title so it reads plausibly.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Ai;

use KeronLewis\SpaAiConcierge\Finder\Catalog;

/**
 * Pure logic. Returns raw tool input in the same shape the API would, so demo output passes
 * through ResponseParser::validate() exactly like a live response.
 */
final class DemoResponder {

	/**
	 * Raw tool input for a task.
	 *
	 * @param string               $task    Task name.
	 * @param array<string, mixed> $context Post context (title, site_name, excerpt, image).
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException For unknown tasks.
	 */
	public static function respond( string $task, array $context ): array {
		switch ( $task ) {
			case Tasks::FAQS:
				return array( 'faqs' => self::faqs( $context ) );
			case Tasks::META:
				return self::meta( $context );
			case Tasks::ALT_TEXT:
				return array( 'alt_text' => self::alt_text( $context ) );
		}
		throw new \InvalidArgumentException( 'Unknown task' );
	}

	/**
	 * FAQs for a title: a matching treatment profile if there is one, otherwise a generic set.
	 *
	 * @param array<string, mixed> $context Post context.
	 * @return array<int, array{question: string, answer: string}>
	 */
	public static function faqs( array $context ): array {
		$title   = self::title( $context );
		$site    = self::site( $context );
		$profile = Catalog::match_profile( $title );
		$sets    = self::faq_sets( $title, $site );

		return $sets[ $profile ?? 'generic' ] ?? $sets['generic'];
	}

	/**
	 * Three title and three description options.
	 *
	 * @param array<string, mixed> $context Post context.
	 * @return array{titles: string[], descriptions: string[]}
	 */
	public static function meta( array $context ): array {
		$title = self::title( $context );
		$site  = self::site( $context );
		$lower = self::lower_title( $title );

		return array(
			'titles'       => array(
				sprintf( '%s | %s', $title, $site ),
				sprintf( '%s: Results, Downtime & What to Expect', $title ),
				sprintf( 'Book %s at %s | Consultations Available', $title, $site ),
			),
			'descriptions' => array(
				sprintf( 'Curious about %1$s? Learn how the treatment works, who it suits and what recovery looks like, then book a consultation with %2$s.', $lower, $site ),
				sprintf( '%1$s at %2$s: a plan built around your goals, clear pricing at consultation and aftercare from our team. Book online today.', $title, $site ),
				sprintf( 'Thinking about %1$s? Get straight answers on results, downtime and cost from the licensed team at %2$s, and book your visit online in a few clicks.', $lower, $site ),
			),
		);
	}

	/**
	 * Alt text from caption, file name and page title.
	 *
	 * @param array<string, mixed> $context Post context with an image key.
	 */
	public static function alt_text( array $context ): string {
		$title = self::title( $context );
		$image = (array) ( $context['image'] ?? array() );

		$caption = trim( (string) ( $image['caption'] ?? '' ) );
		if ( '' !== $caption && mb_strlen( $caption ) <= Tasks::ALT_LIMIT ) {
			return $caption;
		}

		$from_file = self::humanise_filename( (string) ( $image['filename'] ?? '' ) );
		if ( '' === $from_file ) {
			$from_file = self::humanise_filename( (string) ( $image['title'] ?? '' ) );
		}

		$appointment = self::singular( self::lower_title( $title ) ) . ' appointment';
		$article     = preg_match( '/^[aeiou]/i', $appointment ) || preg_match( '/^(iv|led|hr)\b/i', $appointment ) ? 'an' : 'a';

		// Keep the file's own words (minus generic photo words) only if it says more than the title.
		$generic = array( 'treatment', 'treatments', 'image', 'photo', 'hero', 'banner', 'final', 'edit', 'copy', 'web', 'large', 'small' );
		$title_w = array_map( static fn( $w ) => rtrim( $w, 's' ), (array) preg_split( '/\W+/', strtolower( $title ) ) );
		$words   = array_values( array_filter( explode( ' ', $from_file ), static fn( $w ) => '' !== $w && ! in_array( $w, $generic, true ) ) );
		$novel   = array_filter( $words, static fn( $w ) => ! in_array( rtrim( $w, 's' ), $title_w, true ) );

		if ( array() === $novel ) {
			return sprintf( 'Client during %s %s in a calm treatment room', $article, $appointment );
		}
		return ucfirst( implode( ' ', $words ) ) . sprintf( ' during %s %s', $article, $appointment );
	}

	/**
	 * Singular form of the last word for use as a modifier ("chemical peels" → "chemical peel").
	 *
	 * @param string $phrase Phrase.
	 */
	private static function singular( string $phrase ): string {
		return (string) preg_replace( '/(?<![su])s$/', '', $phrase );
	}

	/**
	 * Turn "hydrafacial-treatment-room-1024x683.jpg" into "hydrafacial treatment room".
	 * Camera-style names (IMG_1234, DSC0042, PXL_…) produce an empty string.
	 *
	 * @param string $filename File name or title.
	 */
	public static function humanise_filename( string $filename ): string {
		$name = (string) preg_replace( '/\.(jpe?g|png|webp|gif|avif|heic)$/i', '', basename( $filename ) );
		$name = (string) preg_replace( '/-\d+x\d+$|-scaled$|-e\d{10,}$/i', '', $name );
		if ( preg_match( '/^(img|dsc|dscn|pxl|photo|image|screenshot)[\W_]*\d*/i', $name ) ) {
			return '';
		}
		$name = (string) preg_replace( '/[\-_]+/', ' ', $name );
		$name = (string) preg_replace( '/\b\d+\b/', '', $name );
		$name = trim( (string) preg_replace( '/\s+/', ' ', $name ) );
		return mb_strlen( $name ) >= 4 ? strtolower( $name ) : '';
	}

	/**
	 * Title from context with a fallback.
	 *
	 * @param array<string, mixed> $context Post context.
	 */
	private static function title( array $context ): string {
		$title = trim( (string) ( $context['title'] ?? '' ) );
		return '' !== $title ? $title : 'This Treatment';
	}

	/**
	 * Site name from context with a fallback.
	 *
	 * @param array<string, mixed> $context Post context.
	 */
	private static function site( array $context ): string {
		$site = trim( (string) ( $context['site_name'] ?? '' ) );
		return '' !== $site ? $site : 'our spa';
	}

	/**
	 * Lower-case a title for mid-sentence use, keeping brand names that are all caps or CamelCase.
	 *
	 * @param string $title Title.
	 */
	private static function lower_title( string $title ): string {
		$words = explode( ' ', $title );
		foreach ( $words as $i => $word ) {
			// Keep words with a capital after the first letter (HydraFacial, IV, CoolSculpting).
			if ( ! preg_match( '/^.\S*[A-Z]/u', $word ) && 'Botox' !== $word ) {
				$words[ $i ] = mb_strtolower( $word );
			}
		}
		return implode( ' ', $words );
	}

	/**
	 * FAQ sets per treatment profile.
	 *
	 * @param string $title Post title.
	 * @param string $site  Site name.
	 * @return array<string, array<int, array{question: string, answer: string}>>
	 */
	private static function faq_sets( string $title, string $site ): array {
		$t  = $title;
		$lt = self::lower_title( $title );
		$st = self::singular( $lt );

		$faq = static function ( string $q, string $a ): array {
			return array(
				'question' => $q,
				'answer'   => $a,
			);
		};

		return array(
			'neurotoxin'      => array(
				$faq( "What can {$t} treat?", "{$t} relaxes the muscles that cause expression lines, most often frown lines between the brows, forehead lines and crow's feet. Your provider maps the injection points to your face at the consultation." ),
				$faq( 'Does it hurt?', 'Most clients describe a quick pinch with each injection. The appointment usually takes 15 to 20 minutes and no numbing is needed, though ice is available if you would like it.' ),
				$faq( 'When will I see results, and how long do they last?', 'Results start to appear within 3 to 5 days and settle at around two weeks. Most clients book a touch-up every three to four months, but this varies from person to person.' ),
				$faq( 'Is there any downtime?', 'There is no downtime. Avoid lying down, rubbing the area and hard exercise for about four hours afterwards, and you may see small bumps or redness that fade within the hour.' ),
				$faq( "Who should not have {$lt}?", "{$t} is not recommended during pregnancy or breastfeeding, or with certain neuromuscular conditions. A licensed provider at {$site} reviews your medical history before treatment." ),
			),
			'filler'          => array(
				$faq( "What can {$lt} do?", "{$t} restores lost volume and softens folds, for example in the cheeks, lips, under the eyes and along the jawline. Your provider will suggest a plan that keeps your features looking like you." ),
				$faq( 'How long do results last?', 'Depending on the product and the area treated, results typically last between 6 and 18 months. Lips tend to need topping up sooner than cheeks.' ),
				$faq( 'Will I have swelling or bruising?', 'Some swelling and possible bruising are normal for two to three days, and lips can stay swollen a little longer. Avoid alcohol and blood-thinning supplements before your visit to reduce bruising.' ),
				$faq( 'Can filler be reversed?', 'Hyaluronic acid fillers can be dissolved with an enzyme if needed. Ask your provider which product they recommend for your treatment area.' ),
				$faq( 'How much filler will I need?', "That depends on your goals and the area treated. Most first-time clients start with one syringe, and your provider at {$site} will give you a clear quote at consultation." ),
			),
			'hydrafacial'     => array(
				$faq( "What happens during a {$st} appointment?", 'The treatment cleanses, exfoliates and extracts congestion, then infuses hydrating serums into the skin. It takes about 30 to 60 minutes depending on the add-ons you choose.' ),
				$faq( 'Is it suitable for sensitive skin?', 'Yes. It is gentle enough for most skin types, including sensitive skin. Let your esthetician know about any active breakouts, rosacea or recent treatments so the serums can be adjusted.' ),
				$faq( 'Is there any downtime?', 'None. Your skin may look a little flushed for an hour, and you can wear makeup the next day. Many clients book one before an event.' ),
				$faq( 'How often should I book?', 'Once a month works well for ongoing skin health, which lines up with your skin\'s natural renewal cycle. A series of treatments helps with congestion and dullness.' ),
				$faq( 'Can I combine it with other treatments?', 'Yes. It pairs well with LED light therapy, lymphatic drainage and boosters for specific concerns. Your esthetician can recommend add-ons on the day.' ),
			),
			'laser-hair'      => array(
				$faq( "How does {$lt} work?", 'Laser energy targets the pigment in the hair follicle and disables it, which slows and reduces regrowth. It works best on hair that is darker than the surrounding skin.' ),
				$faq( 'How many sessions will I need?', 'Most clients need six to eight sessions spaced four to eight weeks apart, because hair grows in cycles and only actively growing hair responds to the laser.' ),
				$faq( 'Does it hurt?', 'Most people compare it to a rubber band snapping against the skin. The laser has built-in cooling, and sessions for small areas take only a few minutes.' ),
				$faq( 'How should I prepare?', 'Shave the area the day before, and avoid waxing, plucking and tanning for four weeks beforehand. Arrive without lotion, deodorant or makeup on the area being treated.' ),
				$faq( 'Is it safe for darker skin tones?', "Many modern lasers can treat a wide range of skin tones safely. A patch test at {$site} confirms the right settings for your skin and hair." ),
			),
			'chemical-peel'   => array(
				$faq( "What can {$lt} do for my skin?", 'A peel applies an acid solution that lifts away dull surface cells, helping fade sun spots, smooth texture and clear congestion. The strength is chosen for your skin and goals.' ),
				$faq( 'Will my skin actually peel?', 'With light peels you may see mild flaking for a few days. Medium-depth peels cause more visible peeling for about five to seven days. Do not pick at it; let it shed naturally.' ),
				$faq( 'How should I care for my skin afterwards?', 'Use a gentle cleanser and moisturiser, wear SPF 30 or higher every day, and pause retinoids and exfoliants until your provider says you can restart them.' ),
				$faq( 'How many peels will I need?', 'Many clients see brighter skin after one peel, but a series of three to six, spaced about four weeks apart, gives the best results for pigmentation and acne scarring.' ),
				$faq( 'Who should avoid chemical peels?', "Peels are not recommended during pregnancy, with active cold sores or infections, or if you have recently used isotretinoin. {$site} checks this at consultation." ),
			),
			'microneedling'   => array(
				$faq( "How does {$lt} work?", 'Fine needles create controlled micro-channels in the skin, which prompts it to produce new collagen and elastin. Over the following weeks, texture, scarring and fine lines improve.' ),
				$faq( 'Does it hurt?', 'A numbing cream is applied for about 30 minutes first, so most clients feel only light pressure or a scratching sensation during the treatment.' ),
				$faq( 'What is the downtime?', 'Expect redness similar to a mild sunburn for one to three days. You can usually return to work the next day and wear mineral makeup after 24 hours.' ),
				$faq( 'How many sessions will I need?', 'Most clients book three to four sessions about four to six weeks apart. Results continue to build for several months after the final session.' ),
				$faq( 'Can it help with acne scars?', 'Yes, microneedling is one of the most popular treatments for rolling and boxcar acne scars. Active acne should be under control first, which your provider will assess.' ),
			),
			'iv-therapy'      => array(
				$faq( "What should I know about {$lt}?", 'IV therapy delivers fluids, vitamins and electrolytes straight into the bloodstream. A licensed nurse places a small IV line and you relax in a lounge chair while it runs.' ),
				$faq( 'How long does a drip take?', 'Most drips take 30 to 60 minutes. Plan a little extra time for your first visit so the nurse can review your health history.' ),
				$faq( 'Who is it for?', 'Clients book IV therapy for hydration after travel, exercise or a busy week, or as part of a wellness routine. Your nurse will check whether it is suitable for you before starting.' ),
				$faq( 'Are there side effects?', 'You may feel a slight pinch when the line is placed and a cool sensation during the drip. Minor bruising at the site is possible and fades within a few days.' ),
				$faq( 'How often can I have IV therapy?', "That depends on the drip and your health. Many clients come in every two to four weeks. The {$site} team will recommend a schedule that suits you." ),
			),
			'body-contouring' => array(
				$faq( "What is involved in {$lt}?", 'Non-surgical body contouring targets stubborn fat or muscle tone in specific areas without incisions. Your provider will recommend the technology that fits your goals at consultation.' ),
				$faq( 'Is it a weight-loss treatment?', 'No. It is designed to reshape areas that do not respond to diet and exercise, and works best for people who are already close to their goal weight.' ),
				$faq( 'When will I see results?', 'Results develop gradually as the body processes treated fat cells, usually over 8 to 12 weeks. Some clients choose a second session for a more visible change.' ),
				$faq( 'Is there any downtime?', 'Most clients return to normal activities the same day. Temporary redness, tingling, numbness or soreness in the treated area is common for a few days.' ),
				$faq( 'Which areas can be treated?', "Common areas include the abdomen, flanks, thighs, arms and under the chin. {$site} will map the treatment areas with you before your first session." ),
			),
			'generic'         => array(
				$faq( "What should I know about {$lt}?", "{$t} is one of the services offered at {$site}. Your provider explains exactly what is involved and tailors it to your goals at your first appointment." ),
				$faq( "Who is a good candidate for {$lt}?", 'Most healthy adults are good candidates. Share any medical conditions, medications, allergies or recent treatments when you book so we can confirm it is right for you.' ),
				$faq( 'How long does the appointment take?', 'Appointment times vary with the options you choose. We confirm the timing when you book so you can plan the rest of your day.' ),
				$faq( 'Is there any downtime?', 'Downtime depends on the treatment plan. Your provider will walk you through aftercare and anything to avoid in the days afterwards.' ),
				$faq( 'How do I book?', "You can book online or call {$site}. If you are not sure which service suits you, start with a consultation and we will recommend a plan." ),
			),
		);
	}
}
