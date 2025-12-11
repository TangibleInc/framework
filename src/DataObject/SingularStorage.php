<?php declare( strict_types=1 );

namespace Tangible\DataObject;

interface SingularStorage {

    public function set( string $slug, mixed $value ): void;
    public function get( string $slug ): mixed;
    public function save(): void;
    public function load(): void;
}
