<?php declare( strict_types=1 );

namespace Tangible\DataObject;

abstract class Customer {
    protected Storage $storage;

    public function set( string $slug, mixed $value ): Customer {
        $this->storage->set( $slug, $value );
        return $this;
    }

    public function get( string $slug ): mixed {
        return $this->storage->get( $slug );
    }

    public function load(): Customer {
        $this->storage->load();
        return $this;
    }

    public function save(): Customer {
        $this->storage->save();
        return $this;
    }

    public function get_storage(): Storage {
        return $this->storage;
    }
}
