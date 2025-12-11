<?php declare( strict_types=1 );

namespace Tangible\DataObject\Storage;

use Tangible\DataObject\SingularStorage;

class OptionStorage implements SingularStorage {
    protected array $values = [];
    protected string $slug;

    public function __construct( string $slug ) {
        $this->slug = $slug;
    }

    public function set( string $slug, mixed $value ): void {
        $this->values[ $slug ] = $value;
    }

    public function get( string $slug ): mixed {
        return $this->values[ $slug ] ?? null;
    }

    public function save(): void {
        update_option( $this->slug, $this->values );
    }

    public function load(): void {
        $this->values = get_option( $this->slug, [] );
    }
}
