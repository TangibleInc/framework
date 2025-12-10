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
}
