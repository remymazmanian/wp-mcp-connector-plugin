<?php
/**
 * JSON Schema helpers.
 *
 * @package WPMCP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small builders for the JSON Schema fragments the tool definitions need, plus
 * a validator strict enough to keep malformed model output from reaching
 * WordPress APIs.
 */
class WPMCP_Schema {

	/**
	 * Builds an object schema.
	 *
	 * @param array<string,array<string,mixed>> $properties Property schemas.
	 * @param string[]                          $required   Required property names.
	 * @return array<string,mixed>
	 */
	public static function object( array $properties, array $required = array() ) {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);

		if ( $required ) {
			$schema['required'] = array_values( $required );
		}

		return $schema;
	}

	/**
	 * Builds a string schema.
	 *
	 * @param string   $description Human description shown to the model.
	 * @param string[] $enum        Optional allowed values.
	 * @return array<string,mixed>
	 */
	public static function string( $description, array $enum = array() ) {
		$schema = array(
			'type'        => 'string',
			'description' => $description,
		);

		if ( $enum ) {
			$schema['enum'] = array_values( $enum );
		}

		return $schema;
	}

	/**
	 * Builds an integer schema.
	 *
	 * @param string   $description Description.
	 * @param int|null $min         Minimum.
	 * @param int|null $max         Maximum.
	 * @return array<string,mixed>
	 */
	public static function integer( $description, $min = null, $max = null ) {
		$schema = array(
			'type'        => 'integer',
			'description' => $description,
		);

		if ( null !== $min ) {
			$schema['minimum'] = $min;
		}

		if ( null !== $max ) {
			$schema['maximum'] = $max;
		}

		return $schema;
	}

	/**
	 * Builds a boolean schema.
	 *
	 * @param string $description Description.
	 * @return array<string,mixed>
	 */
	public static function boolean( $description ) {
		return array(
			'type'        => 'boolean',
			'description' => $description,
		);
	}

	/**
	 * Builds an array schema.
	 *
	 * @param string              $description Description.
	 * @param array<string,mixed> $items       Item schema.
	 * @return array<string,mixed>
	 */
	public static function arr( $description, array $items ) {
		return array(
			'type'        => 'array',
			'description' => $description,
			'items'       => $items,
		);
	}

	/**
	 * Ensures a schema serialises as a JSON object rather than an empty array.
	 *
	 * An empty `properties` list encodes as `[]` in PHP's json_encode, which is
	 * invalid JSON Schema and makes strict clients reject the tool.
	 *
	 * @param array<string,mixed> $schema Schema.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $schema ) {
		if ( isset( $schema['properties'] ) && is_array( $schema['properties'] ) && empty( $schema['properties'] ) ) {
			$schema['properties'] = new stdClass();
		}

		return $schema;
	}

	/**
	 * Validates and coerces tool arguments against an input schema.
	 *
	 * Deliberately narrow: it enforces the parts of JSON Schema the tools
	 * actually use (type, enum, required, bounds, unknown keys) and coerces the
	 * scalar types models routinely get wrong, such as "12" for an integer.
	 *
	 * @param array<string,mixed> $args   Raw arguments.
	 * @param array<string,mixed> $schema Input schema.
	 * @return array<string,mixed>|WP_Error Coerced arguments, or a descriptive error.
	 */
	public static function validate( array $args, array $schema ) {
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		$required   = isset( $schema['required'] ) ? (array) $schema['required'] : array();
		$clean      = array();

		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] || '' === $args[ $key ] ) {
				return new WP_Error(
					'wpmcp_missing_argument',
					sprintf(
						/* translators: 1: argument name, 2: comma separated list of required arguments. */
						__( 'Missing required argument "%1$s". This tool requires: %2$s.', 'wp-mcp-connector' ),
						$key,
						implode( ', ', $required )
					)
				);
			}
		}

		$unknown = array_diff( array_keys( $args ), array_keys( $properties ) );

		if ( $unknown && isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
			return new WP_Error(
				'wpmcp_unknown_argument',
				sprintf(
					/* translators: 1: unknown argument names, 2: accepted argument names. */
					__( 'Unrecognised argument(s): %1$s. This tool accepts: %2$s.', 'wp-mcp-connector' ),
					implode( ', ', $unknown ),
					implode( ', ', array_keys( $properties ) ) ? implode( ', ', array_keys( $properties ) ) : __( '(no arguments)', 'wp-mcp-connector' )
				)
			);
		}

		foreach ( $properties as $key => $property ) {
			if ( ! array_key_exists( $key, $args ) ) {
				continue;
			}

			$value = self::coerce( $args[ $key ], $property );

			if ( is_wp_error( $value ) ) {
				return new WP_Error(
					'wpmcp_invalid_argument',
					sprintf(
						/* translators: 1: argument name, 2: reason. */
						__( 'Invalid value for "%1$s": %2$s', 'wp-mcp-connector' ),
						$key,
						$value->get_error_message()
					)
				);
			}

			$clean[ $key ] = $value;
		}

		return $clean;
	}

	/**
	 * Coerces one value to its declared type.
	 *
	 * @param mixed               $value    Raw value.
	 * @param array<string,mixed> $property Property schema.
	 * @return mixed|WP_Error
	 */
	private static function coerce( $value, array $property ) {
		$type = isset( $property['type'] ) ? $property['type'] : 'string';

		switch ( $type ) {
			case 'integer':
				if ( is_bool( $value ) || ( ! is_numeric( $value ) && ! is_int( $value ) ) ) {
					return new WP_Error( 'type', __( 'expected a whole number.', 'wp-mcp-connector' ) );
				}

				$value = (int) $value;

				if ( isset( $property['minimum'] ) && $value < $property['minimum'] ) {
					/* translators: %d: minimum allowed value. */
					return new WP_Error( 'range', sprintf( __( 'must be at least %d.', 'wp-mcp-connector' ), $property['minimum'] ) );
				}

				if ( isset( $property['maximum'] ) && $value > $property['maximum'] ) {
					/* translators: %d: maximum allowed value. */
					return new WP_Error( 'range', sprintf( __( 'must be at most %d.', 'wp-mcp-connector' ), $property['maximum'] ) );
				}

				return $value;

			case 'number':
				if ( ! is_numeric( $value ) ) {
					return new WP_Error( 'type', __( 'expected a number.', 'wp-mcp-connector' ) );
				}

				return (float) $value;

			case 'boolean':
				if ( is_bool( $value ) ) {
					return $value;
				}

				if ( in_array( $value, array( 'true', '1', 1, 'yes' ), true ) ) {
					return true;
				}

				if ( in_array( $value, array( 'false', '0', 0, 'no', '' ), true ) ) {
					return false;
				}

				return new WP_Error( 'type', __( 'expected true or false.', 'wp-mcp-connector' ) );

			case 'array':
				if ( ! is_array( $value ) ) {
					// Models frequently send a comma separated string where a list
					// was asked for. Accepting that costs nothing and saves a turn.
					if ( is_string( $value ) && '' !== $value ) {
						$value = array_map( 'trim', explode( ',', $value ) );
					} else {
						return new WP_Error( 'type', __( 'expected an array.', 'wp-mcp-connector' ) );
					}
				}

				if ( isset( $property['items'] ) ) {
					foreach ( $value as $index => $item ) {
						$coerced = self::coerce( $item, $property['items'] );

						if ( is_wp_error( $coerced ) ) {
							return $coerced;
						}

						$value[ $index ] = $coerced;
					}
				}

				return array_values( $value );

			case 'object':
				if ( is_object( $value ) ) {
					$value = (array) $value;
				}

				if ( ! is_array( $value ) ) {
					return new WP_Error( 'type', __( 'expected an object.', 'wp-mcp-connector' ) );
				}

				return $value;

			default:
				if ( is_array( $value ) || is_object( $value ) ) {
					return new WP_Error( 'type', __( 'expected a string.', 'wp-mcp-connector' ) );
				}

				$value = (string) $value;

				if ( isset( $property['enum'] ) && ! in_array( $value, (array) $property['enum'], true ) ) {
					return new WP_Error(
						'enum',
						sprintf(
							/* translators: %s: comma separated allowed values. */
							__( 'must be one of: %s.', 'wp-mcp-connector' ),
							implode( ', ', (array) $property['enum'] )
						)
					);
				}

				return $value;
		}
	}
}
