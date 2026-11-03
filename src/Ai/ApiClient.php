<?php
/**
 * Sends requests to the Anthropic Messages API with wp_remote_post.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Ai;

use KeronLewis\SpaAiConcierge\Support\Log;

/**
 * Thin HTTP layer. Prompt building and response parsing live in pure classes.
 */
final class ApiClient {

	/**
	 * API key (server side only).
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key API key.
	 */
	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Run a task and return the validated output plus usage.
	 *
	 * @param string            $task       Task name.
	 * @param string            $model      Model ID.
	 * @param int               $max_tokens Max output tokens.
	 * @param string            $system     System prompt.
	 * @param array<int, mixed> $user       User content blocks.
	 * @return array{data: array<string, mixed>, usage: array<string, int>, model: string}
	 * @throws AiException On a missing key, transport, HTTP or validation errors.
	 */
	public function run( string $task, string $model, int $max_tokens, string $system, array $user ): array {
		if ( '' === $this->api_key ) {
			throw new AiException( 'no_key', __( 'No Anthropic API key is configured.', 'spa-ai-concierge' ), 400 );
		}

		$payload  = RequestBuilder::build( $model, $max_tokens, $system, $user, $task );
		$response = $this->post( $payload );

		// A model that rejects forced tool use answers 400; retry once with tool_choice auto.
		if ( 400 === $response['status'] && 'tool' === ( $payload['tool_choice']['type'] ?? '' ) && false !== stripos( $response['body'], 'tool_choice' ) ) {
			Log::debug( 'Model rejected forced tool_choice; retrying with auto.', array( 'model' => $model ) );
			$payload  = RequestBuilder::build( $model, $max_tokens, $system, $user, $task, false );
			$response = $this->post( $payload );
		}

		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			$error = self::http_error( $response['status'], $response['body'] );
			throw $error;
		}

		$decoded = json_decode( $response['body'], true );
		if ( ! is_array( $decoded ) ) {
			throw new AiException( 'invalid_response', __( 'The AI service returned a response that was not valid JSON.', 'spa-ai-concierge' ), 502 );
		}

		return array(
			'data'  => ResponseParser::parse( $decoded, $task ),
			'usage' => array(
				'input_tokens'  => (int) ( $decoded['usage']['input_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $decoded['usage']['output_tokens'] ?? 0 ),
			),
			'model' => (string) ( $decoded['model'] ?? $model ),
		);
	}

	/**
	 * POST the payload.
	 *
	 * @param array<string, mixed> $payload Request body.
	 * @return array{status: int, body: string}
	 * @throws AiException On transport errors (DNS, TLS, timeout).
	 */
	private function post( array $payload ): array {
		/**
		 * Filters the request timeout in seconds for Messages API calls.
		 *
		 * @param int $timeout Seconds.
		 */
		$timeout = (int) apply_filters( 'spa_ai_concierge_request_timeout', 45 );

		$result = wp_remote_post(
			RequestBuilder::ENDPOINT,
			array(
				'timeout'     => max( 5, $timeout ),
				'redirection' => 0,
				'headers'     => RequestBuilder::headers( $this->api_key ),
				'body'        => wp_json_encode( $payload ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $result ) ) {
			Log::error( 'Messages API transport error', array( 'error' => $result->get_error_message() ) );
			$timed_out = false !== stripos( $result->get_error_message(), 'timed out' );
			throw new AiException(
				$timed_out ? 'timeout' : 'transport_error',
				$timed_out
					? __( 'The AI service took too long to respond. Try again in a moment.', 'spa-ai-concierge' )
					: __( 'Could not reach the AI service from this server. Check the site’s outbound connections and try again.', 'spa-ai-concierge' ),
				504
			);
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $result ),
			'body'   => (string) wp_remote_retrieve_body( $result ),
		);
	}

	/**
	 * Map an HTTP error to an editor-friendly exception. The upstream message is logged only.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 */
	public static function http_error( int $status, string $body ): AiException {
		$decoded  = json_decode( $body, true );
		$upstream = is_array( $decoded ) ? (string) ( $decoded['error']['message'] ?? '' ) : '';
		Log::error(
			'Messages API HTTP error',
			array(
				'status'  => $status,
				'message' => $upstream,
			)
		);

		switch ( true ) {
			case 401 === $status:
				return new AiException( 'auth', __( 'The Anthropic API key was rejected. Check the key in Settings → Spa AI Concierge or wp-config.php.', 'spa-ai-concierge' ), 502, $upstream );
			case 403 === $status:
				return new AiException( 'forbidden', __( 'This API key is not allowed to use the selected model.', 'spa-ai-concierge' ), 502, $upstream );
			case 404 === $status:
				return new AiException( 'model_not_found', __( 'The model ID in Settings → Spa AI Concierge was not found. Check it for typos.', 'spa-ai-concierge' ), 502, $upstream );
			case 413 === $status:
				return new AiException( 'too_large', __( 'This page is too long to send in one request.', 'spa-ai-concierge' ), 502, $upstream );
			case 429 === $status:
				return new AiException( 'upstream_rate_limited', __( 'The AI service is rate limiting this account. Wait a minute and try again.', 'spa-ai-concierge' ), 503, $upstream );
			case 529 === $status || $status >= 500:
				return new AiException( 'upstream_unavailable', __( 'The AI service is temporarily unavailable. Try again in a minute.', 'spa-ai-concierge' ), 503, $upstream );
			default:
				return new AiException( 'bad_request', __( 'The AI service could not process this request. Check the model and max tokens settings.', 'spa-ai-concierge' ), 502, $upstream );
		}
	}
}
