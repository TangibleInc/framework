<?php declare( strict_types=1 );

namespace Tangible\DataObject\PluralObject;

class Entity {
    protected int $id;
    protected array $values;

    public function __construct( ?array $data = null ) {
        if ( $data !== null ) {
            $this->values = $data;
        }
    }

    public function set_id( int $id ): Entity {
        $this->id = $id;
        return $this;
    }

    public function get_id(): int|null {
        return $this->id ?? null;
    }

    public function get( string $property ): mixed {
        return $this->values[ $property ] ?? null;
    }

    public function set( string $property, mixed $value ): Entity {
        $this->values[ $property ] = $value;
        return $this;
    }

    public function get_data(): array {
        return $this->values;
    }

    public function set_data( array $data ): Entity {
        $this->values = $data;
        return $this;
    }
}
