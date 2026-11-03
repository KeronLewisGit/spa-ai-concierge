<?php
/**
 * Front-end FAQs: accordion block, [spa_faqs] shortcode, optional auto-append, FAQPage JSON-LD.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Faq;

use KeronLewis\SpaAiConcierge\Assets;
use KeronLewis\SpaAiConcierge\Integrations\ThemeFields;
use KeronLewis\SpaAiConcierge\Settings;

/**
 * Renders saved FAQs and prints matching structured data once per page.
 */
final class Faqs {

	public const SHORTCODE = 'spa_faqs';
	public const BLOCK     = 'spa-ai-concierge/faqs';

	/**
	 * Post IDs whose FAQs were rendered on this request, in order.
	 *
	 * @var int[]
	 */
	private static array $rendered = array();

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_shortcode( self::SHORTCODE, array( self::class, 'shortcode' ) );
		add_filter( 'the_content', array( self::class, 'auto_append' ), 20 );
		add_action( 'wp_footer', array( self::class, 'print_schema' ), 20 );
	}

	/**
	 * Shortcode: [spa_faqs post_id="123" heading="Questions" open_first="1"].
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public static function shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'post_id'    => 0,
				'heading'    => __( 'Frequently asked questions', 'spa-ai-concierge' ),
				'open_first' => '0',
			),
			is_array( $atts ) ? $atts : array(),
			self::SHORTCODE
		);
		return self::render( absint( $atts['post_id'] ), (string) $atts['heading'], filter_var( $atts['open_first'], FILTER_VALIDATE_BOOLEAN ) );
	}

	/**
	 * Block render callback.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner content (unused).
	 * @param \WP_Block|null       $block      Block instance.
	 */
	public static function render_block( array $attributes, string $content = '', $block = null ): string {
		$post_id = absint( $attributes['postId'] ?? 0 );
		if ( 0 === $post_id && $block instanceof \WP_Block && isset( $block->context['postId'] ) ) {
			$post_id = absint( $block->context['postId'] );
		}
		$html = self::render( $post_id, (string) ( $attributes['heading'] ?? __( 'Frequently asked questions', 'spa-ai-concierge' ) ), ! empty( $attributes['openFirst'] ) );
		if ( '' === $html ) {
			return '';
		}
		return '<div ' . get_block_wrapper_attributes( array( 'class' => 'spa-faqs-block' ) ) . '>' . $html . '</div>';
	}

	/**
	 * Accordion markup. Returns '' when the post has no FAQs.
	 *
	 * @param int    $post_id    Post ID (0 = current post).
	 * @param string $heading    Heading text ('' hides it).
	 * @param bool   $open_first Open the first item.
	 */
	public static function render( int $post_id, string $heading, bool $open_first = false ): string {
		$post_id = $post_id > 0 ? $post_id : (int) get_the_ID();
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ( 'publish' !== $post->post_status && ! current_user_can( 'read_post', $post_id ) ) || post_password_required( $post ) ) {
			return '';
		}

		$faqs = FaqRepository::get( $post_id );
		if ( array() === $faqs ) {
			return '';
		}

		Assets::enqueue_frontend( false );
		self::$rendered[] = $post_id;

		$heading_id = 'spa-faqs-' . $post_id . '-' . count( self::$rendered );
		$html       = '<section class="spa-faqs"' . ( '' !== $heading ? ' aria-labelledby="' . esc_attr( $heading_id ) . '"' : '' ) . '>';
		if ( '' !== $heading ) {
			$html .= '<h2 class="spa-faqs__title" id="' . esc_attr( $heading_id ) . '">' . esc_html( $heading ) . '</h2>';
		}
		$html .= '<div class="spa-faqs__list">';
		foreach ( $faqs as $i => $faq ) {
			$html .= '<details class="spa-faq"' . ( $open_first && 0 === $i ? ' open' : '' ) . '>';
			$html .= '<summary class="spa-faq__question">' . esc_html( $faq['question'] ) . '</summary>';
			$html .= '<div class="spa-faq__answer">' . wpautop( wp_kses( $faq['answer'], FaqRepository::allowed_answer_html() ) ) . '</div>';
			$html .= '</details>';
		}
		$html .= '</div></section>';

		return $html;
	}

	/**
	 * Append FAQs to singular treatment content when the setting is on and the content does
	 * not already include the block or shortcode.
	 *
	 * @param string $content Post content.
	 */
	public static function auto_append( $content ) {
		if ( ! is_string( $content ) || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$post = get_post();
		if ( ! $post instanceof \WP_Post || (int) get_queried_object_id() !== $post->ID ) {
			return $content;
		}

		// Themes with their own FAQ field render it themselves; the plugin syncs into that field instead.
		$enabled = (bool) Settings::get( 'auto_append_faqs' )
			&& Settings::finder_post_type() === $post->post_type
			&& '' === ThemeFields::faq_field( $post->ID );
		/**
		 * Filters whether saved FAQs are appended to the end of the post content.
		 *
		 * @param bool     $enabled Default: setting on, post is the treatment post type, and the
		 *                          post has no theme FAQ field.
		 * @param \WP_Post $post    Post.
		 */
		if ( ! apply_filters( 'spa_ai_concierge_auto_append_faqs', $enabled, $post ) ) {
			return $content;
		}
		if ( has_block( self::BLOCK, $post ) || has_shortcode( $post->post_content, self::SHORTCODE ) || in_array( $post->ID, self::$rendered, true ) ) {
			return $content;
		}

		return $content . self::render( $post->ID, __( 'Frequently asked questions', 'spa-ai-concierge' ) );
	}

	/**
	 * FAQPage JSON-LD data for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>|null
	 */
	public static function schema_data( int $post_id ): ?array {
		$faqs = FaqRepository::get( $post_id );
		if ( array() === $faqs ) {
			return null;
		}

		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'@id'        => get_permalink( $post_id ) . '#faq',
			'url'        => get_permalink( $post_id ),
			'mainEntity' => array_map(
				static fn( array $faq ): array => array(
					'@type'          => 'Question',
					'name'           => $faq['question'],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => trim( wp_strip_all_tags( $faq['answer'] ) ),
					),
				),
				$faqs
			),
		);
	}

	/**
	 * Whether this plugin should print FAQPage schema for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function should_output_schema( int $post_id ): bool {
		$post    = get_post( $post_id );
		$default = $post instanceof \WP_Post
			&& ! has_block( 'yoast/faq-block', $post )
			&& ! has_block( 'rank-math/faq-block', $post )
			&& ! self::rank_math_has_faq_schema( $post_id )
			&& ! current_theme_supports( 'spa-ai-concierge-no-faq-schema' );

		/**
		 * Filters whether the plugin prints FAQPage JSON-LD for a post. Return false from a theme
		 * that prints its own FAQ schema.
		 *
		 * @param bool $output  Default decision.
		 * @param int  $post_id Post ID.
		 */
		return (bool) apply_filters( 'spa_ai_concierge_output_faq_schema', $default, $post_id );
	}

	/**
	 * Rank Math stores schema in rank_math_schema_* meta.
	 *
	 * @param int $post_id Post ID.
	 */
	private static function rank_math_has_faq_schema( int $post_id ): bool {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			return false;
		}
		foreach ( get_post_meta( $post_id ) as $key => $values ) {
			if ( str_starts_with( (string) $key, 'rank_math_schema_' ) && str_contains( (string) ( $values[0] ?? '' ), 'FAQPage' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Print JSON-LD for the main post if its FAQs were shown on the page. Printed in the footer
	 * because block themes and classic themes render content at different points.
	 */
	public static function print_schema(): void {
		if ( array() === self::$rendered || ! is_singular() ) {
			return;
		}
		$post_id = in_array( (int) get_queried_object_id(), self::$rendered, true ) ? (int) get_queried_object_id() : self::$rendered[0];
		if ( ! self::should_output_schema( $post_id ) ) {
			return;
		}
		$data = self::schema_data( $post_id );
		if ( null === $data ) {
			return;
		}
		echo "\n" . '<script type="application/ld+json" class="spa-ai-concierge-faq-schema">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . '</script>' . "\n";
	}
}
