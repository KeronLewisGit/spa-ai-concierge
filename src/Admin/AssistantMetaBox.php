<?php
/**
 * "Spa AI Concierge" meta box: FAQ list, meta title/description, featured image alt text.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Admin;

use KeronLewis\SpaAiConcierge\Ai\Tasks;
use KeronLewis\SpaAiConcierge\Faq\FaqRepository;
use KeronLewis\SpaAiConcierge\Integrations\ThemeFields;
use KeronLewis\SpaAiConcierge\Rest\AssistantController;
use KeronLewis\SpaAiConcierge\Seo\SeoMeta;
use KeronLewis\SpaAiConcierge\Settings;

/**
 * Works in the classic editor and as a meta box in the block editor. Generated text only fills
 * form fields; it is saved when the editor updates the post.
 */
final class AssistantMetaBox {

	private const NONCE = 'spa_ai_assistant_save';

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add' ), 10, 2 );
		add_action( 'save_post', array( self::class, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	/**
	 * Post types that get the box: posts, pages and any public CPT.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
		/**
		 * Filters the post types that show the assistant meta box.
		 *
		 * @param string[] $types Post type slugs.
		 */
		return (array) apply_filters( 'spa_ai_concierge_assistant_post_types', $types );
	}

	/**
	 * Add the meta box.
	 *
	 * @param string $post_type Post type.
	 */
	public static function add( $post_type ): void {
		if ( ! in_array( $post_type, self::post_types(), true ) ) {
			return;
		}
		add_meta_box( 'spa-ai-concierge', __( 'Spa AI Concierge', 'spa-ai-concierge' ), array( self::class, 'render' ), $post_type, 'normal', 'high' );
	}

	/**
	 * Enqueue admin assets on edit screens.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, self::post_types(), true ) ) {
			return;
		}

		wp_enqueue_style( 'spa-ai-concierge-admin', SPA_AI_CONCIERGE_URL . 'assets/css/admin.css', array(), SPA_AI_CONCIERGE_VERSION );
		wp_enqueue_script( 'spa-ai-concierge-admin', SPA_AI_CONCIERGE_URL . 'assets/js/admin.js', array( 'wp-i18n' ), SPA_AI_CONCIERGE_VERSION, true );
		wp_set_script_translations( 'spa-ai-concierge-admin', 'spa-ai-concierge', SPA_AI_CONCIERGE_DIR . 'languages' );
		wp_localize_script(
			'spa-ai-concierge-admin',
			'spaAiConcierge',
			array(
				'restRoot' => esc_url_raw( rest_url( AssistantController::NAMESPACE . '/posts/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'demo'     => Settings::is_demo_mode(),
				'limits'   => array(
					'title'       => Tasks::TITLE_LIMIT,
					'description' => Tasks::DESCRIPTION_LIMIT,
					'alt'         => Tasks::ALT_LIMIT,
				),
			)
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render( \WP_Post $post ): void {
		$faqs      = FaqRepository::get( $post->ID );
		$source    = (string) get_post_meta( $post->ID, FaqRepository::SOURCE_KEY, true );
		$meta      = SeoMeta::get( $post->ID );
		$theme_faq = ThemeFields::faq_field( $post->ID );
		$theme_seo = ThemeFields::seo_keys( $post->ID );
		foreach ( $theme_seo as $field => $key ) {
			if ( '' === $meta[ $field ] ) {
				$meta[ $field ] = (string) get_post_meta( $post->ID, $key, true );
			}
		}
		$seo      = SeoMeta::active_plugins();
		$thumb_id = (int) get_post_thumbnail_id( $post );
		$alt      = $thumb_id ? (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ) : '';
		$demo     = Settings::is_demo_mode();

		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		?>
		<div class="spa-ai" data-spa-ai data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
			<?php if ( $demo ) : ?>
				<p class="spa-ai__mode spa-ai__mode--demo">
					<strong><?php esc_html_e( 'Demo mode', 'spa-ai-concierge' ); ?></strong>
					<?php esc_html_e( 'Buttons return canned sample text built from this post’s title. No request is sent to Anthropic.', 'spa-ai-concierge' ); ?>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . Settings::PAGE_SLUG ) ); ?>"><?php esc_html_e( 'Settings', 'spa-ai-concierge' ); ?></a>
					<?php endif; ?>
				</p>
			<?php else : ?>
				<p class="spa-ai__mode">
					<?php
					/* translators: %s: model ID */
					echo esc_html( sprintf( __( 'Using the Anthropic API (%s). Suggestions fill the fields below; nothing is published until you update the post.', 'spa-ai-concierge' ), Settings::model() ) );
					?>
				</p>
			<?php endif; ?>

			<p class="spa-ai__status" data-spa-ai-status role="status" aria-live="polite"></p>

			<section class="spa-ai__section" aria-labelledby="spa-ai-faqs-h">
				<div class="spa-ai__section-head">
					<h3 id="spa-ai-faqs-h"><?php esc_html_e( 'FAQs', 'spa-ai-concierge' ); ?></h3>
					<button type="button" class="button" data-spa-ai-run="faqs"><?php esc_html_e( 'Generate FAQs', 'spa-ai-concierge' ); ?></button>
				</div>
				<p class="description">
					<?php
					printf(
						/* translators: 1: block name, 2: shortcode */
						esc_html__( 'Generated FAQs are added to the list for you to edit. Shown on the page by the %1$s block or %2$s shortcode, with FAQPage schema.', 'spa-ai-concierge' ),
						'<em>' . esc_html__( 'Spa FAQs', 'spa-ai-concierge' ) . '</em>',
						'<code>[spa_faqs]</code>'
					);
					?>
				</p>
				<?php if ( '' !== $theme_faq ) : ?>
					<p class="description">
						<?php
						/* translators: %s: field name */
						printf( esc_html__( 'This post also has the theme field %s. Saved FAQs are written back to it so the theme shows the same list.', 'spa-ai-concierge' ), '<code>' . esc_html( $theme_faq ) . '</code>' );
						?>
					</p>
				<?php endif; ?>
				<input type="hidden" name="spa_ai[faqs_source]" value="<?php echo esc_attr( '' !== $source ? $source : 'manual' ); ?>" data-spa-ai-faq-source>
				<ol class="spa-ai__faqs" data-spa-ai-faqs>
					<?php
					foreach ( $faqs as $i => $faq ) {
						self::faq_row( (string) $i, $faq['question'], $faq['answer'] );
					}
					?>
				</ol>
				<template data-spa-ai-faq-template><?php self::faq_row( '__i__', '', '' ); ?></template>
				<p><button type="button" class="button-link" data-spa-ai-add-faq>+ <?php esc_html_e( 'Add FAQ', 'spa-ai-concierge' ); ?></button></p>
			</section>

			<section class="spa-ai__section" aria-labelledby="spa-ai-meta-h">
				<div class="spa-ai__section-head">
					<h3 id="spa-ai-meta-h"><?php esc_html_e( 'Search appearance', 'spa-ai-concierge' ); ?></h3>
					<button type="button" class="button" data-spa-ai-run="meta"><?php esc_html_e( 'Suggest meta title + description', 'spa-ai-concierge' ); ?></button>
				</div>
				<p class="description">
					<?php
					$targets = array_values( $seo );
					foreach ( $theme_seo as $key ) {
						/* translators: %s: meta key */
						$targets[] = sprintf( __( 'the theme field %s', 'spa-ai-concierge' ), $key );
					}
					if ( array() !== $targets ) {
						/* translators: %s: list of SEO plugins / fields */
						echo esc_html( sprintf( __( 'Saved here and also written to %s.', 'spa-ai-concierge' ), implode( ', ', $targets ) ) );
					} else {
						esc_html_e( 'No SEO plugin detected, so this plugin prints the title and description tags itself.', 'spa-ai-concierge' );
					}
					?>
				</p>
				<div class="spa-ai__choices" data-spa-ai-meta-choices hidden></div>
				<p class="spa-ai__field">
					<label for="spa-ai-meta-title"><?php esc_html_e( 'Meta title', 'spa-ai-concierge' ); ?></label>
					<input type="text" id="spa-ai-meta-title" name="spa_ai[meta_title]" value="<?php echo esc_attr( $meta['title'] ); ?>" class="widefat" data-spa-ai-count="<?php echo esc_attr( (string) Tasks::TITLE_LIMIT ); ?>">
					<span class="spa-ai__count" data-spa-ai-counter></span>
				</p>
				<p class="spa-ai__field">
					<label for="spa-ai-meta-desc"><?php esc_html_e( 'Meta description', 'spa-ai-concierge' ); ?></label>
					<textarea id="spa-ai-meta-desc" name="spa_ai[meta_description]" rows="3" class="widefat" data-spa-ai-count="<?php echo esc_attr( (string) Tasks::DESCRIPTION_LIMIT ); ?>"><?php echo esc_textarea( $meta['description'] ); ?></textarea>
					<span class="spa-ai__count" data-spa-ai-counter></span>
				</p>
			</section>

			<section class="spa-ai__section" aria-labelledby="spa-ai-alt-h">
				<div class="spa-ai__section-head">
					<h3 id="spa-ai-alt-h"><?php esc_html_e( 'Featured image alt text', 'spa-ai-concierge' ); ?></h3>
					<button type="button" class="button" data-spa-ai-run="alt-text"><?php esc_html_e( 'Draft alt text', 'spa-ai-concierge' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( 'Drafted from the image file name, title and caption plus this page’s topic. Check it against the actual image before saving.', 'spa-ai-concierge' ); ?></p>
				<input type="hidden" name="spa_ai[alt_attachment]" value="<?php echo esc_attr( (string) $thumb_id ); ?>" data-spa-ai-alt-attachment>
				<p class="spa-ai__field">
					<label for="spa-ai-alt"><?php esc_html_e( 'Alt text', 'spa-ai-concierge' ); ?></label>
					<input type="text" id="spa-ai-alt" name="spa_ai[alt_text]" value="<?php echo esc_attr( $alt ); ?>" class="widefat" data-spa-ai-count="<?php echo esc_attr( (string) Tasks::ALT_LIMIT ); ?>">
					<span class="spa-ai__count" data-spa-ai-counter></span>
				</p>
			</section>
		</div>
		<?php
	}

	/**
	 * One editable FAQ row.
	 *
	 * @param string $index    Row index or template placeholder.
	 * @param string $question Question.
	 * @param string $answer   Answer.
	 */
	private static function faq_row( string $index, string $question, string $answer ): void {
		?>
		<li class="spa-ai__faq" data-spa-ai-faq>
			<p class="spa-ai__field">
				<label><?php esc_html_e( 'Question', 'spa-ai-concierge' ); ?>
					<input type="text" class="widefat" name="spa_ai[faqs][<?php echo esc_attr( $index ); ?>][question]" value="<?php echo esc_attr( $question ); ?>" data-spa-ai-q>
				</label>
			</p>
			<p class="spa-ai__field">
				<label><?php esc_html_e( 'Answer', 'spa-ai-concierge' ); ?>
					<textarea class="widefat" rows="3" name="spa_ai[faqs][<?php echo esc_attr( $index ); ?>][answer]" data-spa-ai-a><?php echo esc_textarea( $answer ); ?></textarea>
				</label>
			</p>
			<p class="spa-ai__row-actions">
				<button type="button" class="button-link" data-spa-ai-move="up"><?php esc_html_e( 'Move up', 'spa-ai-concierge' ); ?></button>
				<button type="button" class="button-link" data-spa-ai-move="down"><?php esc_html_e( 'Move down', 'spa-ai-concierge' ); ?></button>
				<button type="button" class="button-link button-link-delete" data-spa-ai-remove><?php esc_html_e( 'Remove', 'spa-ai-concierge' ); ?></button>
			</p>
		</li>
		<?php
	}

	/**
	 * Save the reviewed values.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST[ self::NONCE . '_nonce' ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::post_types(), true ) || ! isset( $_POST['spa_ai'] ) || ! is_array( $_POST['spa_ai'] ) ) {
			return;
		}

		$input = wp_unslash( $_POST['spa_ai'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitized below.

		$faqs = isset( $input['faqs'] ) && is_array( $input['faqs'] ) ? array_values( $input['faqs'] ) : array();
		FaqRepository::save( $post_id, $faqs, sanitize_key( (string) ( $input['faqs_source'] ?? 'manual' ) ) );
		ThemeFields::write_faqs( $post_id, FaqRepository::get( $post_id ) );

		SeoMeta::save( $post_id, (string) ( $input['meta_title'] ?? '' ), (string) ( $input['meta_description'] ?? '' ) );

		$attachment_id = absint( $input['alt_attachment'] ?? 0 );
		if ( $attachment_id > 0 && isset( $input['alt_text'] ) && 'attachment' === get_post_type( $attachment_id ) && current_user_can( 'edit_post', $attachment_id ) ) {
			$alt = mb_substr( sanitize_text_field( (string) $input['alt_text'] ), 0, 250 );
			if ( '' !== $alt ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			}
		}
	}
}
