<?php declare( strict_types=1 );
/**
 * The Field class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\EditorLayout;

/**
 * Represents a field configuration in the editor layout.
 *
 * Fields have presentation properties like help text, placeholder,
 * width, readonly state, and conditional visibility.
 */
class Field {

    /**
     * The field slug (name).
     *
     * @var string
     */
    protected string $slug;

    /**
     * Help text for the field.
     *
     * @var string|null
     */
    protected ?string $help_text = null;

    /**
     * Placeholder text for the field.
     *
     * @var string|null
     */
    protected ?string $placeholder_text = null;

    /**
     * Width of the field (e.g., '50%', '100%').
     *
     * @var string|null
     */
    protected ?string $width_value = null;

    /**
     * Whether the field is readonly.
     *
     * @var bool
     */
    protected bool $is_readonly = false;

    /**
     * Conditional visibility configuration.
     *
     * @var array|null
     */
    protected ?array $condition_config = null;

    /**
     * Create a new Field instance.
     *
     * @param string $slug The field slug.
     */
    public function __construct( string $slug ) {
        $this->slug = $slug;
    }

    /**
     * Set the help text for the field.
     *
     * @param string $text The help text.
     * @return Field This field for method chaining.
     */
    public function help( string $text ): Field {
        $this->help_text = $text;
        return $this;
    }

    /**
     * Set the placeholder text for the field.
     *
     * @param string $text The placeholder text.
     * @return Field This field for method chaining.
     */
    public function placeholder( string $text ): Field {
        $this->placeholder_text = $text;
        return $this;
    }

    /**
     * Set the width of the field.
     *
     * @param string $width The width value (e.g., '50%').
     * @return Field This field for method chaining.
     */
    public function width( string $width ): Field {
        $this->width_value = $width;
        return $this;
    }

    /**
     * Mark the field as readonly.
     *
     * @return Field This field for method chaining.
     */
    public function readonly(): Field {
        $this->is_readonly = true;
        return $this;
    }

    /**
     * Set a condition for this field's visibility.
     *
     * @param string $field The field to check.
     * @param mixed  $value The value to compare against.
     * @return Field This field for method chaining.
     */
    public function condition( string $field, mixed $value ): Field {
        $this->condition_config = [
            'field' => $field,
            'value' => $value,
        ];
        return $this;
    }

    /**
     * Convert the field to an array representation.
     *
     * @return array The field as an array.
     */
    public function to_array(): array {
        $result = [
            'slug' => $this->slug,
        ];

        if ( $this->help_text !== null ) {
            $result['help'] = $this->help_text;
        }

        if ( $this->placeholder_text !== null ) {
            $result['placeholder'] = $this->placeholder_text;
        }

        if ( $this->width_value !== null ) {
            $result['width'] = $this->width_value;
        }

        if ( $this->is_readonly ) {
            $result['readonly'] = true;
        }

        if ( $this->condition_config !== null ) {
            $result['condition'] = $this->condition_config;
        }

        return $result;
    }
}
