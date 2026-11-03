<?php
/**
 * Runs an assistant task for a post, in demo mode or against the Messages API.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Ai;

use KeronLewis\SpaAiConcierge\Settings;

/**
 * Orchestrates context → prompt → API (or demo) → validated output.
 */
final class ContentAssistant {

	/**
	 * Run a task.
	 *
	 * @param string               $task      Task name.
	 * @param \WP_Post             $post      Post.
	 * @param array<string, mixed> $overrides Unsaved editor values.
	 * @param int                  $image_id  Attachment for alt text.
	 * @return array{data: array<string, mixed>, demo: bool, model: string, usage: array<string, int>}
	 * @throws AiException On failure.
	 */
	public static function run( string $task, \WP_Post $post, array $overrides = array(), int $image_id = 0 ): array {
		$context = ContextBuilder::for_post( $post, $overrides, $image_id );

		if ( Tasks::ALT_TEXT === $task && empty( $context['image'] ) ) {
			throw new AiException( 'no_image', __( 'Set a featured image first, then draft its alt text.', 'spa-ai-concierge' ), 400 );
		}

		if ( Settings::is_demo_mode() ) {
			return array(
				'data'  => ResponseParser::validate( DemoResponder::respond( $task, $context ), $task ),
				'demo'  => true,
				'model' => 'demo',
				'usage' => array(
					'input_tokens'  => 0,
					'output_tokens' => 0,
				),
			);
		}

		/**
		 * Filters the system prompt.
		 *
		 * @param string               $system  Prompt.
		 * @param string               $task    Task name.
		 * @param array<string, mixed> $context Post context.
		 */
		$system = (string) apply_filters( 'spa_ai_concierge_system_prompt', PromptBuilder::system( $task, $context ), $task, $context );

		/**
		 * Filters the user message content blocks. Use this to add an image block for alt text
		 * if you want a vision-capable request, for example:
		 * array( 'type' => 'image', 'source' => array( 'type' => 'url', 'url' => $url ) ).
		 *
		 * @param array<int, array<string, mixed>> $content Content blocks.
		 * @param string                           $task    Task name.
		 * @param array<string, mixed>             $context Post context.
		 */
		$user = (array) apply_filters( 'spa_ai_concierge_user_content', PromptBuilder::user( $task, $context ), $task, $context );

		$client = new ApiClient( Settings::api_key() );
		$result = $client->run( $task, Settings::model(), Settings::max_tokens(), $system, $user );

		return array(
			'data'  => $result['data'],
			'demo'  => false,
			'model' => $result['model'],
			'usage' => $result['usage'],
		);
	}
}
