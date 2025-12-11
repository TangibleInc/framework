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
class SingularObject extends BaseDataObject {

    protected SingularStorage $storage;
    protected string $slug;

    /**
     * Creates a new singular object with optional storage.
     *
     * @param string $slug the object slug.
     * @param SingularStorage|null $storage the storage object to use.
     */
    public function __construct( string $slug, SingularStorage|null $storage = null ) {
        $this->slug = $slug;

        if ( $storage !== null ) {
            $this->storage = $storage;
        } else {
            $this->storage = new OptionStorage( $slug );
        }
    }

    public function get_storage(): SingularStorage {
        return $this->storage;
    }

    public function set( string $slug, mixed $value ): BaseDataObject {
        if ( isset( $this->data ) ) {
            $value = $this->data->coerce( $slug, $value );
        }
        $this->storage->set( $slug, $value );
        return $this;
    }

    public function get( string $slug ): mixed {
        $value = $this->storage->get( $slug );
        if ( isset( $this->data ) && $value !== null ) {
            $value = $this->data->coerce( $slug, $value );
        }
        return $value;
    }

    public function load(): BaseDataObject {
        $this->storage->load();
        return $this;
    }

    public function save(): BaseDataObject {
        $this->storage->save();
        return $this;
    }
}
