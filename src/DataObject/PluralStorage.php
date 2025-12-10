<?php declare( strict_types=1 );

namespace Tangible\DataObject;

interface PluralStorage {
    public function register( string $slug, array $settings ): void;
    public function insert( array $data ): int;
    public function update( int $id, array $data ): void;
    public function delete( int $id ): void;
    public function find( int $id ): ?array;
    public function all(): array;
}
