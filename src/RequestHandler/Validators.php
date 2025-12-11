<?php declare( strict_types=1 );
/**
 * The Validators class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\RequestHandler;

/**
 * Factory class for common validation rules.
 *
 * Provides static methods that return validator callables for use
 * with Handler::add_validator(). Each validator returns either true
 * (valid) or a ValidationError instance (invalid).
 *
 * Example usage:
 * ```php
 * $handler
 *     ->add_validator('title', Validators::required())
 *     ->add_validator('title', Validators::min_length(3))
 *     ->add_validator('count', Validators::min(0))
 *     ->add_validator('status', Validators::in(['draft', 'published']));
 * ```
 */
class Validators {

    /**
     * Create a validator that requires a non-empty value.
     *
     * Fails if the value is null or an empty string.
     * Note: 0, false, and '0' are considered valid (not empty).
     *
     * @return callable The validator callable.
     */
    public static function required(): callable {
        return function( $value ) {
            if ( $value === null || $value === '' ) {
                return new ValidationError( 'This field is required' );
            }
            return true;
        };
    }

    /**
     * Create a validator that requires a minimum string length.
     *
     * Only validates string values; non-strings pass through.
     *
     * @param int $length The minimum required length.
     * @return callable The validator callable.
     */
    public static function min_length( int $length ): callable {
        return function( $value ) use ( $length ) {
            if ( is_string( $value ) && strlen( $value ) < $length ) {
                return new ValidationError( "Must be at least {$length} characters" );
            }
            return true;
        };
    }

    /**
     * Create a validator that requires a maximum string length.
     *
     * Only validates string values; non-strings pass through.
     *
     * @param int $length The maximum allowed length.
     * @return callable The validator callable.
     */
    public static function max_length( int $length ): callable {
        return function( $value ) use ( $length ) {
            if ( is_string( $value ) && strlen( $value ) > $length ) {
                return new ValidationError( "Must be no more than {$length} characters" );
            }
            return true;
        };
    }

    /**
     * Create a validator that requires a minimum numeric value.
     *
     * Only validates numeric values; non-numerics pass through.
     *
     * @param int|float $min The minimum allowed value.
     * @return callable The validator callable.
     */
    public static function min( int|float $min ): callable {
        return function( $value ) use ( $min ) {
            if ( is_numeric( $value ) && $value < $min ) {
                return new ValidationError( "Must be at least {$min}" );
            }
            return true;
        };
    }

    /**
     * Create a validator that requires a maximum numeric value.
     *
     * Only validates numeric values; non-numerics pass through.
     *
     * @param int|float $max The maximum allowed value.
     * @return callable The validator callable.
     */
    public static function max( int|float $max ): callable {
        return function( $value ) use ( $max ) {
            if ( is_numeric( $value ) && $value > $max ) {
                return new ValidationError( "Must be no more than {$max}" );
            }
            return true;
        };
    }

    /**
     * Create a validator that requires the value to be in a list.
     *
     * Uses strict comparison (===) to check membership.
     *
     * @param array $allowed The list of allowed values.
     * @return callable The validator callable.
     */
    public static function in( array $allowed ): callable {
        return function( $value ) use ( $allowed ) {
            if ( ! in_array( $value, $allowed, true ) ) {
                $list = implode( ', ', $allowed );
                return new ValidationError( "Must be one of: {$list}" );
            }
            return true;
        };
    }

    /**
     * Create a validator that requires a valid email address.
     *
     * Empty values pass validation; use required() if the field is mandatory.
     * Uses PHP's FILTER_VALIDATE_EMAIL for validation.
     *
     * @return callable The validator callable.
     */
    public static function email(): callable {
        return function( $value ) {
            if ( ! empty( $value ) && ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
                return new ValidationError( 'Invalid email address' );
            }
            return true;
        };
    }
}
