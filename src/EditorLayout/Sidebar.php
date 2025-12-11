<?php declare( strict_types=1 );
/**
 * The Sidebar class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\EditorLayout;

use Tangible\DataObject\DataSet;
use Tangible\EditorLayout\Exception\InvalidFieldException;

/**
 * Represents a sidebar in the editor layout.
 *
 * Sidebars can contain fields and action buttons (e.g., save, delete).
 */
class Sidebar {

    /**
     * The DataSet for field validation.
     *
     * @var DataSet
     */
    protected DataSet $dataset;

    /**
     * Fields in the sidebar.
     *
     * @var array
     */
    protected array $fields = [];

    /**
     * Action buttons in the sidebar.
     *
     * @var array
     */
    protected array $actions_list = [];

    /**
     * Create a new Sidebar instance.
     *
     * @param DataSet $dataset The dataset for field validation.
     */
    public function __construct( DataSet $dataset ) {
        $this->dataset = $dataset;
    }

    /**
     * Add a field to the sidebar.
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
     * Set the action buttons for the sidebar.
     *
     * @param array $actions An array of action identifiers (e.g., ['save', 'delete']).
     * @return Sidebar This sidebar for method chaining.
     */
    public function actions( array $actions ): Sidebar {
        $this->actions_list = $actions;
        return $this;
    }

    /**
     * Convert the sidebar to an array representation.
     *
     * @return array The sidebar as an array.
     */
    public function to_array(): array {
        $result = [
            'fields' => array_map(
                fn( Field $field ) => $field->to_array(),
                $this->fields
            ),
        ];

        if ( ! empty( $this->actions_list ) ) {
            $result['actions'] = $this->actions_list;
        }

        return $result;
    }
}
