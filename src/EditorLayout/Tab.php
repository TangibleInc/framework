<?php declare( strict_types=1 );
/**
 * The Tab class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\EditorLayout;

use Tangible\DataObject\DataSet;
use Tangible\EditorLayout\Exception\InvalidFieldException;

/**
 * Represents a single tab in the editor layout.
 *
 * A Tab can contain fields and nested items (sections, tabs).
 */
class Tab {

    /**
     * The tab label.
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
     * Fields in this tab.
     *
     * @var array
     */
    protected array $fields = [];

    /**
     * Nested items (sections, tabs) in this tab.
     *
     * @var array
     */
    protected array $items = [];

    /**
     * Create a new Tab instance.
     *
     * @param string  $label   The tab label.
     * @param DataSet $dataset The dataset for field validation.
     */
    public function __construct( string $label, DataSet $dataset ) {
        $this->label = $label;
        $this->dataset = $dataset;
    }

    /**
     * Add a field to this tab.
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
     * Add a section to this tab.
     *
     * @param string   $label    The section label.
     * @param callable $callback A callback that receives a Section instance.
     * @return Tab This tab for method chaining.
     */
    public function section( string $label, callable $callback ): Tab {
        $section = new Section( $label, $this->dataset );
        $callback( $section );
        $this->items[] = $section;
        return $this;
    }

    /**
     * Add nested tabs to this tab.
     *
     * @param callable $callback A callback that receives a Tabs instance.
     * @return Tab This tab for method chaining.
     */
    public function tabs( callable $callback ): Tab {
        $tabs = new Tabs( $this->dataset );
        $callback( $tabs );
        $this->items[] = $tabs;
        return $this;
    }

    /**
     * Convert the tab to an array representation.
     *
     * @return array The tab as an array.
     */
    public function to_array(): array {
        $result = [
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

        return $result;
    }
}
