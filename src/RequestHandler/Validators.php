<?php declare( strict_types=1 );

namespace Tangible\RequestHandler;

class Validators {

    public static function required(): callable {
        return function( $value ) {
            if ( $value === null || $value === '' ) {
                return new ValidationError( 'This field is required' );
            }
            return true;
        };
    }

    public static function min_length( int $length ): callable {
        return function( $value ) use ( $length ) {
            if ( is_string( $value ) && strlen( $value ) < $length ) {
                return new ValidationError( "Must be at least {$length} characters" );
            }
            return true;
        };
    }

    public static function max_length( int $length ): callable {
        return function( $value ) use ( $length ) {
            if ( is_string( $value ) && strlen( $value ) > $length ) {
                return new ValidationError( "Must be no more than {$length} characters" );
            }
            return true;
        };
    }

    public static function min( int|float $min ): callable {
        return function( $value ) use ( $min ) {
            if ( is_numeric( $value ) && $value < $min ) {
                return new ValidationError( "Must be at least {$min}" );
            }
            return true;
        };
    }

    public static function max( int|float $max ): callable {
        return function( $value ) use ( $max ) {
            if ( is_numeric( $value ) && $value > $max ) {
                return new ValidationError( "Must be no more than {$max}" );
            }
            return true;
        };
    }

    public static function in( array $allowed ): callable {
        return function( $value ) use ( $allowed ) {
            if ( ! in_array( $value, $allowed, true ) ) {
                $list = implode( ', ', $allowed );
                return new ValidationError( "Must be one of: {$list}" );
            }
            return true;
        };
    }

    public static function email(): callable {
        return function( $value ) {
            if ( ! empty( $value ) && ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
                return new ValidationError( 'Invalid email address' );
            }
            return true;
        };
    }
}
