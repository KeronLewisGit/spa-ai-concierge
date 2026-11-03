<?php
/**
 * The three assistant tasks: tool names, JSON schemas and output limits.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Ai;

/**
 * Each task is answered by one tool call whose input must match the task schema.
 */
final class Tasks {

	public const FAQS     = 'faqs';
	public const META     = 'meta';
	public const ALT_TEXT = 'alt_text';

	public const TITLE_LIMIT       = 60;
	public const DESCRIPTION_LIMIT = 155;
	public const ALT_LIMIT         = 125;

	/**
	 * Known task names.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array( self::FAQS, self::META, self::ALT_TEXT );
	}

	/**
	 * Tool definition for a task (name, description, input_schema).
	 *
	 * @param string $task Task name.
	 * @return array{name: string, description: string, input_schema: array<string, mixed>}
	 * @throws \InvalidArgumentException For unknown tasks.
	 */
	public static function tool( string $task ): array {
		switch ( $task ) {
			case self::FAQS:
				return array(
					'name'         => 'record_faqs',
					'description'  => 'Record 4 to 6 frequently asked questions and answers for the page, written for prospective clients.',
					'input_schema' => array(
						'type'                 => 'object',
						'properties'           => array(
							'faqs' => array(
								'type'     => 'array',
								'minItems' => 4,
								'maxItems' => 6,
								'items'    => array(
									'type'                 => 'object',
									'properties'           => array(
										'question' => array(
											'type'        => 'string',
											'minLength'   => 10,
											'maxLength'   => 200,
											'description' => 'A question a client would actually ask, ending with a question mark.',
										),
										'answer'   => array(
											'type'        => 'string',
											'minLength'   => 20,
											'maxLength'   => 700,
											'description' => 'A plain-text answer of 1 to 3 sentences. No HTML or markdown.',
										),
									),
									'required'             => array( 'question', 'answer' ),
									'additionalProperties' => false,
								),
							),
						),
						'required'             => array( 'faqs' ),
						'additionalProperties' => false,
					),
				);

			case self::META:
				$options = static function ( int $min, int $max, string $description ): array {
					return array(
						'type'        => 'array',
						'minItems'    => 3,
						'maxItems'    => 3,
						'description' => $description,
						'items'       => array(
							'type'      => 'string',
							'minLength' => $min,
							'maxLength' => $max,
						),
					);
				};
				return array(
					'name'         => 'record_meta_suggestions',
					'description'  => 'Record exactly three SEO title options and three meta description options for the page.',
					'input_schema' => array(
						'type'                 => 'object',
						'properties'           => array(
							'titles'       => $options( 10, 120, 'Three distinct title tag options, ideally 50 to 60 characters each.' ),
							'descriptions' => $options( 50, 320, 'Three distinct meta descriptions, ideally 140 to 155 characters each.' ),
						),
						'required'             => array( 'titles', 'descriptions' ),
						'additionalProperties' => false,
					),
				);

			case self::ALT_TEXT:
				return array(
					'name'         => 'record_alt_text',
					'description'  => 'Record alt text for the featured image.',
					'input_schema' => array(
						'type'                 => 'object',
						'properties'           => array(
							'alt_text' => array(
								'type'        => 'string',
								'minLength'   => 5,
								'maxLength'   => 250,
								'description' => 'Concise alt text under 125 characters. Do not start with "Image of" or "Photo of".',
							),
						),
						'required'             => array( 'alt_text' ),
						'additionalProperties' => false,
					),
				);
		}

		throw new \InvalidArgumentException( 'Unknown task: ' . esc_html( $task ) );
	}

	/**
	 * Schema as sent to the API. Strict tool mode only accepts a subset of JSON Schema,
	 * so length and count bounds are removed there; they are still enforced locally.
	 *
	 * @param array<string, mixed> $schema Full schema.
	 * @param bool                 $strict Whether the tool is sent with strict: true.
	 * @return array<string, mixed>
	 */
	public static function schema_for_api( array $schema, bool $strict ): array {
		if ( ! $strict ) {
			return $schema;
		}
		foreach ( array( 'minItems', 'maxItems', 'minLength', 'maxLength' ) as $keyword ) {
			unset( $schema[ $keyword ] );
		}
		foreach ( array( 'properties' ) as $container ) {
			if ( isset( $schema[ $container ] ) && is_array( $schema[ $container ] ) ) {
				foreach ( $schema[ $container ] as $key => $child ) {
					$schema[ $container ][ $key ] = self::schema_for_api( $child, true );
				}
			}
		}
		if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$schema['items'] = self::schema_for_api( $schema['items'], true );
		}
		return $schema;
	}

	/**
	 * Character counts and over-limit flags for meta suggestions.
	 *
	 * @param string[] $titles       Title options.
	 * @param string[] $descriptions Description options.
	 * @return array{titles: array<int, array{text: string, length: int, over: bool}>, descriptions: array<int, array{text: string, length: int, over: bool}>, limits: array{title: int, description: int}}
	 */
	public static function annotate_meta( array $titles, array $descriptions ): array {
		$annotate = static function ( array $items, int $limit ): array {
			return array_map(
				static function ( $text ) use ( $limit ) {
					$text   = (string) $text;
					$length = mb_strlen( $text );
					return array(
						'text'   => $text,
						'length' => $length,
						'over'   => $length > $limit,
					);
				},
				array_values( $items )
			);
		};

		return array(
			'titles'       => $annotate( $titles, self::TITLE_LIMIT ),
			'descriptions' => $annotate( $descriptions, self::DESCRIPTION_LIMIT ),
			'limits'       => array(
				'title'       => self::TITLE_LIMIT,
				'description' => self::DESCRIPTION_LIMIT,
			),
		);
	}
}
