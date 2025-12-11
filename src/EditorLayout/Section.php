<?php declare( strict_types=1 );
/**
 * The Section class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\EditorLayout;

use Tangible\DataObject\DataSet;
use Tangible\EditorLayout\Exception\InvalidFieldException;

/**
 * Represents a section in the editor layout.
 *
 * Sections can contain fields and nested items (sections, tabs).
 * They support column layouts and conditional visibility.
 */
class Section {

    /**
     * The section label.
     *
     * @var string
     */
    protected string $label;

    /**
     * The DataSet for field validation.
     *
     * @var DataSet
     */
    protected DataSet $dataset;

    /**
     * Fields in this section.
     *
     * @var array
     */
    protected array $fields = [];

    /**
     * Nested items (sections, tabs) in this section.
     *
     * @var array
     */
    protected array $items = [];

    /**
     * Number of columns for the section layout.
     *
     * @var int|null
     */
    protected ?int $columns_count = null;

    /**
     * Conditional visibility configuration.
     *
     * @var array|null
     */
    protected ?array $condition_config = null;

    /**
     * Create a new Section instance.
     *
     * @param string  $label   The section label.
     * @param DataSet $dataset The dataset for field validation.
     */
    public function __construct( string $label, DataSet $dataset ) {
        $this->label = $label;
        $this->dataset = $dataset;
    }

    /**
     * Add a field to this section.
     *
     * @param string $slug The field slug.
     * @return Field The field instance for further configuration.
     * @throws InvalidFieldException If the field is not defined in the DataSet.
     */
    public function field( string $slug ): Field {
        if ( ! $this->dataset->has_field( $slug ) ) {
            throw new InvalidFieldException( "Field '{$slug}' is not defined in DataSet" );
        }

        $field = new Field( $slug );
        $this->fields[] = $field;
        return $field;
    }

    /**
     * Add a nested section to this section.
     *
     * @param string   $label    The nested section label.
     * @param callable $callback A callback that receives a Section instance.
     * @return Section This section for method chaining.
     */
    public function section( string $label, callable $callback ): Section {
        $section = new Section( $label, $this->dataset );
        $callback( $section );
        $this->items[] = $section;
        return $this;
    }

    /**
     * Add a tabs container to this section.
     *
     * @param callable $callback A callback that receives a Tabs instance.
     * @return Section This section for method chaining.
     */
    public function tabs( callable $callback ): Section {
        $tabs = new Tabs( $this->dataset );
        $callback( $tabs );
        $this->items[] = $tabs;
        return $this;
    }

    /**
     * Set the number of columns for this section.
     *
     * @param int $count The number of columns.
     * @return Section This section for method chaining.
     */
    public function columns( int $count ): Section {
        $this->columns_count = $count;
        return $this;
    }

    /**
     * Set a condition for this section's visibility.
     *
     * @param string $field The field to check.
     * @param mixed  $value The value to compare against.
     * @return Section This section for method chaining.
     */
    public function condition( string $field, mixed $value ): Section {
        $this->condition_config = [
            'field' => $field,
            'value' => $value,
        ];
        return $this;
    }

    /**
     * Convert the section to an array representation.
     *
     * @return array The section as an array.
     */
    public function to_array(): array {
        $result = [
            'type'   => 'section',
            'label'  => $this->label,
            'fields' => array_map(
                fn( Field $field ) => $field->to_array(),
                $this->fields
            ),
        ];

        if ( ! empty( $this->items ) ) {
            $result['items'] = array_map(
                fn( $item ) => $item->to_array(),
                $this->items
            );
        }

        if ( $this->columns_count !== null ) {
            $result['columns'] = $this->columns_count;
        }

        if ( $this->condition_config !== null ) {
            $result['condition'] = $this->condition_config;
        }

        return $result;
    }
}
