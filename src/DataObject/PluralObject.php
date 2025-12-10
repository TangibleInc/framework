<?php declare( strict_types=1 );
/**
 * The PluralObject class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\DataObject;

/**
 * The PluralObject class is the base for objects that may exist
 * in multiple instances, like posts, pages, etc.
 */
class PluralObject extends Customer {

    /**
     * Sets the dataset to be used by these objects.
     *
     * @param DataSet $dataset the dataset to use.
     */
    public function set_dataset( DataSet $dataset ) {
        
    }
}
