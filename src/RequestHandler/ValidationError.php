<?php declare( strict_types=1 );

namespace Tangible\RequestHandler;

class ValidationError {
    protected string $message;
    protected ?string $field;

    public function __construct( string $message, string|null $field = null ) {
        $this->message = $message;
        $this->field = $field;
    }

    public function get_message(): string {
        return $this->message;
    }

    public function get_field(): string|null {
        return $this->field;
    }

    public function set_field( string $field ): ValidationError {
        if ( $this->field === null ) {
            $this->field = $field;
        }
        return $this;
    }
}
