<?php declare( strict_types=1 );
/**
 * The SingularObject class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\DataObject;

/**
 * The SingularObject class is the base for objects that need to exist
 * in a single instance, like plugin settings.
 */
class SingularObject {

    /**
     * Sets the dataset to be used by this singular object.
     *
     * @param DataSet $dataset the dataset to use.
     */
    public function set_dataset( DataSet $dataset ) {
        
    }

    public function get_storage(): StorageAdapter {
        
    }
}
