<?php
/**
 * Plugin settings: storage, defaults and the Settings → Spa AI Concierge screen.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge;

/**
 * Wraps the single `spa_ai_concierge_settings` option.
 */
final class Settings {

	public const OPTION        = 'spa_ai_concierge_settings';
	public const PAGE_SLUG     = 'spa-ai-concierge';
	public const DEFAULT_MODEL = 'claude-haiku-4-5';
	public const KEY_CONSTANT  = 'SPA_AI_CONCIERGE_API_KEY';

	/**
	 * Default values for every setting.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'model'                  => self::DEFAULT_MODEL,
			'api_key'                => '',
			'max_tokens'             => 1024,
			'brand_voice'            => '',
			'demo_mode'              => true,
			'rate_limit'             => 20,
			'finder_post_type'       => 'treatment',
			'booking_url'            => '',
			'auto_append_faqs'       => true,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * All settings merged over defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Model ID sent to the Messages API. Any ID is accepted; blank falls back to the default.
	 */
	public static function model(): string {
		$model = trim( (string) self::get( 'model' ) );
		return '' === $model ? self::DEFAULT_MODEL : $model;
	}

	/**
	 * Max output tokens, clamped to a sane range.
	 */
	public static function max_tokens(): int {
		return max( 256, min( 8192, (int) self::get( 'max_tokens' ) ) );
	}

	/**
	 * API key: wp-config constant first, then the stored option.
	 */
	public static function api_key(): string {
		if ( defined( self::KEY_CONSTANT ) && is_string( constant( self::KEY_CONSTANT ) ) && '' !== constant( self::KEY_CONSTANT ) ) {
			return (string) constant( self::KEY_CONSTANT );
		}
		return (string) self::get( 'api_key' );
	}

	/**
	 * Where the key comes from: constant, option or none.
	 */
	public static function api_key_source(): string {
		if ( defined( self::KEY_CONSTANT ) && '' !== (string) constant( self::KEY_CONSTANT ) ) {
			return 'constant';
		}
		return '' !== (string) self::get( 'api_key' ) ? 'option' : 'none';
	}

	/**
	 * Last four characters of a key for display, never the whole thing.
	 *
	 * @param string $key Full key.
	 */
	public static function mask_key( string $key ): string {
		if ( '' === $key ) {
			return '';
		}
		return str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	/**
	 * Demo mode is on when the checkbox is ticked or no key exists at all.
	 */
	public static function is_demo_mode(): bool {
		$demo = (bool) self::get( 'demo_mode' ) || '' === self::api_key();
		/**
		 * Filters whether the assistant returns canned demo output instead of calling the API.
		 *
		 * @param bool $demo Demo mode state.
		 */
		return (bool) apply_filters( 'spa_ai_concierge_demo_mode', $demo );
	}

	/**
	 * Post type the Treatment Finder reads from. Falls back to pages if the type does not exist.
	 */
	public static function finder_post_type(): string {
		$type = sanitize_key( (string) self::get( 'finder_post_type' ) );
		if ( '' === $type ) {
			$type = 'treatment';
		}
		/**
		 * Filters the post type used by the Treatment Finder.
		 *
		 * @param string $type Post type slug.
		 */
		return (string) apply_filters( 'spa_ai_concierge_finder_post_type', $type );
	}

	/**
	 * Finder post type if registered, else `page`.
	 */
	public static function resolved_finder_post_type(): string {
		$type = self::finder_post_type();
		return post_type_exists( $type ) ? $type : 'page';
	}

	/**
	 * Hook the settings screen.
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SPA_AI_CONCIERGE_FILE ), array( self::class, 'action_links' ) );
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array<int|string, string> $links Existing links.
	 * @return array<int|string, string>
	 */
	public static function action_links( array $links ): array {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'spa-ai-concierge' ) . '</a>' );
		return $links;
	}

	/**
	 * Add Settings → Spa AI Concierge.
	 */
	public static function add_page(): void {
		add_options_page(
			__( 'Spa AI Concierge', 'spa-ai-concierge' ),
			__( 'Spa AI Concierge', 'spa-ai-concierge' ),
			'manage_options',
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Register the option with its sanitizer.
	 */
	public static function register_setting(): void {
		register_setting(
			'spa_ai_concierge',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Sanitize the submitted settings array.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$current = self::all();
		$input   = is_array( $input ) ? $input : array();
		$clean   = $current;

		$model          = isset( $input['model'] ) ? sanitize_text_field( wp_unslash( $input['model'] ) ) : '';
		$clean['model'] = preg_match( '/^[A-Za-z0-9._:\-\/@]{1,100}$/', $model ) ? $model : self::DEFAULT_MODEL;

		// Blank key field means "keep what is stored"; the checkbox clears it.
		if ( ! empty( $input['api_key_clear'] ) ) {
			$clean['api_key'] = '';
		} elseif ( isset( $input['api_key'] ) && '' !== trim( (string) $input['api_key'] ) ) {
			$clean['api_key'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) wp_unslash( $input['api_key'] ) );
		}

		$clean['max_tokens']               = isset( $input['max_tokens'] ) ? max( 256, min( 8192, absint( $input['max_tokens'] ) ) ) : 1024;
		$clean['rate_limit']               = isset( $input['rate_limit'] ) ? max( 1, min( 500, absint( $input['rate_limit'] ) ) ) : 20;
		$clean['brand_voice']              = isset( $input['brand_voice'] ) ? sanitize_textarea_field( wp_unslash( $input['brand_voice'] ) ) : '';
		$clean['brand_voice']              = mb_substr( $clean['brand_voice'], 0, 1500 );
		$clean['demo_mode']                = ! empty( $input['demo_mode'] );
		$clean['auto_append_faqs']         = ! empty( $input['auto_append_faqs'] );
		$clean['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] );
		$clean['finder_post_type']         = isset( $input['finder_post_type'] ) ? sanitize_key( $input['finder_post_type'] ) : 'treatment';
		$clean['booking_url']              = isset( $input['booking_url'] ) ? esc_url_raw( trim( (string) wp_unslash( $input['booking_url'] ) ) ) : '';

		return $clean;
	}

	/**
	 * Render the settings screen.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s          = self::all();
		$name       = self::OPTION;
		$source     = self::api_key_source();
		$post_types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $post_types['attachment'] );
		?>
		<div class="wrap spa-ai-settings">
			<h1><?php esc_html_e( 'Spa AI Concierge', 'spa-ai-concierge' ); ?></h1>

			<?php if ( self::is_demo_mode() ) : ?>
				<div class="notice notice-info inline"><p>
					<strong><?php esc_html_e( 'Demo mode is on.', 'spa-ai-concierge' ); ?></strong>
					<?php
					if ( 'none' === $source ) {
						esc_html_e( 'No API key is configured, so the editor buttons return canned sample output built from each post’s title. No requests are sent to Anthropic.', 'spa-ai-concierge' );
					} else {
						esc_html_e( 'An API key is configured, but the editor buttons return canned sample output until you untick demo mode.', 'spa-ai-concierge' );
					}
					?>
				</p></div>
			<?php endif; ?>

			<form action="options.php" method="post">
				<?php settings_fields( 'spa_ai_concierge' ); ?>

				<h2 class="title"><?php esc_html_e( 'Anthropic API', 'spa-ai-concierge' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="spa-ai-key"><?php esc_html_e( 'API key', 'spa-ai-concierge' ); ?></label></th>
						<td>
							<?php if ( 'constant' === $source ) : ?>
								<p>
									<?php
									printf(
										/* translators: 1: constant name, 2: masked key */
										esc_html__( 'Using the %1$s constant from wp-config.php (%2$s).', 'spa-ai-concierge' ),
										'<code>' . esc_html( self::KEY_CONSTANT ) . '</code>',
										'<code>' . esc_html( self::mask_key( self::api_key() ) ) . '</code>'
									);
									?>
								</p>
							<?php else : ?>
								<input type="password" id="spa-ai-key" name="<?php echo esc_attr( $name ); ?>[api_key]" value="" class="regular-text" autocomplete="off" spellcheck="false"
									placeholder="<?php echo esc_attr( 'option' === $source ? self::mask_key( (string) $s['api_key'] ) : 'sk-ant-…' ); ?>">
								<?php if ( 'option' === $source ) : ?>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[api_key_clear]" value="1"> <?php esc_html_e( 'Remove stored key', 'spa-ai-concierge' ); ?></label>
								<?php endif; ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: PHP define() example */
										esc_html__( 'Leave blank to keep the current key. Preferred: define it in wp-config.php instead so it never sits in the database: %s', 'spa-ai-concierge' ),
										'<code>define( \'' . esc_html( self::KEY_CONSTANT ) . '\', \'sk-ant-…\' );</code>'
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="spa-ai-model"><?php esc_html_e( 'Model', 'spa-ai-concierge' ); ?></label></th>
						<td>
							<input type="text" id="spa-ai-model" name="<?php echo esc_attr( $name ); ?>[model]" value="<?php echo esc_attr( (string) $s['model'] ); ?>" class="regular-text" list="spa-ai-models" spellcheck="false">
							<datalist id="spa-ai-models">
								<option value="claude-haiku-4-5"></option>
								<option value="claude-haiku-5-5"></option>
								<option value="claude-sonnet-5-5"></option>
							</datalist>
							<p class="description"><?php esc_html_e( 'Any Messages API model ID. The default, claude-haiku-4-5, is fast and inexpensive, which suits short SEO copy.', 'spa-ai-concierge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="spa-ai-max"><?php esc_html_e( 'Max output tokens', 'spa-ai-concierge' ); ?></label></th>
						<td><input type="number" id="spa-ai-max" min="256" max="8192" step="1" name="<?php echo esc_attr( $name ); ?>[max_tokens]" value="<?php echo esc_attr( (string) $s['max_tokens'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="spa-ai-rate"><?php esc_html_e( 'Requests per user per hour', 'spa-ai-concierge' ); ?></label></th>
						<td><input type="number" id="spa-ai-rate" min="1" max="500" step="1" name="<?php echo esc_attr( $name ); ?>[rate_limit]" value="<?php echo esc_attr( (string) $s['rate_limit'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="spa-ai-voice"><?php esc_html_e( 'Brand voice notes', 'spa-ai-concierge' ); ?></label></th>
						<td>
							<textarea id="spa-ai-voice" name="<?php echo esc_attr( $name ); ?>[brand_voice]" rows="4" class="large-text" maxlength="1500"><?php echo esc_textarea( (string) $s['brand_voice'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Added to every prompt. For example: “Warm, calm, second person. We are a physician-led medspa in Austin. Never promise results.”', 'spa-ai-concierge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Demo mode', 'spa-ai-concierge' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[demo_mode]" value="1" <?php checked( ! empty( $s['demo_mode'] ) ); ?>> <?php esc_html_e( 'Return canned sample output instead of calling the API', 'spa-ai-concierge' ); ?></label>
							<p class="description"><?php esc_html_e( 'Always on while no key is configured.', 'spa-ai-concierge' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Front end', 'spa-ai-concierge' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="spa-ai-cpt"><?php esc_html_e( 'Treatment post type', 'spa-ai-concierge' ); ?></label></th>
						<td>
							<select id="spa-ai-cpt" name="<?php echo esc_attr( $name ); ?>[finder_post_type]">
								<?php if ( ! isset( $post_types[ $s['finder_post_type'] ] ) ) : ?>
									<option value="<?php echo esc_attr( (string) $s['finder_post_type'] ); ?>" selected><?php echo esc_html( $s['finder_post_type'] . ' ' . __( '(not registered yet)', 'spa-ai-concierge' ) ); ?></option>
								<?php endif; ?>
								<?php foreach ( $post_types as $type ) : ?>
									<option value="<?php echo esc_attr( $type->name ); ?>" <?php selected( $s['finder_post_type'], $type->name ); ?>><?php echo esc_html( $type->labels->singular_name . ' (' . $type->name . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'The Treatment Finder recommends posts of this type, using their Concern terms and the Finder fields box. Falls back to pages if the type is not registered.', 'spa-ai-concierge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="spa-ai-booking"><?php esc_html_e( 'Booking URL', 'spa-ai-concierge' ); ?></label></th>
						<td>
							<input type="url" id="spa-ai-booking" name="<?php echo esc_attr( $name ); ?>[booking_url]" value="<?php echo esc_attr( (string) $s['booking_url'] ); ?>" class="regular-text" placeholder="https://">
							<p class="description"><?php esc_html_e( 'Where “Book a consultation” points. The treatment slug is added as ?treatment=. Blank uses a page with the slug book, booking or contact if one exists.', 'spa-ai-concierge' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'FAQs', 'spa-ai-concierge' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auto_append_faqs]" value="1" <?php checked( ! empty( $s['auto_append_faqs'] ) ); ?>> <?php esc_html_e( 'Show saved FAQs at the end of treatment posts that do not already contain the FAQ block or shortcode', 'spa-ai-concierge' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'spa-ai-concierge' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_data_on_uninstall]" value="1" <?php checked( ! empty( $s['delete_data_on_uninstall'] ) ); ?>> <?php esc_html_e( 'Also delete saved FAQs, meta suggestions, finder fields and Concern terms when the plugin is deleted', 'spa-ai-concierge' ); ?></label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
