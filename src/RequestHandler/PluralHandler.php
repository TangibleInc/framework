<?php declare( strict_types=1 );
/**
 * The PluralHandler class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\RequestHandler;

use Tangible\DataObject\DataSet;
use Tangible\DataObject\PluralObject;
use Tangible\DataObject\PluralObject\Entity;

/**
 * Request handler for PluralObject instances.
 *
 * Handles CRUD operations for objects that can have multiple instances,
 * such as custom post types or custom database entities.
 *
 * Provides:
 * - Full CRUD operations (create, read, update, delete, list)
 * - Type coercion and validation
 * - Lifecycle hooks for all operations
 */
class PluralHandler extends BaseHandler {

    /**
     * The PluralObject instance being handled.
     *
     * @var PluralObject
     */
    protected PluralObject $object;

    /**
     * Hooks to run before create operations.
     *
     * @var callable[]
     */
    protected array $before_create = [];

    /**
     * Hooks to run after create operations.
     *
     * @var callable[]
     */
    protected array $after_create = [];

    /**
     * Hooks to run before delete operations.
     *
     * @var callable[]
     */
    protected array $before_delete = [];

    /**
     * Hooks to run after delete operations.
     *
     * @var callable[]
     */
    protected array $after_delete = [];

    /**
     * Create a new PluralHandler instance.
     *
     * @param PluralObject $object The PluralObject to handle requests for.
     */
    public function __construct( PluralObject $object ) {
        $this->object = $object;
    }

    /**
     * Get the DataSet associated with the PluralObject.
     *
     * @return DataSet The dataset defining field types.
     */
    public function get_dataset(): DataSet {
        return $this->object->get_dataset();
    }

    /**
     * Read a single entity by ID.
     *
     * @param int $id The entity ID to retrieve.
     * @return Result Success with entity, or error if not found.
     */
    public function read( int $id ): Result {
        $result = new Result();

        $entity = $this->object->find( $id );
        if ( $entity === null ) {
            return $result->set_is_error( true );
        }

        return $result->set_entity( $entity )
            ->set_is_success( true );
    }

    /**
     * List all entities.
     *
     * @return Result Success with array of entities.
     */
    public function list(): Result {
        $result   = new Result();
        $entities = $this->object->all();

        return $result->set_entities( $entities )
            ->set_is_success( true );
    }

    /**
     * Create a new entity.
     *
     * Performs type coercion, validation, and runs lifecycle hooks.
     *
     * @param array $data The entity data to create.
     * @return Result Success with created entity, or error with validation errors.
     */
    public function create( array $data ): Result {
        $result = new Result();

        // Type coercion
        $data = $this->coerce( $data );

        // Validation
        $errors = $this->validate( $data );
        if ( ! empty( $errors ) ) {
            return $result
                ->set_errors( $errors )
                ->set_is_error( true );
        }

        // Before hooks
        foreach ( $this->before_create as $callable ) {
            $data = $callable( $data );
        }

        $entity = $this->object->create( $data );

        // After hooks
        foreach ( $this->after_create as $callable ) {
            $callable( $entity );
        }

        return $result->set_entity( $entity )
            ->set_is_success( true );
    }

    /**
     * Update an existing entity.
     *
     * Performs type coercion, validation, and runs lifecycle hooks.
     *
     * @param int   $id   The entity ID to update.
     * @param array $data The data to update.
     * @return Result Success with updated entity, or error if not found or validation fails.
     */
    public function update( int $id, array $data ): Result {
        $result = new Result();
        $entity = $this->object->find( $id );

        if ( $entity === null ) {
            return $result->set_is_error( true );
        }

        // Type coercion
        $data = $this->coerce( $data );

        // Validation
        $errors = $this->validate( $data );
        if ( ! empty( $errors ) ) {
            return $result
                ->set_errors( $errors )
                ->set_is_error( true );
        }

        // Before hooks
        foreach ( $this->before_update as $callable ) {
            $data = $callable( $entity, $data );
        }

        foreach ( $data as $field => $value ) {
            $entity->set( $field, $value );
        }
        $this->object->save( $entity );

        // After hooks
        foreach ( $this->after_update as $callable ) {
            $callable( $entity );
        }

        return $result->set_entity( $entity )
            ->set_is_success( true );
    }

    /**
     * Delete an entity.
     *
     * Runs before_delete hooks which can cancel the deletion by returning false.
     *
     * @param int $id The entity ID to delete.
     * @return Result Success if deleted, or error if not found or cancelled.
     */
    public function delete( int $id ): Result {
        $result = new Result();

        $entity = $this->object->find( $id );
        if ( $entity === null ) {
            return $result->set_is_error( true );
        }

        // Before hooks - can cancel deletion by returning false
        foreach ( $this->before_delete as $callable ) {
            if ( $callable( $entity ) === false ) {
                return $result->set_is_error( true );
            }
        }

        $this->object->delete( $entity );

        // After hooks
        foreach ( $this->after_delete as $callable ) {
            $callable( $id );
        }

        return $result->set_is_success( true );
    }

    /**
     * Register a hook to run before create operations.
     *
     * Callback signature: function(array $data): array
     * The callback receives the data and should return the (possibly modified) data.
     *
     * @param callable $hook The hook callable.
     * @return static The handler instance for method chaining.
     */
    public function before_create( callable $hook ): static {
        $this->before_create[] = $hook;
        return $this;
    }

    /**
     * Register a hook to run after create operations.
     *
     * Callback signature: function(Entity $entity): void
     *
     * @param callable $hook The hook callable.
     * @return static The handler instance for method chaining.
     */
    public function after_create( callable $hook ): static {
        $this->after_create[] = $hook;
        return $this;
    }

    /**
     * Register a hook to run before delete operations.
     *
     * Callback signature: function(Entity $entity): bool
     * Return false to cancel the deletion.
     *
     * @param callable $hook The hook callable.
     * @return static The handler instance for method chaining.
     */
    public function before_delete( callable $hook ): static {
        $this->before_delete[] = $hook;
        return $this;
    }

    /**
     * Register a hook to run after delete operations.
     *
     * Callback signature: function(int $id): void
     *
     * @param callable $hook The hook callable.
     * @return static The handler instance for method chaining.
     */
    public function after_delete( callable $hook ): static {
        $this->after_delete[] = $hook;
        return $this;
    }
}
