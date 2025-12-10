<?php declare( strict_types=1 );
/**
 * The DataSet class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\DataObject;

/**
 * The DataSet is the foundation to the data definition layer of Tangible Object.
 */
class DataSet {

    public const TYPE_STRING = 'string';
    public const TYPE_INTEGER = 'int';
    public const TYPE_BOOLEAN = 'bool';

    /**
     * @var array
     */
    protected $fields;

    /**
     * Get defined fields.
     *
     * @return Array defined fields.
     */
    public function get_fields(): array {
        return $this->fields;
    }

    /**
     * Add a boolean based field to the dataset.
     *
     * @param String $slug the field name.
     */
    public function add_boolean( string $slug ) {
        $this->fields[ $slug ] = [
            'type' => self::TYPE_BOOLEAN,
        ];
        return $this;
    }

    /**
     * Add an integer based field to the dataset.
     *
     * @param String $slug the field name.
     */
    public function add_integer( string $slug ) {
        $this->fields[ $slug ] = [
            'type' => self::TYPE_INTEGER,
        ];
        return $this;
    }

    /**
     * Add a string based field to the dataset.
     *
     * @param String $slug the field name.
     */
    public function add_string( string $slug ) {
        $this->fields[ $slug ] = [
            'type' => self::TYPE_STRING,
        ];
        return $this;
    }

    /**
     * Coerce a value to the correct type based on field definition.
     *
     * @param string $slug the field name.
     * @param mixed $value the value to coerce.
     * @return mixed the coerced value.
     */
    public function coerce( string $slug, mixed $value ): mixed {
        if ( ! isset( $this->fields[ $slug ] ) ) {
            return $value;
        }

        $type = $this->fields[ $slug ]['type'];

        return match ( $type ) {
            self::TYPE_STRING  => (string) $value,
            self::TYPE_INTEGER => (int) $value,
            self::TYPE_BOOLEAN => $this->coerce_boolean( $value ),
            default            => $value,
        };
    }

    /**
     * Coerce a value to boolean.
     *
     * @param mixed $value the value to coerce.
     * @return bool the coerced value.
     */
    private function coerce_boolean( mixed $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_string( $value ) ) {
            $value = strtolower( $value );
            return in_array( $value, [ '1', 'true', 'yes', 'on' ], true );
        }

        return (bool) $value;
    }
}
