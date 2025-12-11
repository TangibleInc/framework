<?php declare( strict_types=1 );
/**
 * The SingularHandler class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\RequestHandler;

use Tangible\DataObject\DataSet;
use Tangible\DataObject\SingularObject;

/**
 * Request handler for SingularObject instances.
 *
 * Handles read/update operations for objects that exist as a single instance,
 * such as plugin settings or site configuration.
 *
 * Unlike PluralHandler, this does not support create/delete operations
 * since singular objects are always a single persistent instance.
 *
 * Provides:
 * - Read and update operations
 * - Type coercion and validation
 * - Before/after update lifecycle hooks
 */
class SingularHandler extends BaseHandler {

    /**
     * The SingularObject instance being handled.
     *
     * @var SingularObject
     */
    protected SingularObject $object;

    /**
     * Create a new SingularHandler instance.
     *
     * @param SingularObject $object The SingularObject to handle requests for.
     */
    public function __construct( SingularObject $object ) {
        $this->object = $object;
    }

    /**
     * Get the DataSet associated with the SingularObject.
     *
     * @return DataSet The dataset defining field types.
     */
    public function get_dataset(): DataSet {
        return $this->object->get_dataset();
    }

    /**
     * Read the current values of all defined fields.
     *
     * Loads data from storage and returns all field values defined in the DataSet.
     *
     * @return Result Success with data array containing all field values.
     */
    public function read(): Result {
        $result = new Result();

        $this->object->load();
        $data = $this->get_all_values();

        return $result->set_data( $data )
            ->set_is_success( true );
    }

    /**
     * Update field values.
     *
     * Performs type coercion, validation, and runs lifecycle hooks.
     * Only the fields provided in $data are updated; other fields retain their values.
     *
     * @param array $data The field values to update.
     * @return Result Success with updated data, or error with validation errors.
     */
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

    /**
     * Get current values for all fields defined in the DataSet.
     *
     * @return array Associative array of field names to values.
     */
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
