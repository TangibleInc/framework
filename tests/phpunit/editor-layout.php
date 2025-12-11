<?php
namespace Tangible\Framework\Tests;

use Tangible\DataObject\DataSet;
use Tangible\EditorLayout\Layout;
use Tangible\EditorLayout\Section;
use Tangible\EditorLayout\Tabs;
use Tangible\EditorLayout\Tab;
use Tangible\EditorLayout\Sidebar;
use Tangible\EditorLayout\Field;
use Tangible\EditorLayout\Exception\InvalidFieldException;

/**
 * Tests for the EditorLayout (Layer 2: Editor Composition)
 *
 * This tests the editor layout layer:
 * - Layout structure with ordered items (sections, tabs)
 * - Sidebar as a separate layout slot
 * - Field configuration (help, placeholder, readonly, width)
 * - Nesting of sections and tabs
 * - Conditional visibility
 * - Field validation against DataSet
 *
 * Structure format:
 * [
 *     'items' => [
 *         ['type' => 'section', 'label' => '...', 'fields' => [...], 'items' => [...]],
 *         ['type' => 'tabs', 'tabs' => [['label' => '...', 'items' => [...]]]],
 *     ],
 *     'sidebar' => ['fields' => [...], 'actions' => [...]],
 * ]
 */
class EditorLayout_TestCase extends \WP_UnitTestCase {

    private DataSet $dataset;

    public function setUp(): void {
        parent::setUp();

        $this->dataset = new DataSet();
        $this->dataset->add_string('title');
        $this->dataset->add_string('description');
        $this->dataset->add_string('slug');
        $this->dataset->add_integer('count');
        $this->dataset->add_boolean('is_active');
        $this->dataset->add_string('status');
        $this->dataset->add_string('page');
        $this->dataset->add_string('body');
        $this->dataset->add_string('keywords');
    }

    /**
     * ==========================================================================
     * Layout Basic Instantiation
     * ==========================================================================
     */

    public function test_layout_can_be_instantiated(): void {
        $layout = new Layout($this->dataset);
        $this->assertInstanceOf(Layout::class, $layout);
    }

    public function test_layout_returns_dataset(): void {
        $layout = new Layout($this->dataset);
        $this->assertSame($this->dataset, $layout->get_dataset());
    }

    public function test_empty_layout_has_empty_items(): void {
        $layout = new Layout($this->dataset);
        $structure = $layout->get_structure();
        $this->assertArrayHasKey('items', $structure);
        $this->assertEmpty($structure['items']);
    }

    /**
     * ==========================================================================
     * Sections
     * ==========================================================================
     */

    public function test_layout_can_add_section(): void {
        $layout = new Layout($this->dataset);

        $result = $layout->section('General', function(Section $s) {
            $s->field('title');
        });

        $this->assertSame($layout, $result); // fluent interface
    }

    public function test_section_can_add_fields(): void {
        $layout = new Layout($this->dataset);

        $layout->section('General', function(Section $s) {
            $s->field('title');
            $s->field('description');
        });

        $structure = $layout->get_structure();
        $this->assertCount(1, $structure['items']);
        $this->assertEquals('section', $structure['items'][0]['type']);
        $this->assertEquals('General', $structure['items'][0]['label']);
        $this->assertCount(2, $structure['items'][0]['fields']);
    }

    public function test_section_can_set_columns(): void {
        $layout = new Layout($this->dataset);

        $layout->section('Settings', function(Section $s) {
            $s->columns(2);
            $s->field('title');
            $s->field('count');
        });

        $structure = $layout->get_structure();
        $this->assertEquals(2, $structure['items'][0]['columns']);
    }

    public function test_multiple_sections_can_be_added(): void {
        $layout = new Layout($this->dataset);

        $layout->section('General', function(Section $s) {
            $s->field('title');
        });

        $layout->section('Advanced', function(Section $s) {
            $s->field('slug');
        });

        $structure = $layout->get_structure();
        $this->assertCount(2, $structure['items']);
        $this->assertEquals('General', $structure['items'][0]['label']);
        $this->assertEquals('Advanced', $structure['items'][1]['label']);
    }

    /**
     * ==========================================================================
     * Field Configuration
     * ==========================================================================
     */

    public function test_field_can_set_help_text(): void {
        $layout = new Layout($this->dataset);

        $layout->section('General', function(Section $s) {
            $s->field('title')->help('Enter the display title');
        });

        $structure = $layout->get_structure();
        $field = $structure['items'][0]['fields'][0];
        $this->assertEquals('Enter the display title', $field['help']);
    }

    public function test_field_can_set_placeholder(): void {
        $layout = new Layout($this->dataset);

        $layout->section('General', function(Section $s) {
            $s->field('title')->placeholder('My Title');
        });

        $structure = $layout->get_structure();
        $field = $structure['items'][0]['fields'][0];
        $this->assertEquals('My Title', $field['placeholder']);
    }

    public function test_field_can_set_readonly(): void {
        $layout = new Layout($this->dataset);

        $layout->section('General', function(Section $s) {
            $s->field('status')->readonly();
        });

        $structure = $layout->get_structure();
        $field = $structure['items'][0]['fields'][0];
        $this->assertTrue($field['readonly']);
    }

    public function test_field_can_set_width(): void {
        $layout = new Layout($this->dataset);

        $layout->section('General', function(Section $s) {
            $s->field('title')->width('50%');
        });

        $structure = $layout->get_structure();
        $field = $structure['items'][0]['fields'][0];
        $this->assertEquals('50%', $field['width']);
    }

    public function test_field_supports_fluent_interface(): void {
        $layout = new Layout($this->dataset);

        $layout->section('General', function(Section $s) {
            $s->field('title')
              ->help('Help text')
              ->placeholder('Placeholder')
              ->width('100%');
        });

        $structure = $layout->get_structure();
        $field = $structure['items'][0]['fields'][0];
        $this->assertEquals('Help text', $field['help']);
        $this->assertEquals('Placeholder', $field['placeholder']);
        $this->assertEquals('100%', $field['width']);
    }

    /**
     * ==========================================================================
     * Field Validation
     * ==========================================================================
     */

    public function test_field_throws_exception_for_undefined_field(): void {
        $layout = new Layout($this->dataset);

        $this->expectException(InvalidFieldException::class);
        $this->expectExceptionMessage("Field 'nonexistent' is not defined in DataSet");

        $layout->section('General', function(Section $s) {
            $s->field('nonexistent');
        });
    }

    /**
     * ==========================================================================
     * Conditional Visibility
     * ==========================================================================
     */

    public function test_section_can_have_condition(): void {
        $layout = new Layout($this->dataset);

        $layout->section('Conditional', function(Section $s) {
            $s->condition('is_active', true);
            $s->field('title');
        });

        $structure = $layout->get_structure();
        $section = $structure['items'][0];
        $this->assertNotNull($section['condition']);
        $this->assertEquals('is_active', $section['condition']['field']);
        $this->assertTrue($section['condition']['value']);
    }

    public function test_field_can_have_condition(): void {
        $layout = new Layout($this->dataset);

        $layout->section('General', function(Section $s) {
            $s->field('title')->condition('is_active', true);
        });

        $structure = $layout->get_structure();
        $field = $structure['items'][0]['fields'][0];
        $this->assertNotNull($field['condition']);
        $this->assertEquals('is_active', $field['condition']['field']);
    }

    /**
     * ==========================================================================
     * Tabs
     * ==========================================================================
     */

    public function test_layout_can_add_tabs(): void {
        $layout = new Layout($this->dataset);

        $result = $layout->tabs(function(Tabs $tabs) {
            $tabs->tab('Content', function(Tab $t) {
                $t->field('title');
            });
        });

        $this->assertSame($layout, $result); // fluent interface
    }

    public function test_tabs_appear_in_items_array(): void {
        $layout = new Layout($this->dataset);

        $layout->tabs(function(Tabs $tabs) {
            $tabs->tab('Content', function(Tab $t) {
                $t->field('title');
            });
        });

        $structure = $layout->get_structure();
        $this->assertCount(1, $structure['items']);
        $this->assertEquals('tabs', $structure['items'][0]['type']);
    }

    public function test_tabs_can_contain_multiple_tabs(): void {
        $layout = new Layout($this->dataset);

        $layout->tabs(function(Tabs $tabs) {
            $tabs->tab('Content', function(Tab $t) {
                $t->field('title');
            });
            $tabs->tab('Settings', function(Tab $t) {
                $t->field('is_active');
            });
        });

        $structure = $layout->get_structure();
        $tabsItem = $structure['items'][0];
        $this->assertCount(2, $tabsItem['tabs']);
        $this->assertEquals('Content', $tabsItem['tabs'][0]['label']);
        $this->assertEquals('Settings', $tabsItem['tabs'][1]['label']);
    }

    public function test_tab_can_contain_fields(): void {
        $layout = new Layout($this->dataset);

        $layout->tabs(function(Tabs $tabs) {
            $tabs->tab('Content', function(Tab $t) {
                $t->field('title');
                $t->field('description');
            });
        });

        $structure = $layout->get_structure();
        $tab = $structure['items'][0]['tabs'][0];
        $this->assertCount(2, $tab['fields']);
    }

    public function test_tab_can_contain_sections(): void {
        $layout = new Layout($this->dataset);

        $layout->tabs(function(Tabs $tabs) {
            $tabs->tab('Content', function(Tab $t) {
                $t->section('Main', function(Section $s) {
                    $s->field('title');
                });
                $t->section('Extra', function(Section $s) {
                    $s->field('description');
                });
            });
        });

        $structure = $layout->get_structure();
        $tab = $structure['items'][0]['tabs'][0];
        // Tab uses items array too for ordering
        $this->assertCount(2, $tab['items']);
        $this->assertEquals('section', $tab['items'][0]['type']);
        $this->assertEquals('Main', $tab['items'][0]['label']);
    }

    /**
     * ==========================================================================
     * Ordering - Sections and Tabs Interleaved
     * ==========================================================================
     */

    public function test_sections_and_tabs_preserve_order(): void {
        $layout = new Layout($this->dataset);

        $layout->section('First Section', function(Section $s) {
            $s->field('title');
        });

        $layout->tabs(function(Tabs $tabs) {
            $tabs->tab('Tab 1', function(Tab $t) {
                $t->field('description');
            });
        });

        $layout->section('Second Section', function(Section $s) {
            $s->field('slug');
        });

        $structure = $layout->get_structure();
        $this->assertCount(3, $structure['items']);
        $this->assertEquals('section', $structure['items'][0]['type']);
        $this->assertEquals('First Section', $structure['items'][0]['label']);
        $this->assertEquals('tabs', $structure['items'][1]['type']);
        $this->assertEquals('section', $structure['items'][2]['type']);
        $this->assertEquals('Second Section', $structure['items'][2]['label']);
    }

    /**
     * ==========================================================================
     * Nesting
     * ==========================================================================
     */

    public function test_section_can_contain_nested_items(): void {
        $layout = new Layout($this->dataset);

        $layout->section('Main', function(Section $s) {
            $s->field('title');
            $s->section('Advanced', function(Section $nested) {
                $nested->field('slug');
            });
        });

        $structure = $layout->get_structure();
        $section = $structure['items'][0];
        $this->assertCount(1, $section['fields']);
        $this->assertCount(1, $section['items']);
        $this->assertEquals('section', $section['items'][0]['type']);
        $this->assertEquals('Advanced', $section['items'][0]['label']);
    }

    public function test_section_can_contain_tabs(): void {
        $layout = new Layout($this->dataset);

        $layout->section('Main', function(Section $s) {
            $s->field('title');
            $s->tabs(function(Tabs $tabs) {
                $tabs->tab('Content', function(Tab $t) {
                    $t->field('body');
                });
                $tabs->tab('Meta', function(Tab $t) {
                    $t->field('keywords');
                });
            });
        });

        $structure = $layout->get_structure();
        $section = $structure['items'][0];
        $this->assertCount(1, $section['items']);
        $this->assertEquals('tabs', $section['items'][0]['type']);
        $this->assertCount(2, $section['items'][0]['tabs']);
    }

    public function test_tab_can_contain_nested_tabs(): void {
        $layout = new Layout($this->dataset);

        $layout->tabs(function(Tabs $tabs) {
            $tabs->tab('Main', function(Tab $t) {
                $t->tabs(function(Tabs $nested) {
                    $nested->tab('Sub 1', function(Tab $st) {
                        $st->field('title');
                    });
                    $nested->tab('Sub 2', function(Tab $st) {
                        $st->field('description');
                    });
                });
            });
        });

        $structure = $layout->get_structure();
        $tab = $structure['items'][0]['tabs'][0];
        $this->assertCount(1, $tab['items']);
        $this->assertEquals('tabs', $tab['items'][0]['type']);
        $this->assertCount(2, $tab['items'][0]['tabs']);
    }

    public function test_deeply_nested_structure(): void {
        $layout = new Layout($this->dataset);

        $layout->section('Level 1', function(Section $s1) {
            $s1->section('Level 2', function(Section $s2) {
                $s2->tabs(function(Tabs $tabs) {
                    $tabs->tab('Level 3', function(Tab $t) {
                        $t->section('Level 4', function(Section $s4) {
                            $s4->field('title');
                        });
                    });
                });
            });
        });

        $structure = $layout->get_structure();

        // Navigate the structure
        $level1 = $structure['items'][0];
        $this->assertEquals('Level 1', $level1['label']);

        $level2 = $level1['items'][0];
        $this->assertEquals('Level 2', $level2['label']);

        $tabsContainer = $level2['items'][0];
        $this->assertEquals('tabs', $tabsContainer['type']);

        $level3 = $tabsContainer['tabs'][0];
        $this->assertEquals('Level 3', $level3['label']);

        $level4 = $level3['items'][0];
        $this->assertEquals('Level 4', $level4['label']);
        $this->assertCount(1, $level4['fields']);
    }

    /**
     * ==========================================================================
     * Sidebar
     * ==========================================================================
     */

    public function test_layout_can_add_sidebar(): void {
        $layout = new Layout($this->dataset);

        $result = $layout->sidebar(function(Sidebar $sb) {
            $sb->field('status');
        });

        $this->assertSame($layout, $result); // fluent interface
    }

    public function test_sidebar_is_separate_from_items(): void {
        $layout = new Layout($this->dataset);

        $layout->section('Main', function(Section $s) {
            $s->field('title');
        });

        $layout->sidebar(function(Sidebar $sb) {
            $sb->field('status');
        });

        $structure = $layout->get_structure();
        $this->assertArrayHasKey('items', $structure);
        $this->assertArrayHasKey('sidebar', $structure);
        $this->assertCount(1, $structure['items']); // sidebar not in items
    }

    public function test_sidebar_can_contain_fields(): void {
        $layout = new Layout($this->dataset);

        $layout->sidebar(function(Sidebar $sb) {
            $sb->field('status')->readonly();
            $sb->field('is_active');
        });

        $structure = $layout->get_structure();
        $this->assertNotNull($structure['sidebar']);
        $this->assertCount(2, $structure['sidebar']['fields']);
        $this->assertTrue($structure['sidebar']['fields'][0]['readonly']);
    }

    public function test_sidebar_can_have_actions(): void {
        $layout = new Layout($this->dataset);

        $layout->sidebar(function(Sidebar $sb) {
            $sb->actions(['save', 'delete']);
        });

        $structure = $layout->get_structure();
        $this->assertCount(2, $structure['sidebar']['actions']);
        $this->assertContains('save', $structure['sidebar']['actions']);
        $this->assertContains('delete', $structure['sidebar']['actions']);
    }

    public function test_sidebar_can_have_fields_and_actions(): void {
        $layout = new Layout($this->dataset);

        $layout->sidebar(function(Sidebar $sb) {
            $sb->field('status')->readonly();
            $sb->actions(['save', 'delete']);
        });

        $structure = $layout->get_structure();
        $this->assertCount(1, $structure['sidebar']['fields']);
        $this->assertCount(2, $structure['sidebar']['actions']);
    }

    /**
     * ==========================================================================
     * Complete Layout Structure
     * ==========================================================================
     */

    public function test_complete_layout_structure(): void {
        $layout = new Layout($this->dataset);

        // Add sections and tabs in order
        $layout->section('General', function(Section $s) {
            $s->field('title')->help('Enter title')->placeholder('My Title');
            $s->field('is_active');
        });

        $layout->tabs(function(Tabs $tabs) {
            $tabs->tab('Content', function(Tab $t) {
                $t->section('Main', function(Section $s) {
                    $s->field('body');
                });
            });
            $tabs->tab('Meta', function(Tab $t) {
                $t->field('keywords');
            });
        });

        $layout->section('Page Settings', function(Section $s) {
            $s->columns(2);
            $s->field('page');
            $s->condition('is_active', true);
        });

        // Add sidebar
        $layout->sidebar(function(Sidebar $sb) {
            $sb->field('status')->readonly();
            $sb->actions(['save', 'delete']);
        });

        $structure = $layout->get_structure();

        // Verify complete structure
        $this->assertCount(3, $structure['items']); // 2 sections + 1 tabs
        $this->assertEquals('section', $structure['items'][0]['type']);
        $this->assertEquals('tabs', $structure['items'][1]['type']);
        $this->assertEquals('section', $structure['items'][2]['type']);
        $this->assertNotNull($structure['sidebar']);
        $this->assertCount(2, $structure['sidebar']['actions']);
    }

    /**
     * ==========================================================================
     * Fluent Interface
     * ==========================================================================
     */

    public function test_layout_fluent_interface(): void {
        $layout = new Layout($this->dataset);

        $result = $layout
            ->section('General', function(Section $s) {
                $s->field('title');
            })
            ->section('Advanced', function(Section $s) {
                $s->field('slug');
            })
            ->tabs(function(Tabs $tabs) {
                $tabs->tab('Content', function(Tab $t) {
                    $t->field('body');
                });
            })
            ->sidebar(function(Sidebar $sb) {
                $sb->field('status');
            });

        $this->assertInstanceOf(Layout::class, $result);
    }
}
