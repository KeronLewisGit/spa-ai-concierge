<?php
/**
 * Builds Messages API request bodies.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Ai;

/**
 * Pure logic: produces the JSON body for POST /v1/messages.
 */
final class RequestBuilder {

	public const ENDPOINT    = 'https://api.anthropic.com/v1/messages';
	public const API_VERSION = '2023-06-01';

	/**
	 * Models that reject a forced tool_choice ("any"/"tool"). For these the tool is sent with
	 * strict: true and tool_choice auto, and the prompt names the tool. Newer IDs not listed here
	 * are handled by ApiClient, which retries once in auto mode if the API rejects tool_choice.
	 */
	private const NO_FORCED_TOOL_CHOICE = '/^claude-(fable-5-1|mythos-5-1|opus-5-5|sonnet-5-5)\b/';

	/**
	 * Whether a model accepts tool_choice {type: tool}.
	 *
	 * @param string $model Model ID.
	 */
	public static function supports_forced_tool_choice( string $model ): bool {
		return ! preg_match( self::NO_FORCED_TOOL_CHOICE, $model );
	}

	/**
	 * Build the request body.
	 *
	 * @param string                    $model      Model ID.
	 * @param int                       $max_tokens Max output tokens.
	 * @param string                    $system     System prompt.
	 * @param string|array<int, mixed>  $user       User message content (string or content blocks).
	 * @param string                    $task       Task name (selects the tool).
	 * @param bool|null                 $forced     Force tool_choice; null decides from the model ID.
	 * @return array<string, mixed>
	 */
	public static function build( string $model, int $max_tokens, string $system, $user, string $task, ?bool $forced = null ): array {
		$tool   = Tasks::tool( $task );
		$forced = $forced ?? self::supports_forced_tool_choice( $model );
		$strict = ! $forced;

		$tool_definition = array(
			'name'         => $tool['name'],
			'description'  => $tool['description'],
			'input_schema' => Tasks::schema_for_api( $tool['input_schema'], $strict ),
		);
		if ( $strict ) {
			$tool_definition['strict'] = true;
		}

		return array(
			'model'       => $model,
			'max_tokens'  => $max_tokens,
			'system'      => $system,
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			'tools'       => array( $tool_definition ),
			'tool_choice' => $forced
				? array(
					'type' => 'tool',
					'name' => $tool['name'],
				)
				: array( 'type' => 'auto' ),
		);
	}

	/**
	 * HTTP headers for the request. The key only ever travels server to server.
	 *
	 * @param string $api_key API key.
	 * @return array<string, string>
	 */
	public static function headers( string $api_key ): array {
		return array(
			'x-api-key'         => $api_key,
			'anthropic-version' => self::API_VERSION,
			'content-type'      => 'application/json',
		);
	}
}
