<?php declare( strict_types=1 );
/**
 * The SingularObject class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\DataObject;

use Tangible\DataObject\Storage\OptionStorage;

/**
 * The SingularObject class is the base for objects that need to exist
 * in a single instance, like plugin settings.
 */
class SingularObject extends Customer {

    protected string $slug;
    protected DataSet $data;

    /**
     * Creates a new singular object with optional storage.
     *
     * @param string $slug the object slug.
     * @param Storage|null $storage the storage object to use.
     */
    public function __construct( string $slug, Storage|null $storage = null ) {
        $this->slug = $slug;

        if ( $storage !== null ) {
            $this->storage = $storage;
        } else {
            $this->storage = new OptionStorage( $slug );
        }
    }

    /**
     * Sets the dataset to be used by this singular object.
     *
     * @param DataSet $dataset the dataset to use.
     */
    public function set_dataset( DataSet $dataset ) {
        $this->data = $dataset;
        return $this;
    }

    /**
     * Returns the dataset used by this singular object.
     *
     * @return DataSet the used dataset.
     */
    public function get_dataset(): DataSet {
        return $this->data;
    }
}
