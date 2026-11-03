<?php
/**
 * Treatment Finder: shortcode, block render, no-JS GET results and the public REST endpoint.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Finder;

use KeronLewis\SpaAiConcierge\Assets;
use KeronLewis\SpaAiConcierge\Settings;
use KeronLewis\SpaAiConcierge\Support\RateLimiter;

/**
 * Renders the four-step quiz as one progressively enhanced GET form.
 */
final class Finder {

	public const SHORTCODE     = 'spa_treatment_finder';
	public const BLOCK         = 'spa-ai-concierge/treatment-finder';
	public const QUERY_FLAG    = 'spa_finder';
	private const RATE_LIMIT   = 30;
	private const RATE_WINDOW  = 60;

	/**
	 * Instance counter for unique IDs.
	 *
	 * @var int
	 */
	private static int $instance = 0;

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'heading' => __( 'Find your treatment', 'spa-ai-concierge' ),
				'intro'   => __( 'Answer four quick questions and we will suggest the treatments that best fit your goals, schedule and budget.', 'spa-ai-concierge' ),
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		return self::render( $atts );
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public static function render_block( array $attributes ): string {
		$html = self::render(
			array(
				'heading' => (string) ( $attributes['heading'] ?? __( 'Find your treatment', 'spa-ai-concierge' ) ),
				'intro'   => (string) ( $attributes['intro'] ?? '' ),
			)
		);
		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'spa-finder-block' ) ) . '>' . $html . '</div>';
	}

	/**
	 * Concern options for the first question: taxonomy terms in use, else the defaults.
	 *
	 * @return array<string, string> slug => label
	 */
	public static function concern_options(): array {
		$defaults = Catalog::concerns();
		$terms    = taxonomy_exists( TreatmentFields::TAXONOMY ) ? get_terms(
			array(
				'taxonomy'   => TreatmentFields::TAXONOMY,
				'hide_empty' => true,
			)
		) : array();

		if ( ! is_array( $terms ) || array() === $terms ) {
			return $defaults;
		}

		$options = array();
		foreach ( array_keys( $defaults ) as $slug ) {
			foreach ( $terms as $term ) {
				if ( $term->slug === $slug ) {
					$options[ $slug ] = $term->name;
				}
			}
		}
		foreach ( $terms as $term ) {
			if ( ! isset( $options[ $term->slug ] ) ) {
				$options[ $term->slug ] = $term->name;
			}
		}
		return $options;
	}

	/**
	 * Read answers from the query string (no-JS submit).
	 *
	 * @return array<string, mixed>|null Null when the form was not submitted.
	 */
	private static function answers_from_request(): ?array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public, read-only GET form; values are whitelisted in Scorer::normalise_answers().
		if ( empty( $_GET[ self::QUERY_FLAG ] ) ) {
			return null;
		}
		$raw = array();
		foreach ( array( 'concern', 'area', 'downtime', 'budget' ) as $key ) {
			$raw[ $key ] = isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : '';
		}
		// phpcs:enable
		return $raw;
	}

	/**
	 * Full finder markup.
	 *
	 * @param array{heading: string, intro: string} $atts Display options.
	 */
	public static function render( array $atts ): string {
		Assets::enqueue_frontend( true );
		++self::$instance;
		$id = 'spa-finder-' . self::$instance;

		$submitted = self::answers_from_request();
		$answers   = null !== $submitted ? Scorer::normalise_answers( $submitted ) : null;
		$results   = '';
		if ( null !== $answers ) {
			$limit   = new RateLimiter( 'finder', self::RATE_LIMIT, self::RATE_WINDOW );
			$results = $limit->hit( RateLimiter::visitor_key() )['allowed']
				? self::render_results( $answers )
				: '<p class="spa-finder__notice">' . esc_html__( 'You have run the quiz a lot in the last minute. Please wait a moment and try again.', 'spa-ai-concierge' ) . '</p>';
		}

		$steps = array(
			array(
				'name'    => 'concern',
				'legend'  => __( 'What would you most like to improve?', 'spa-ai-concierge' ),
				'options' => self::concern_options(),
			),
			array(
				'name'    => 'area',
				'legend'  => __( 'Where would you like to see a difference?', 'spa-ai-concierge' ),
				'options' => Catalog::areas() + array( 'any' => __( 'Not sure yet', 'spa-ai-concierge' ) ),
			),
			array(
				'name'    => 'downtime',
				'legend'  => __( 'How much downtime can you plan for?', 'spa-ai-concierge' ),
				'options' => wp_list_pluck( Catalog::downtime_options(), 'label' ),
			),
			array(
				'name'    => 'budget',
				'legend'  => __( 'What is your budget per session?', 'spa-ai-concierge' ),
				'options' => wp_list_pluck( Catalog::budget_options(), 'label' ),
			),
		);

		$action = remove_query_arg( array( self::QUERY_FLAG, 'concern', 'area', 'downtime', 'budget' ) );

		ob_start();
		?>
		<section class="spa-finder" id="<?php echo esc_attr( $id ); ?>" data-spa-finder data-rest="<?php echo esc_url( rest_url( 'spa-ai-concierge/v1/finder' ) ); ?>" aria-labelledby="<?php echo esc_attr( $id ); ?>-title">
			<header class="spa-finder__header">
				<?php if ( '' !== $atts['heading'] ) : ?>
					<h2 class="spa-finder__title" id="<?php echo esc_attr( $id ); ?>-title"><?php echo esc_html( $atts['heading'] ); ?></h2>
				<?php endif; ?>
				<?php if ( '' !== $atts['intro'] ) : ?>
					<p class="spa-finder__intro"><?php echo esc_html( $atts['intro'] ); ?></p>
				<?php endif; ?>
			</header>

			<form class="spa-finder__form" method="get" action="<?php echo esc_url( $action . '#' . $id ); ?>" data-spa-finder-form>
				<input type="hidden" name="<?php echo esc_attr( self::QUERY_FLAG ); ?>" value="1">
				<p class="spa-finder__progress" data-spa-finder-progress hidden></p>

				<?php foreach ( $steps as $index => $step ) : ?>
					<fieldset class="spa-finder__step" data-step="<?php echo esc_attr( (string) ( $index + 1 ) ); ?>">
						<legend class="spa-finder__legend">
							<span class="spa-finder__step-count">
								<?php
								/* translators: 1: step number, 2: total steps */
								echo esc_html( sprintf( __( 'Question %1$d of %2$d', 'spa-ai-concierge' ), $index + 1, count( $steps ) ) );
								?>
							</span>
							<span class="spa-finder__question" tabindex="-1"><?php echo esc_html( $step['legend'] ); ?></span>
						</legend>
						<div class="spa-finder__options">
							<?php foreach ( $step['options'] as $value => $label ) : ?>
								<?php $input_id = $id . '-' . $step['name'] . '-' . $value; ?>
								<label class="spa-finder__option" for="<?php echo esc_attr( $input_id ); ?>">
									<input type="radio" id="<?php echo esc_attr( $input_id ); ?>" name="<?php echo esc_attr( $step['name'] ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" required
										<?php checked( null !== $submitted && ( $submitted[ $step['name'] ] ?? '' ) === (string) $value ); ?>>
									<span><?php echo esc_html( $label ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="spa-finder__error" data-spa-finder-error hidden><?php esc_html_e( 'Choose an option to continue.', 'spa-ai-concierge' ); ?></p>
					</fieldset>
				<?php endforeach; ?>

				<div class="spa-finder__nav">
					<button type="button" class="spa-finder__button spa-finder__button--ghost" data-spa-finder-back hidden><?php esc_html_e( 'Back', 'spa-ai-concierge' ); ?></button>
					<button type="button" class="spa-finder__button" data-spa-finder-next hidden><?php esc_html_e( 'Next', 'spa-ai-concierge' ); ?></button>
					<button type="submit" class="spa-finder__button" data-spa-finder-submit><?php esc_html_e( 'See my matches', 'spa-ai-concierge' ); ?></button>
				</div>
			</form>

			<div class="spa-finder__results" data-spa-finder-results aria-live="polite">
				<?php echo $results; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in render_results(). ?>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Ranked results for answers.
	 *
	 * @param array<string, mixed> $answers Normalised answers.
	 * @return array<int, array<string, mixed>>
	 */
	public static function results( array $answers ): array {
		/**
		 * Filters how many treatments the finder recommends.
		 *
		 * @param int $limit Number of results.
		 */
		$limit = (int) apply_filters( 'spa_ai_concierge_finder_limit', 3 );
		$ranked = Scorer::rank( TreatmentRepository::all(), $answers, $limit );

		/**
		 * Filters the ranked finder results.
		 *
		 * @param array $ranked  Ranked treatments.
		 * @param array $answers Normalised answers.
		 */
		return (array) apply_filters( 'spa_ai_concierge_finder_results', $ranked, $answers );
	}

	/**
	 * Results markup.
	 *
	 * @param array<string, mixed>                  $answers Normalised answers.
	 * @param array<int, array<string, mixed>>|null $ranked  Pre-computed results.
	 * @param string|null                           $reset   "Start over" URL; defaults to the current URL without answers.
	 */
	public static function render_results( array $answers, ?array $ranked = null, ?string $reset = null ): string {
		$ranked = $ranked ?? self::results( $answers );
		$reset  = $reset ?? remove_query_arg( array( self::QUERY_FLAG, 'concern', 'area', 'downtime', 'budget' ) );

		ob_start();
		?>
		<div class="spa-finder__result-wrap" data-spa-finder-complete
			data-concern="<?php echo esc_attr( (string) $answers['concern'] ); ?>"
			data-area="<?php echo esc_attr( (string) $answers['area'] ); ?>"
			data-downtime="<?php echo esc_attr( (string) $answers['downtime'] ); ?>"
			data-budget="<?php echo esc_attr( (string) $answers['budget'] ); ?>"
			data-count="<?php echo esc_attr( (string) count( $ranked ) ); ?>">
			<h3 class="spa-finder__results-title" tabindex="-1">
				<?php echo esc_html( array() === $ranked ? __( 'Let’s talk it through', 'spa-ai-concierge' ) : __( 'Your top matches', 'spa-ai-concierge' ) ); ?>
			</h3>

			<?php if ( array() === $ranked ) : ?>
				<p><?php esc_html_e( 'None of our treatments is a clear fit for those answers. Book a consultation and we will put together a plan with you.', 'spa-ai-concierge' ); ?></p>
				<p><a class="spa-finder__button" href="<?php echo esc_url( self::booking_url( '' ) ); ?>" data-spa-finder-book data-treatment=""><?php esc_html_e( 'Book a consultation', 'spa-ai-concierge' ); ?></a></p>
			<?php else : ?>
				<ol class="spa-finder__cards">
					<?php foreach ( $ranked as $position => $item ) : ?>
						<li class="spa-card<?php echo 0 === $position ? ' spa-card--top' : ''; ?>">
							<?php if ( ! empty( $item['image'] ) ) : ?>
								<img class="spa-card__image" src="<?php echo esc_url( (string) $item['image'] ); ?>" alt="" loading="lazy" decoding="async">
							<?php endif; ?>
							<div class="spa-card__body">
								<p class="spa-card__badge">
									<?php
									if ( ! $item['concern_match'] ) {
										esc_html_e( 'Also worth asking about', 'spa-ai-concierge' );
									} elseif ( 0 === $position ) {
										/* translators: %d: match percentage */
										echo esc_html( sprintf( __( 'Best match · %d%%', 'spa-ai-concierge' ), (int) $item['match'] ) );
									} else {
										/* translators: %d: match percentage */
										echo esc_html( sprintf( __( 'Good match · %d%%', 'spa-ai-concierge' ), (int) $item['match'] ) );
									}
									?>
								</p>
								<h4 class="spa-card__title"><a href="<?php echo esc_url( (string) $item['url'] ); ?>"><?php echo esc_html( (string) $item['title'] ); ?></a></h4>
								<?php if ( ! empty( $item['excerpt'] ) ) : ?>
									<p class="spa-card__excerpt"><?php echo esc_html( (string) $item['excerpt'] ); ?></p>
								<?php endif; ?>
								<?php if ( ! empty( $item['reasons'] ) ) : ?>
									<ul class="spa-card__reasons">
										<?php foreach ( $item['reasons'] as $reason ) : ?>
											<li><?php echo esc_html( (string) $reason ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
								<?php if ( null !== $item['price'] ) : ?>
									<p class="spa-card__price">
										<?php
										/* translators: %s: price */
										echo esc_html( trim( sprintf( __( 'From $%s', 'spa-ai-concierge' ), number_format_i18n( (int) $item['price'] ) ) . ' ' . (string) ( $item['price_unit'] ?? '' ) ) );
										?>
									</p>
								<?php endif; ?>
								<div class="spa-card__actions">
									<a class="spa-finder__button" href="<?php echo esc_url( self::booking_url( (string) $item['slug'] ) ); ?>" data-spa-finder-book data-treatment="<?php echo esc_attr( (string) $item['title'] ); ?>" data-treatment-id="<?php echo esc_attr( (string) $item['id'] ); ?>">
										<?php esc_html_e( 'Book a consultation', 'spa-ai-concierge' ); ?>
										<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: treatment */ __( 'for %s', 'spa-ai-concierge' ), (string) $item['title'] ) ); ?></span>
									</a>
									<a class="spa-card__more" href="<?php echo esc_url( (string) $item['url'] ); ?>">
										<?php esc_html_e( 'Learn more', 'spa-ai-concierge' ); ?>
										<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %s: treatment */ __( 'about %s', 'spa-ai-concierge' ), (string) $item['title'] ) ); ?></span>
									</a>
								</div>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>

			<p class="spa-finder__restart"><a href="<?php echo esc_url( $reset ); ?>" data-spa-finder-restart><?php esc_html_e( 'Start over', 'spa-ai-concierge' ); ?></a></p>
			<p class="spa-finder__disclaimer"><?php esc_html_e( 'Suggestions are a starting point, not medical advice. Your provider confirms what is right for you at consultation.', 'spa-ai-concierge' ); ?></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Booking URL with the treatment slug attached.
	 *
	 * @param string $treatment_slug Treatment slug, or '' for a general consultation.
	 */
	public static function booking_url( string $treatment_slug ): string {
		$base = (string) Settings::get( 'booking_url' );
		if ( '' === $base ) {
			foreach ( array( 'book', 'booking', 'book-now', 'book-a-consultation', 'consultation', 'contact' ) as $path ) {
				$page = get_page_by_path( $path );
				if ( $page instanceof \WP_Post && 'publish' === $page->post_status ) {
					$base = (string) get_permalink( $page );
					break;
				}
			}
		}
		if ( '' === $base ) {
			$base = home_url( '/' );
		}
		$url = '' !== $treatment_slug ? add_query_arg( 'treatment', rawurlencode( $treatment_slug ), $base ) : $base;

		/**
		 * Filters the "Book a consultation" URL.
		 *
		 * @param string $url            URL.
		 * @param string $treatment_slug Treatment slug or ''.
		 */
		return (string) apply_filters( 'spa_ai_concierge_booking_url', $url, $treatment_slug );
	}

	/**
	 * Public read-only REST route.
	 */
	public static function register_route(): void {
		$enum = static fn( array $values ): array => array(
			'type'              => 'string',
			'required'          => false,
			'default'           => 'any',
			'enum'              => array_values( $values ),
			'sanitize_callback' => 'sanitize_key',
		);

		register_rest_route(
			'spa-ai-concierge/v1',
			'/finder',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'rest_results' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'concern'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn( $value ) => is_string( $value ) && (bool) preg_match( '/^[a-z0-9\-]{1,60}$/', $value ),
					),
					'area'     => $enum( array_merge( array_keys( Catalog::areas() ), array( 'any' ) ) ),
					'downtime' => $enum( array_keys( Catalog::downtime_options() ) ),
					'budget'   => $enum( array_keys( Catalog::budget_options() ) ),
				),
			)
		);
	}

	/**
	 * REST callback.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function rest_results( \WP_REST_Request $request ) {
		$limit = new RateLimiter( 'finder', self::RATE_LIMIT, self::RATE_WINDOW );
		$hit   = $limit->hit( RateLimiter::visitor_key() );
		if ( ! $hit['allowed'] ) {
			return new \WP_Error(
				'spa_ai_rate_limited',
				__( 'You have run the quiz a lot in the last minute. Please wait a moment and try again.', 'spa-ai-concierge' ),
				array(
					'status'      => 429,
					'retry_after' => $hit['retry_after'],
				)
			);
		}

		$answers = Scorer::normalise_answers(
			array(
				'concern'  => $request['concern'],
				'area'     => $request['area'],
				'downtime' => $request['downtime'],
				'budget'   => $request['budget'],
			)
		);
		$ranked  = self::results( $answers );

		$response = new \WP_REST_Response(
			array(
				'answers' => array_intersect_key( $answers, array_flip( array( 'concern', 'area', 'downtime', 'budget' ) ) ),
				'results' => array_map(
					static fn( array $item ): array => array(
						'id'            => (int) $item['id'],
						'title'         => (string) $item['title'],
						'url'           => (string) $item['url'],
						'match'         => (int) $item['match'],
						'concern_match' => (bool) $item['concern_match'],
						'reasons'       => $item['reasons'],
						'price_from'    => $item['price'],
						'booking_url'   => self::booking_url( (string) $item['slug'] ),
					),
					$ranked
				),
				'html'    => self::render_results( $answers, $ranked, '#' ),
			)
		);
		$response->header( 'X-RateLimit-Remaining', (string) $hit['remaining'] );
		return $response;
	}
}
