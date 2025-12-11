<?php declare( strict_types=1 );

namespace Tangible\RequestHandler;

use Tangible\DataObject\BaseDataObject;

abstract class BaseHandler {

    /**
     * @var array<string, callable[]>
     */
    protected array $validators = [];

    /**
     * @var callable[]
     */
    protected array $before_update = [];

    /**
     * @var callable[]
     */
    protected array $after_update = [];

    abstract public function get_dataset();

    protected function coerce( array $data ): array {
        $dataset = $this->get_dataset();

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

    public function set_capability( string $capability ): static {
        return $this;
    }

    public function add_validator( string $field, callable $validator ): static {
        if ( ! isset( $this->validators[ $field ] ) ) {
            $this->validators[ $field ] = [];
        }
        $this->validators[ $field ][] = $validator;
        return $this;
    }

    public function before_update( callable $hook ): static {
        $this->before_update[] = $hook;
        return $this;
    }

    public function after_update( callable $hook ): static {
        $this->after_update[] = $hook;
        return $this;
    }
}
