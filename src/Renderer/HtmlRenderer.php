<?php declare( strict_types=1 );
/**
 * The HtmlRenderer class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\Renderer;

use Tangible\DataObject\DataSet;
use Tangible\EditorLayout\Layout;

/**
 * Plain HTML renderer implementation.
 *
 * Renders forms and lists as basic HTML without any framework dependencies.
 */
class HtmlRenderer implements Renderer {

    /**
     * The layout being rendered.
     *
     * @var Layout
     */
    protected Layout $layout;

    /**
     * The entity data being rendered.
     *
     * @var array
     */
    protected array $data;

    /**
     * Render an editor form for an entity.
     *
     * @param Layout $layout The editor layout structure.
     * @param array  $data   The entity data to populate the form.
     * @return string The rendered HTML.
     */
    public function render_editor( Layout $layout, array $data ): string {
        $this->layout = $layout;
        $this->data = $data;

        $structure = $layout->get_structure();
        $html = '<form method="post">';

        // Render main content items
        foreach ( $structure['items'] as $item ) {
            $html .= $this->render_item( $item );
        }

        // Render sidebar if present
        if ( isset( $structure['sidebar'] ) ) {
            $html .= $this->render_sidebar( $structure['sidebar'] );
        }

        $html .= '</form>';

        return $html;
    }

    /**
     * Render a structure item (section or tabs).
     *
     * @param array $item The item structure.
     * @return string The rendered HTML.
     */
    protected function render_item( array $item ): string {
        return match ( $item['type'] ) {
            'section' => $this->render_section( $item ),
            'tabs'    => $this->render_tabs( $item ),
            default   => '',
        };
    }

    /**
     * Render a section.
     *
     * @param array $section The section structure.
     * @return string The rendered HTML.
     */
    protected function render_section( array $section ): string {
        $html = '<fieldset>';
        $html .= '<legend>' . $this->escape( $section['label'] ) . '</legend>';

        // Render fields
        foreach ( $section['fields'] as $field ) {
            $html .= $this->render_field( $field );
        }

        // Render nested items
        if ( isset( $section['items'] ) ) {
            foreach ( $section['items'] as $item ) {
                $html .= $this->render_item( $item );
            }
        }

        $html .= '</fieldset>';

        return $html;
    }

    /**
     * Render a tabs container.
     *
     * @param array $tabs The tabs structure.
     * @return string The rendered HTML.
     */
    protected function render_tabs( array $tabs ): string {
        $html = '<div class="tabs">';

        // Render tab labels
        $html .= '<div class="tab-labels">';
        foreach ( $tabs['tabs'] as $tab ) {
            $html .= '<span class="tab-label">' . $this->escape( $tab['label'] ) . '</span>';
        }
        $html .= '</div>';

        // Render tab contents
        foreach ( $tabs['tabs'] as $tab ) {
            $html .= $this->render_tab( $tab );
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render a single tab.
     *
     * @param array $tab The tab structure.
     * @return string The rendered HTML.
     */
    protected function render_tab( array $tab ): string {
        $html = '<div class="tab-content">';

        // Render fields directly in tab
        foreach ( $tab['fields'] as $field ) {
            $html .= $this->render_field( $field );
        }

        // Render nested items
        if ( isset( $tab['items'] ) ) {
            foreach ( $tab['items'] as $item ) {
                $html .= $this->render_item( $item );
            }
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render the sidebar.
     *
     * @param array $sidebar The sidebar structure.
     * @return string The rendered HTML.
     */
    protected function render_sidebar( array $sidebar ): string {
        $html = '<aside class="sidebar">';

        // Render fields
        foreach ( $sidebar['fields'] as $field ) {
            $html .= $this->render_field( $field );
        }

        // Render actions
        if ( isset( $sidebar['actions'] ) ) {
            $html .= '<div class="actions">';
            foreach ( $sidebar['actions'] as $action ) {
                $html .= $this->render_action( $action );
            }
            $html .= '</div>';
        }

        $html .= '</aside>';

        return $html;
    }

    /**
     * Render an action button.
     *
     * @param string $action The action identifier.
     * @return string The rendered HTML.
     */
    protected function render_action( string $action ): string {
        $type = ( $action === 'save' ) ? 'submit' : 'button';
        $label = ucfirst( $action );

        return '<button type="' . $type . '" name="action" value="' . $this->escape( $action ) . '">' . $this->escape( $label ) . '</button>';
    }

    /**
     * Render a field.
     *
     * @param array $field The field structure.
     * @return string The rendered HTML.
     */
    protected function render_field( array $field ): string {
        $slug = $field['slug'];
        $type = $this->get_field_type( $slug );
        $value = $this->data[ $slug ] ?? null;

        $html = '<div class="field">';

        // Label
        $html .= '<label for="' . $this->escape( $slug ) . '">' . $this->escape( ucfirst( $slug ) ) . '</label>';

        // Input
        $html .= $this->render_input( $field, $type, $value );

        // Help text
        if ( isset( $field['help'] ) ) {
            $html .= '<p class="help">' . $this->escape( $field['help'] ) . '</p>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render an input element.
     *
     * @param array  $field The field structure.
     * @param string $type  The field type from DataSet.
     * @param mixed  $value The field value.
     * @return string The rendered HTML.
     */
    protected function render_input( array $field, string $type, mixed $value ): string {
        $slug = $field['slug'];
        $inputType = $this->get_input_type( $type );

        $attrs = [
            'id'   => $slug,
            'name' => $slug,
            'type' => $inputType,
        ];

        // Handle checkbox specially
        if ( $inputType === 'checkbox' ) {
            if ( $value ) {
                $attrs['checked'] = 'checked';
            }
            $attrs['value'] = '1';
        } else {
            if ( $value !== null ) {
                $attrs['value'] = (string) $value;
            }
        }

        // Add placeholder
        if ( isset( $field['placeholder'] ) ) {
            $attrs['placeholder'] = $field['placeholder'];
        }

        // Add readonly
        if ( isset( $field['readonly'] ) && $field['readonly'] ) {
            $attrs['readonly'] = 'readonly';
        }

        return '<input ' . $this->build_attributes( $attrs ) . '>';
    }

    /**
     * Get the field type from the DataSet.
     *
     * @param string $slug The field slug.
     * @return string The field type.
     */
    protected function get_field_type( string $slug ): string {
        $fields = $this->layout->get_dataset()->get_fields();
        return $fields[ $slug ]['type'] ?? 'string';
    }

    /**
     * Map DataSet field type to HTML input type.
     *
     * @param string $type The DataSet field type.
     * @return string The HTML input type.
     */
    protected function get_input_type( string $type ): string {
        return match ( $type ) {
            DataSet::TYPE_INTEGER => 'number',
            DataSet::TYPE_BOOLEAN => 'checkbox',
            default               => 'text',
        };
    }

    /**
     * Build HTML attributes string.
     *
     * @param array $attrs The attributes array.
     * @return string The HTML attributes string.
     */
    protected function build_attributes( array $attrs ): string {
        $parts = [];
        foreach ( $attrs as $key => $value ) {
            $parts[] = $this->escape( $key ) . '="' . $this->escape( $value ) . '"';
        }
        return implode( ' ', $parts );
    }

    /**
     * Render a list of entities.
     *
     * @param DataSet $dataset  The dataset defining the fields.
     * @param array   $entities The entities to display.
     * @return string The rendered HTML.
     */
    public function render_list( DataSet $dataset, array $entities ): string {
        $fields = $dataset->get_fields();
        $fieldSlugs = array_keys( $fields );

        $html = '<table>';

        // Header row
        $html .= '<thead><tr>';
        foreach ( $fieldSlugs as $slug ) {
            $html .= '<th>' . $this->escape( $slug ) . '</th>';
        }
        $html .= '</tr></thead>';

        // Data rows
        $html .= '<tbody>';
        foreach ( $entities as $entity ) {
            $html .= '<tr>';
            foreach ( $fieldSlugs as $slug ) {
                $value = $entity[ $slug ] ?? '';
                $html .= '<td>' . $this->escape( (string) $value ) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';

        $html .= '</table>';

        return $html;
    }

    /**
     * Enqueue any required CSS/JS assets.
     *
     * For plain HTML, this is a no-op.
     *
     * @return void
     */
    public function enqueue_assets(): void {
        // No assets needed for plain HTML rendering
    }

    /**
     * Escape HTML entities.
     *
     * @param string $value The value to escape.
     * @return string The escaped value.
     */
    protected function escape( string $value ): string {
        return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
    }
}
