<?php declare( strict_types=1 );
/**
 * The Result class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\RequestHandler;

use Tangible\DataObject\PluralObject\Entity;

/**
 * Represents the result of a request handler operation.
 *
 * Provides a uniform interface for both successful and failed operations,
 * containing either the resulting data/entity or validation errors.
 *
 * Used by both PluralHandler and SingularHandler to return operation results.
 */
class Result {

    /**
     * Array of entities for list operations.
     *
     * @var Entity[]
     */
    protected array $multiple = [];

    /**
     * Single entity for read/create/update operations.
     *
     * @var Entity
     */
    protected Entity $entity;

    /**
     * Whether the operation was successful.
     *
     * @var bool
     */
    protected bool $is_success = false;

    /**
     * Whether the operation failed.
     *
     * @var bool
     */
    protected bool $is_error = false;

    /**
     * Validation errors from failed operations.
     *
     * @var ValidationError[]
     */
    protected array $errors = [];

    /**
     * Data array for singular object operations.
     *
     * @var array
     */
    protected array $data = [];

    /**
     * Check if the operation resulted in an error.
     *
     * @return bool True if the operation failed.
     */
    public function is_error(): bool {
        return $this->is_error;
    }

    /**
     * Check if the operation was successful.
     *
     * @return bool True if the operation succeeded.
     */
    public function is_success(): bool {
        return $this->is_success;
    }

    /**
     * Set the resulting entity for single-entity operations.
     *
     * @param Entity $entity The entity to set.
     * @return Result The result instance for method chaining.
     */
    public function set_entity( Entity $entity ): Result {
        $this->entity = $entity;
        return $this;
    }

    /**
     * Set the resulting entities for list operations.
     *
     * @param Entity[] $entities The array of entities.
     * @return Result The result instance for method chaining.
     */
    public function set_entities( array $entities ): Result {
        $this->multiple = $entities;
        return $this;
    }

    /**
     * Get the resulting entity.
     *
     * @return Entity|null The entity, or null if not set.
     */
    public function get_entity(): Entity|null {
        return $this->entity ?? null;
    }

    /**
     * Get the resulting entities from a list operation.
     *
     * @return Entity[] Array of entities.
     */
    public function get_entities(): array {
        return $this->multiple;
    }

    /**
     * Set the error state.
     *
     * @param bool $is_error Whether this is an error result.
     * @return Result The result instance for method chaining.
     */
    public function set_is_error( bool $is_error ): Result {
        $this->is_error = $is_error;
        return $this;
    }

    /**
     * Set the success state.
     *
     * @param bool $is_success Whether this is a success result.
     * @return Result The result instance for method chaining.
     */
    public function set_is_success( bool $is_success ): Result {
        $this->is_success = $is_success;
        return $this;
    }

    /**
     * Set the validation errors.
     *
     * @param ValidationError[] $errors Array of validation errors.
     * @return Result The result instance for method chaining.
     */
    public function set_errors( array $errors ): Result {
        $this->errors = $errors;
        return $this;
    }

    /**
     * Get all validation errors.
     *
     * @return ValidationError[] Array of validation errors.
     */
    public function get_errors(): array {
        return $this->errors;
    }

    /**
     * Get validation errors for a specific field.
     *
     * @param string $field The field name to get errors for.
     * @return ValidationError[] Array of errors for the specified field.
     */
    public function get_field_errors( string $field ): array {
        return array_filter(
            $this->errors,
            fn( $e ) => $e->get_field() === $field
        );
    }

    /**
     * Set the data array for singular object operations.
     *
     * @param array $data The data array.
     * @return Result The result instance for method chaining.
     */
    public function set_data( array $data ): Result {
        $this->data = $data;
        return $this;
    }

    /**
     * Get the data array from singular object operations.
     *
     * @return array The data array.
     */
    public function get_data(): array {
        return $this->data;
    }
}
