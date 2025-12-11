<?php declare( strict_types=1 );

namespace Tangible\RequestHandler;

use Tangible\DataObject\PluralObject\Entity;

class Result {

    /**
     * @var Entity[]
     */
    protected array $multiple = [];
    protected Entity $entity;
    protected bool $is_success = false;
    protected bool $is_error = false;

    /**
     * @var ValidationError[]
     */
    protected array $errors = [];

    public function is_error(): bool {
        return $this->is_error;
    }

    public function is_success(): bool {
        return $this->is_success;
    }

    public function set_entity( Entity $entity ): Result {
        $this->entity = $entity;
        return $this;
    }

    public function set_entities( array $entities ): Result {
        $this->multiple = $entities;
        return $this;
    }

    public function get_entity(): Entity|null {
        return $this->entity ?? null;
    }

    public function get_entities(): array {
        return $this->multiple;
    }

    public function set_is_error( bool $is_error ): Result {
        $this->is_error = $is_error;
        return $this;
    }

    public function set_is_success( bool $is_success ): Result {
        $this->is_success = $is_success;
        return $this;
    }

    public function set_errors( array $errors ): Result {
        $this->errors = $errors;
        return $this;
    }

    public function get_errors(): array {
        return $this->errors;
    }

    public function get_field_errors( string $field ): array {
        return array_filter(
            $this->errors,
            fn( $e ) => $e->get_field() === $field
        );
    }
}
