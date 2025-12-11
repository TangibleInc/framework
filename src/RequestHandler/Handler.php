<?php declare( strict_types=1 );

namespace Tangible\RequestHandler;

use Tangible\DataObject\PluralObject;

class Handler {

    protected PluralObject $object;

    /**
     * @var array<string, callable[]>
     */
    protected array $validators = [];

    /**
     * @var callable[]
     */
    protected array $before_create = [];

    /**
     * @var callable[]
     */
    protected array $before_update = [];

    /**
     * @var callable[]
     */
    protected array $after_create = [];

    /**
     * @var callable[]
     */
    protected array $after_update = [];

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

    protected function coerce( array $data ): array {
        $dataset = $this->object->get_dataset();

        foreach ( $data as $field => $value ) {
            $data[ $field ] = $dataset->coerce( $field, $value );
        }

        return $data;
    }

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

    public function set_capability( string $capability ): Handler {
        return $this;
    }

    public function add_validator( string $field, callable $validator ): Handler {
        if ( ! isset( $this->validators[ $field ] ) ) {
            $this->validators[ $field ] = [];
        }
        $this->validators[ $field ][] = $validator;
        return $this;
    }

    public function before_create( callable $hook ): Handler {
        $this->before_create[] = $hook;
        return $this;
    }

    public function after_create( callable $hook ): Handler {
        $this->after_create[] = $hook;
        return $this;
    }

    public function before_update( callable $hook ): Handler {
        $this->before_update[] = $hook;
        return $this;
    }

    public function after_update( callable $hook ): Handler {
        $this->after_update[] = $hook;
        return $this;
    }

    public function before_delete( callable $hook ): Handler {
        $this->before_delete[] = $hook;
        return $this;
    }

    public function after_delete( callable $hook ): Handler {
        $this->after_delete[] = $hook;
        return $this;
    }
}
