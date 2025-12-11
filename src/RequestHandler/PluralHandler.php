<?php declare( strict_types=1 );

namespace Tangible\RequestHandler;

use Tangible\DataObject\DataSet;
use Tangible\DataObject\PluralObject;

class PluralHandler extends BaseHandler {

    protected PluralObject $object;

    /**
     * @var callable[]
     */
    protected array $before_create = [];

    /**
     * @var callable[]
     */
    protected array $after_create = [];

    /**
     * @var callable[]
     */
    protected array $before_delete = [];

    /**
     * @var callable[]
     */
    protected array $after_delete = [];

    public function __construct( PluralObject $object ) {
        $this->object = $object;
    }

    public function get_dataset(): DataSet {
        return $this->object->get_dataset();
    }

    public function read( int $id ): Result {
        $result = new Result();

        $entity = $this->object->find( $id );
        if ( $entity === null ) {
            return $result->set_is_error( true );
        }

        return $result->set_entity( $entity )
            ->set_is_success( true );
    }

    public function list(): Result {
        $result   = new Result();
        $entities = $this->object->all();

        return $result->set_entities( $entities )
            ->set_is_success( true );
    }

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

    public function before_create( callable $hook ): static {
        $this->before_create[] = $hook;
        return $this;
    }

    public function after_create( callable $hook ): static {
        $this->after_create[] = $hook;
        return $this;
    }

    public function before_delete( callable $hook ): static {
        $this->before_delete[] = $hook;
        return $this;
    }

    public function after_delete( callable $hook ): static {
        $this->after_delete[] = $hook;
        return $this;
    }
}
