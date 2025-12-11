<?php declare( strict_types=1 );

namespace Tangible\RequestHandler;

use Tangible\DataObject\DataSet;
use Tangible\DataObject\SingularObject;

class SingularHandler extends BaseHandler {

    protected SingularObject $object;

    public function __construct( SingularObject $object ) {
        $this->object = $object;
    }

    public function get_dataset(): DataSet {
        return $this->object->get_dataset();
    }

    public function read(): Result {
        $result = new Result();

        $this->object->load();
        $data = $this->get_all_values();

        return $result->set_data( $data )
            ->set_is_success( true );
    }

    public function update( array $data ): Result {
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
        $current_data = $this->get_all_values();
        foreach ( $this->before_update as $callable ) {
            $data = $callable( $current_data, $data );
        }

        // Apply changes
        foreach ( $data as $field => $value ) {
            $this->object->set( $field, $value );
        }
        $this->object->save();

        // After hooks
        $updated_data = $this->get_all_values();
        foreach ( $this->after_update as $callable ) {
            $callable( $updated_data );
        }

        return $result->set_data( $updated_data )
            ->set_is_success( true );
    }

    protected function get_all_values(): array {
        $dataset = $this->get_dataset();
        $fields = $dataset->get_fields();
        $data = [];

        foreach ( array_keys( $fields ) as $field ) {
            $data[ $field ] = $this->object->get( $field );
        }

        return $data;
    }
}
