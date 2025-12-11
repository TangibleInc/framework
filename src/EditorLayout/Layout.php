<?php declare( strict_types=1 );
/**
 * The Layout class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\EditorLayout;

use Tangible\DataObject\DataSet;

/**
 * The Layout is the main entry point for defining editor layouts.
 *
 * It holds a reference to the DataSet for field validation and maintains
 * an ordered array of items (sections, tabs) plus a separate sidebar slot.
 */
class Layout {

    /**
     * The DataSet that this editor layout is based on.
     *
     * @var DataSet
     */
    protected DataSet $dataset;

    /**
     * Ordered array of layout items (sections, tabs).
     *
     * @var array
     */
    protected array $items = [];

    /**
     * The sidebar configuration.
     *
     * @var Sidebar|null
     */
    protected ?Sidebar $sidebar_instance = null;

    /**
     * Create a new Layout instance.
     *
     * @param DataSet $dataset The data set to validate fields against.
     */
    public function __construct( DataSet $dataset ) {
        $this->dataset = $dataset;
    }

    /**
     * Get the DataSet associated with this layout.
     *
     * @return DataSet The dataset.
     */
    public function get_dataset(): DataSet {
        return $this->dataset;
    }

    /**
     * Add a section to the layout.
     *
     * @param string   $label    The section label.
     * @param callable $callback A callback that receives a Section instance.
     * @return Layout This layout for method chaining.
     */
    public function section( string $label, callable $callback ): Layout {
        $section = new Section( $label, $this->dataset );
        $callback( $section );
        $this->items[] = $section;
        return $this;
    }

    /**
     * Add a tabs container to the layout.
     *
     * @param callable $callback A callback that receives a Tabs instance.
     * @return Layout This layout for method chaining.
     */
    public function tabs( callable $callback ): Layout {
        $tabs = new Tabs( $this->dataset );
        $callback( $tabs );
        $this->items[] = $tabs;
        return $this;
    }

    /**
     * Add a sidebar to the layout.
     *
     * @param callable $callback A callback that receives a Sidebar instance.
     * @return Layout This layout for method chaining.
     */
    public function sidebar( callable $callback ): Layout {
        $sidebar = new Sidebar( $this->dataset );
        $callback( $sidebar );
        $this->sidebar_instance = $sidebar;
        return $this;
    }

    /**
     * Get the compiled layout structure as an array.
     *
     * @return array The layout structure.
     */
    public function get_structure(): array {
        $structure = [
            'items' => array_map(
                fn( $item ) => $item->to_array(),
                $this->items
            ),
        ];

        if ( $this->sidebar_instance !== null ) {
            $structure['sidebar'] = $this->sidebar_instance->to_array();
        }

        return $structure;
    }
}
