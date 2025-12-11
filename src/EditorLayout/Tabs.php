<?php declare( strict_types=1 );
/**
 * The Tabs class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\EditorLayout;

use Tangible\DataObject\DataSet;

/**
 * Represents a tabs container in the editor layout.
 *
 * A Tabs instance groups multiple Tab instances together.
 */
class Tabs {

    /**
     * The DataSet for field validation.
     *
     * @var DataSet
     */
    protected DataSet $dataset;

    /**
     * The tabs in this container.
     *
     * @var array
     */
    protected array $tabs = [];

    /**
     * Create a new Tabs instance.
     *
     * @param DataSet $dataset The dataset for field validation.
     */
    public function __construct( DataSet $dataset ) {
        $this->dataset = $dataset;
    }

    /**
     * Add a tab to this container.
     *
     * @param string   $label    The tab label.
     * @param callable $callback A callback that receives a Tab instance.
     * @return Tabs This tabs container for method chaining.
     */
    public function tab( string $label, callable $callback ): Tabs {
        $tab = new Tab( $label, $this->dataset );
        $callback( $tab );
        $this->tabs[] = $tab;
        return $this;
    }

    /**
     * Convert the tabs container to an array representation.
     *
     * @return array The tabs container as an array.
     */
    public function to_array(): array {
        return [
            'type' => 'tabs',
            'tabs' => array_map(
                fn( Tab $tab ) => $tab->to_array(),
                $this->tabs
            ),
        ];
    }
}
