<?php
namespace Tangible\Framework\Tests;

use Tangible\DataObject\DataSet;
use Tangible\DataObject\PluralObject;
use Tangible\DataObject\SingularObject;
use Tangible\EditorLayout\Layout;
use Tangible\EditorLayout\Section;
use Tangible\EditorLayout\Tabs;
use Tangible\EditorLayout\Tab;
use Tangible\EditorLayout\Sidebar;
use Tangible\Renderer\Renderer;
use Tangible\Renderer\HtmlRenderer;

/**
 * Tests for the Renderer (Layer 3: UI Presentation)
 *
 * This tests the renderer layer with a minimal HTML implementation:
 * - Rendering editor forms from Layout structure
 * - Rendering entity lists for PluralObject
 * - Field type to input type mapping
 * - Basic form structure (sections, fields, sidebar, actions)
 */
class Renderer_TestCase extends \WP_UnitTestCase {

    private DataSet $dataset;
    private Layout $layout;

    public function setUp(): void {
        parent::setUp();

        $this->dataset = new DataSet();
        $this->dataset->add_string('title');
        $this->dataset->add_string('description');
        $this->dataset->add_integer('count');
        $this->dataset->add_boolean('is_active');
        $this->dataset->add_string('status');

        $this->layout = new Layout($this->dataset);
    }

    /**
     * ==========================================================================
     * Renderer Interface and Basic Instantiation
     * ==========================================================================
     */

    public function test_html_renderer_implements_renderer_interface(): void {
        $renderer = new HtmlRenderer();
        $this->assertInstanceOf(Renderer::class, $renderer);
    }

    public function test_html_renderer_can_be_instantiated(): void {
        $renderer = new HtmlRenderer();
        $this->assertInstanceOf(HtmlRenderer::class, $renderer);
    }

    /**
     * ==========================================================================
     * Basic Editor Rendering
     * ==========================================================================
     */

    public function test_render_editor_returns_string(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('title');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertIsString($html);
    }

    public function test_render_editor_contains_form_tag(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('title');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('</form>', $html);
    }

    public function test_render_editor_contains_section_label(): void {
        $this->layout->section('General Settings', function(Section $s) {
            $s->field('title');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('General Settings', $html);
    }

    public function test_render_editor_contains_field_input(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('title');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('name="title"', $html);
    }

    /**
     * ==========================================================================
     * Field Types to Input Types
     * ==========================================================================
     */

    public function test_string_field_renders_as_text_input(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('title');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('name="title"', $html);
    }

    public function test_integer_field_renders_as_number_input(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('count');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('type="number"', $html);
        $this->assertStringContainsString('name="count"', $html);
    }

    public function test_boolean_field_renders_as_checkbox(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('is_active');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('name="is_active"', $html);
    }

    /**
     * ==========================================================================
     * Field Configuration Rendering
     * ==========================================================================
     */

    public function test_field_placeholder_is_rendered(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('title')->placeholder('Enter title here');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('placeholder="Enter title here"', $html);
    }

    public function test_field_help_text_is_rendered(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('title')->help('This is the main title');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('This is the main title', $html);
    }

    public function test_field_readonly_is_rendered(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('status')->readonly();
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('readonly', $html);
    }

    /**
     * ==========================================================================
     * Entity Data Population
     * ==========================================================================
     */

    public function test_field_value_is_populated_from_data(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('title');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, ['title' => 'My Title']);

        $this->assertStringContainsString('value="My Title"', $html);
    }

    public function test_checkbox_is_checked_when_value_is_true(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('is_active');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, ['is_active' => true]);

        $this->assertStringContainsString('checked', $html);
    }

    public function test_checkbox_is_not_checked_when_value_is_false(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('is_active');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, ['is_active' => false]);

        // Should have checkbox but not checked
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringNotContainsString('checked', $html);
    }

    /**
     * ==========================================================================
     * Multiple Sections
     * ==========================================================================
     */

    public function test_multiple_sections_are_rendered(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('title');
        });

        $this->layout->section('Settings', function(Section $s) {
            $s->field('is_active');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('General', $html);
        $this->assertStringContainsString('Settings', $html);
    }

    public function test_multiple_fields_in_section_are_rendered(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('title');
            $s->field('description');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('name="title"', $html);
        $this->assertStringContainsString('name="description"', $html);
    }

    /**
     * ==========================================================================
     * Tabs Rendering
     * ==========================================================================
     */

    public function test_tabs_are_rendered(): void {
        $this->layout->tabs(function(Tabs $tabs) {
            $tabs->tab('Content', function(Tab $t) {
                $t->field('title');
            });
            $tabs->tab('Settings', function(Tab $t) {
                $t->field('is_active');
            });
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('Content', $html);
        $this->assertStringContainsString('Settings', $html);
    }

    public function test_tab_fields_are_rendered(): void {
        $this->layout->tabs(function(Tabs $tabs) {
            $tabs->tab('Content', function(Tab $t) {
                $t->field('title');
            });
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('name="title"', $html);
    }

    /**
     * ==========================================================================
     * Sidebar Rendering
     * ==========================================================================
     */

    public function test_sidebar_is_rendered(): void {
        $this->layout->sidebar(function(Sidebar $sb) {
            $sb->field('status');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('name="status"', $html);
    }

    public function test_sidebar_actions_are_rendered(): void {
        $this->layout->sidebar(function(Sidebar $sb) {
            $sb->actions(['save', 'delete']);
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        // Actions should be rendered as buttons
        $this->assertStringContainsString('save', $html);
        $this->assertStringContainsString('delete', $html);
    }

    public function test_save_action_renders_as_submit_button(): void {
        $this->layout->sidebar(function(Sidebar $sb) {
            $sb->actions(['save']);
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, []);

        $this->assertStringContainsString('type="submit"', $html);
    }

    /**
     * ==========================================================================
     * List Rendering
     * ==========================================================================
     */

    public function test_render_list_returns_string(): void {
        $renderer = new HtmlRenderer();
        $html = $renderer->render_list($this->dataset, []);

        $this->assertIsString($html);
    }

    public function test_render_list_contains_table(): void {
        $renderer = new HtmlRenderer();
        $html = $renderer->render_list($this->dataset, []);

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('</table>', $html);
    }

    public function test_render_list_shows_entities(): void {
        $entities = [
            ['id' => 1, 'title' => 'First Item', 'is_active' => true],
            ['id' => 2, 'title' => 'Second Item', 'is_active' => false],
        ];

        $renderer = new HtmlRenderer();
        $html = $renderer->render_list($this->dataset, $entities);

        $this->assertStringContainsString('First Item', $html);
        $this->assertStringContainsString('Second Item', $html);
    }

    public function test_render_list_shows_field_headers(): void {
        $renderer = new HtmlRenderer();
        $html = $renderer->render_list($this->dataset, []);

        // Should show column headers based on dataset fields
        $this->assertStringContainsString('title', $html);
    }

    /**
     * ==========================================================================
     * Enqueue Assets
     * ==========================================================================
     */

    public function test_enqueue_assets_does_not_throw(): void {
        $renderer = new HtmlRenderer();

        // For plain HTML renderer, this should be a no-op
        $this->assertNull($renderer->enqueue_assets());
    }

    /**
     * ==========================================================================
     * HTML Escaping
     * ==========================================================================
     */

    public function test_field_values_are_escaped(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('title');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, ['title' => '<script>alert("xss")</script>']);

        // Should not contain raw script tag
        $this->assertStringNotContainsString('<script>', $html);
        // Should contain escaped version
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * ==========================================================================
     * Complete Form Structure
     * ==========================================================================
     */

    public function test_complete_editor_structure(): void {
        $this->layout->section('General', function(Section $s) {
            $s->field('title')->placeholder('Enter title');
            $s->field('description');
        });

        $this->layout->section('Options', function(Section $s) {
            $s->field('count');
            $s->field('is_active');
        });

        $this->layout->sidebar(function(Sidebar $sb) {
            $sb->field('status')->readonly();
            $sb->actions(['save', 'delete']);
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($this->layout, [
            'title' => 'Test Title',
            'description' => 'Test Description',
            'count' => 42,
            'is_active' => true,
            'status' => 'published',
        ]);

        // Form structure
        $this->assertStringContainsString('<form', $html);

        // Sections
        $this->assertStringContainsString('General', $html);
        $this->assertStringContainsString('Options', $html);

        // Fields with values
        $this->assertStringContainsString('value="Test Title"', $html);
        $this->assertStringContainsString('value="42"', $html);
        $this->assertStringContainsString('checked', $html);

        // Sidebar
        $this->assertStringContainsString('readonly', $html);
        $this->assertStringContainsString('type="submit"', $html);
    }
}
