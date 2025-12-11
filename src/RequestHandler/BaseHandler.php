<?php declare( strict_types=1 );
/**
 * The BaseHandler class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\RequestHandler;

use Tangible\DataObject\DataSet;

/**
 * Abstract base class for request handlers.
 *
 * Provides shared functionality for handling requests including:
 * - Type coercion via DataSet
 * - Field validation with custom validators
 * - Before/after update lifecycle hooks
 *
 * Extended by PluralHandler and SingularHandler for specific use cases.
 */
abstract class BaseHandler {

    /**
     * Registered validators organized by field name.
     *
     * @var array<string, callable[]>
     */
    protected array $validators = [];

    /**
     * Hooks to run before update operations.
     *
     * @var callable[]
     */
    protected array $before_update = [];

    /**
     * Hooks to run after update operations.
     *
     * @var callable[]
     */
    protected array $after_update = [];

    /**
     * Get the DataSet associated with the handled object.
     *
     * @return DataSet The dataset defining field types.
     */
    abstract public function get_dataset(): DataSet;

    /**
     * Coerce field values to their defined types using the DataSet.
     *
     * @param array $data The raw input data.
     * @return array The data with values coerced to proper types.
     */
    protected function coerce( array $data ): array {
        $dataset = $this->get_dataset();

        foreach ( $data as $field => $value ) {
            $data[ $field ] = $dataset->coerce( $field, $value );
        }

        return $data;
    }

    /**
     * Validate field values against registered validators.
     *
     * @param array $data The data to validate.
     * @return ValidationError[] Array of validation errors, empty if valid.
     */
    protected function validate( array $data ): array {
        $errors = [];

        foreach ( $data as $field => $value ) {
            if ( ! isset( $this->validators[ $field ] ) ) {
                continue;
            }

            foreach ( $this->validators[ $field ] as $validator ) {
                $result = $validator( $value );
                if ( $result instanceof ValidationError ) {
                    $result->set_field( $field );
                    $errors[] = $result;
                }
            }
        }

        return $errors;
    }

    /**
     * Set the required capability for CRUD operations.
     *
     * @param string $capability The WordPress capability required.
     * @return static The handler instance for method chaining.
     */
    public function set_capability( string $capability ): static {
        return $this;
    }

    /**
     * Add a validator for a specific field.
     *
     * Validators are callables that receive the field value and return
     * either true (valid) or a ValidationError instance (invalid).
     *
     * @param string   $field     The field name to validate.
     * @param callable $validator The validator callable.
     * @return static The handler instance for method chaining.
     */
    public function add_validator( string $field, callable $validator ): static {
        if ( ! isset( $this->validators[ $field ] ) ) {
            $this->validators[ $field ] = [];
        }
        $this->validators[ $field ][] = $validator;
        return $this;
    }

    /**
     * Register a hook to run before update operations.
     *
     * The callback signature varies by handler type:
     * - PluralHandler: function(Entity $entity, array $data): array
     * - SingularHandler: function(array $current, array $data): array
     *
     * @param callable $hook The hook callable.
     * @return static The handler instance for method chaining.
     */
    public function before_update( callable $hook ): static {
        $this->before_update[] = $hook;
        return $this;
    }

    /**
     * Register a hook to run after update operations.
     *
     * The callback signature varies by handler type:
     * - PluralHandler: function(Entity $entity): void
     * - SingularHandler: function(array $data): void
     *
     * @param callable $hook The hook callable.
     * @return static The handler instance for method chaining.
     */
    public function after_update( callable $hook ): static {
        $this->after_update[] = $hook;
        return $this;
    }
}
