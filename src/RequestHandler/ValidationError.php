<?php declare( strict_types=1 );
/**
 * The ValidationError class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\RequestHandler;

/**
 * Represents a validation error for a specific field.
 *
 * Created by validators when a field value fails validation.
 * Contains an error message and optionally the field name that failed.
 */
class ValidationError {

    /**
     * The error message describing the validation failure.
     *
     * @var string
     */
    protected string $message;

    /**
     * The name of the field that failed validation.
     *
     * @var string|null
     */
    protected ?string $field;

    /**
     * Create a new ValidationError instance.
     *
     * @param string      $message The error message.
     * @param string|null $field   The field name (optional, can be set later).
     */
    public function __construct( string $message, string|null $field = null ) {
        $this->message = $message;
        $this->field = $field;
    }

    /**
     * Get the error message.
     *
     * @return string The validation error message.
     */
    public function get_message(): string {
        return $this->message;
    }

    /**
     * Get the field name that failed validation.
     *
     * @return string|null The field name, or null if not set.
     */
    public function get_field(): string|null {
        return $this->field;
    }

    /**
     * Set the field name for this error.
     *
     * Only sets the field if it hasn't been set already, allowing
     * validators to optionally specify the field or have it set
     * automatically by the handler.
     *
     * @param string $field The field name.
     * @return ValidationError The error instance for method chaining.
     */
    public function set_field( string $field ): ValidationError {
        if ( $this->field === null ) {
            $this->field = $field;
        }
        return $this;
    }
}
