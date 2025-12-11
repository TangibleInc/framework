<?php declare( strict_types=1 );
/**
 * The Renderer interface file.
 *
 * @package @tangible/framework
 */

namespace Tangible\Renderer;

use Tangible\DataObject\DataSet;
use Tangible\EditorLayout\Layout;

/**
 * Interface for rendering UI components.
 *
 * Implementations can provide different rendering strategies
 * (plain HTML, React, Tailwind, etc.)
 */
interface Renderer {

    /**
     * Render an editor form for an entity.
     *
     * @param Layout $layout The editor layout structure.
     * @param array  $data   The entity data to populate the form.
     * @return string The rendered HTML.
     */
    public function render_editor( Layout $layout, array $data ): string;

    /**
     * Render a list of entities.
     *
     * @param DataSet $dataset  The dataset defining the fields.
     * @param array   $entities The entities to display.
     * @return string The rendered HTML.
     */
    public function render_list( DataSet $dataset, array $entities ): string;

    /**
     * Enqueue any required CSS/JS assets.
     *
     * @return void
     */
    public function enqueue_assets(): void;
}
