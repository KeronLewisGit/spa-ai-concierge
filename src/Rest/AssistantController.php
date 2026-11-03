<?php
/**
 * REST routes for the editor assistant: spa-ai-concierge/v1/posts/{id}/{faqs|meta|alt-text}.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Rest;

use KeronLewis\SpaAiConcierge\Ai\AiException;
use KeronLewis\SpaAiConcierge\Ai\ContentAssistant;
use KeronLewis\SpaAiConcierge\Ai\Tasks;
use KeronLewis\SpaAiConcierge\Settings;
use KeronLewis\SpaAiConcierge\Support\Log;
use KeronLewis\SpaAiConcierge\Support\RateLimiter;

/**
 * Generation only: nothing here writes post content or meta. Editors review the output in the
 * meta box and it is saved with the post.
 *
 * Cookie-authenticated requests need the X-WP-Nonce header (wp_rest nonce), which core checks
 * before permission_callback runs. Application passwords also work.
 */
final class AssistantController {

	public const NAMESPACE = 'spa-ai-concierge/v1';

	private const ROUTES = array(
		'faqs'     => Tasks::FAQS,
		'meta'     => Tasks::META,
		'alt-text' => Tasks::ALT_TEXT,
	);

	/**
	 * Hooks.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register routes.
	 */
	public static function register_routes(): void {
		foreach ( self::ROUTES as $path => $task ) {
			register_rest_route(
				self::NAMESPACE,
				'/posts/(?P<id>\d+)/' . $path,
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => static fn( \WP_REST_Request $request ) => self::handle( $request, $task ),
					'permission_callback' => array( self::class, 'can_edit' ),
					'args'                => array(
						'id'            => array(
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'title'         => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'content'       => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => static fn( $value ) => wp_kses_post( (string) $value ),
							'validate_callback' => static fn( $value ) => is_string( $value ) && strlen( $value ) <= 200000,
						),
						'excerpt'       => array(
							'type'              => 'string',
							'required'          => false,
							'default'           => '',
							'sanitize_callback' => 'sanitize_textarea_field',
						),
						'attachment_id' => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'minimum'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				)
			);
		}
	}

	/**
	 * Permission: the user must be able to edit this specific post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public static function can_edit( \WP_REST_Request $request ) {
		$post_id = absint( $request['id'] );
		$post    = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'spa_ai_not_found', __( 'Post not found.', 'spa-ai-concierge' ), array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'spa_ai_forbidden', __( 'You are not allowed to edit this post.', 'spa-ai-concierge' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/**
	 * Run a task.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param string           $task    Task name.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( \WP_REST_Request $request, string $task ) {
		$post = get_post( absint( $request['id'] ) );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'spa_ai_not_found', __( 'Post not found.', 'spa-ai-concierge' ), array( 'status' => 404 ) );
		}

		$image_id = absint( $request['attachment_id'] );
		if ( $image_id > 0 && ( 'attachment' !== get_post_type( $image_id ) || ! current_user_can( 'edit_post', $image_id ) ) ) {
			$image_id = 0;
		}

		/**
		 * Filters the per-user hourly request limit for assistant calls.
		 *
		 * @param int $limit Requests per hour.
		 */
		$limit   = (int) apply_filters( 'spa_ai_concierge_rate_limit', (int) Settings::get( 'rate_limit' ) );
		$limiter = new RateLimiter( 'ai', $limit, HOUR_IN_SECONDS );
		$hit     = $limiter->hit( 'user-' . get_current_user_id() );
		if ( ! $hit['allowed'] ) {
			return new \WP_Error(
				'spa_ai_rate_limited',
				sprintf(
					/* translators: 1: request limit, 2: minutes to wait */
					__( 'You have used all %1$d assistant requests for this hour. Try again in about %2$d minutes.', 'spa-ai-concierge' ),
					$hit['limit'],
					(int) ceil( $hit['retry_after'] / 60 )
				),
				array(
					'status'      => 429,
					'retry_after' => $hit['retry_after'],
				)
			);
		}

		try {
			$result = ContentAssistant::run(
				$task,
				$post,
				array(
					'title'   => (string) $request['title'],
					'content' => (string) $request['content'],
					'excerpt' => (string) $request['excerpt'],
				),
				$image_id
			);
		} catch ( AiException $e ) {
			Log::error(
				'Assistant task failed',
				array(
					'task'   => $task,
					'post'   => $post->ID,
					'code'   => $e->error_code(),
					'detail' => $e->detail(),
				)
			);
			return new \WP_Error( 'spa_ai_' . $e->error_code(), $e->getMessage(), array( 'status' => $e->status() ) );
		}

		$response = new \WP_REST_Response(
			array(
				'task'      => $task,
				'demo'      => $result['demo'],
				'model'     => $result['model'],
				'data'      => $result['data'],
				'usage'     => $result['usage'],
				'remaining' => $hit['remaining'],
			)
		);
		$response->header( 'X-RateLimit-Remaining', (string) $hit['remaining'] );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
