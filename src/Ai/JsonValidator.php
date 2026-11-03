<?php
/**
 * Minimal JSON Schema validator covering the keywords this plugin's schemas use.
 *
 * Supported: type (object, array, string, integer, number, boolean), properties, required,
 * additionalProperties (false), items, minItems, maxItems, minLength, maxLength, enum.
 *
 * @package SpaAiConcierge
 */

namespace KeronLewis\SpaAiConcierge\Ai;

/**
 * Validates decoded JSON (PHP arrays/scalars) against a schema array.
 */
final class JsonValidator {

	/**
	 * Validate a value. Returns a list of human-readable errors; empty means valid.
	 *
	 * @param mixed                $value  Decoded value.
	 * @param array<string, mixed> $schema Schema.
	 * @param string               $path   JSON pointer-ish path for messages.
	 * @return string[]
	 */
	public static function validate( $value, array $schema, string $path = '$' ): array {
		$errors = array();
		$type   = $schema['type'] ?? null;

		if ( null !== $type && ! self::matches_type( $value, (string) $type ) ) {
			return array( sprintf( '%s should be %s, got %s', $path, $type, self::describe( $value ) ) );
		}

		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$errors[] = sprintf( '%s is not one of the allowed values', $path );
		}

		if ( 'object' === $type ) {
			$properties = $schema['properties'] ?? array();
			foreach ( (array) ( $schema['required'] ?? array() ) as $required ) {
				if ( ! array_key_exists( $required, $value ) ) {
					$errors[] = sprintf( '%s.%s is required', $path, $required );
				}
			}
			foreach ( $value as $key => $child ) {
				if ( isset( $properties[ $key ] ) ) {
					$errors = array_merge( $errors, self::validate( $child, $properties[ $key ], $path . '.' . $key ) );
				} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
					$errors[] = sprintf( '%s.%s is not allowed', $path, $key );
				}
			}
		}

		if ( 'array' === $type ) {
			$count = count( $value );
			if ( isset( $schema['minItems'] ) && $count < (int) $schema['minItems'] ) {
				$errors[] = sprintf( '%s needs at least %d items, got %d', $path, $schema['minItems'], $count );
			}
			if ( isset( $schema['maxItems'] ) && $count > (int) $schema['maxItems'] ) {
				$errors[] = sprintf( '%s allows at most %d items, got %d', $path, $schema['maxItems'], $count );
			}
			if ( isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
				foreach ( array_values( $value ) as $i => $item ) {
					$errors = array_merge( $errors, self::validate( $item, $schema['items'], $path . '[' . $i . ']' ) );
				}
			}
		}

		if ( 'string' === $type ) {
			$length = mb_strlen( trim( $value ) );
			if ( isset( $schema['minLength'] ) && $length < (int) $schema['minLength'] ) {
				$errors[] = sprintf( '%s is too short (%d characters, minimum %d)', $path, $length, $schema['minLength'] );
			}
			if ( isset( $schema['maxLength'] ) && $length > (int) $schema['maxLength'] ) {
				$errors[] = sprintf( '%s is too long (%d characters, maximum %d)', $path, $length, $schema['maxLength'] );
			}
		}

		return $errors;
	}

	/**
	 * Type check that treats PHP's array ambiguity explicitly (list vs map).
	 *
	 * @param mixed  $value Value.
	 * @param string $type  JSON Schema type.
	 */
	private static function matches_type( $value, string $type ): bool {
		switch ( $type ) {
			case 'object':
				return is_array( $value ) && ( array() === $value || ! array_is_list( $value ) );
			case 'array':
				return is_array( $value ) && array_is_list( $value );
			case 'string':
				return is_string( $value );
			case 'integer':
				return is_int( $value );
			case 'number':
				return is_int( $value ) || is_float( $value );
			case 'boolean':
				return is_bool( $value );
			default:
				return true;
		}
	}

	/**
	 * Short type description for error messages.
	 *
	 * @param mixed $value Value.
	 */
	private static function describe( $value ): string {
		if ( is_array( $value ) ) {
			return array_is_list( $value ) && array() !== $value ? 'array' : 'object';
		}
		return gettype( $value );
	}
}
