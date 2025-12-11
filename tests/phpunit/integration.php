<?php
namespace Tangible\Framework\Tests;

use Tangible\DataObject\DataSet;
use Tangible\DataObject\PluralObject;
use Tangible\EditorLayout\Layout;
use Tangible\EditorLayout\Section;
use Tangible\EditorLayout\Tabs;
use Tangible\EditorLayout\Tab;
use Tangible\EditorLayout\Sidebar;
use Tangible\RequestHandler\PluralHandler;
use Tangible\RequestHandler\Validators;
use Tangible\Renderer\HtmlRenderer;

/**
 * Integration tests - All 4 layers working together
 */
class Integration_TestCase extends \WP_UnitTestCase {

    /**
     * Test complete workflow: Define -> Layout -> Render -> Handle
     */
    public function test_complete_crud_workflow(): void {
        // =====================================================================
        // LAYER 1: Data Definition
        // =====================================================================
        $dataset = new DataSet();
        $dataset
            ->add_string('title')
            ->add_string('description')
            ->add_integer('priority')
            ->add_boolean('is_published');

        // =====================================================================
        // LAYER 2: Editor Composition
        // =====================================================================
        $layout = new Layout($dataset);

        $layout->section('General', function(Section $s) {
            $s->field('title')
              ->placeholder('Enter title')
              ->help('The main title for this item');
            $s->field('description');
        });

        $layout->section('Settings', function(Section $s) {
            $s->field('priority');
            $s->field('is_published');
        });

        $layout->sidebar(function(Sidebar $sb) {
            $sb->actions(['save', 'delete']);
        });

        // =====================================================================
        // LAYER 3: UI Presentation
        // =====================================================================
        $renderer = new HtmlRenderer();

        // Render empty form (for create)
        $createFormHtml = $renderer->render_editor($layout, []);

        $this->assertStringContainsString('<form', $createFormHtml);
        $this->assertStringContainsString('name="title"', $createFormHtml);
        $this->assertStringContainsString('placeholder="Enter title"', $createFormHtml);
        $this->assertStringContainsString('type="checkbox"', $createFormHtml);
        $this->assertStringContainsString('type="submit"', $createFormHtml);

        // =====================================================================
        // LAYER 4: Request Handling (with actual persistence)
        // =====================================================================
        $object = new PluralObject('integ_test_item');
        $object->set_dataset($dataset);
        $object->register(['public' => false]);

        $handler = new PluralHandler($object);
        $handler->add_validator('title', Validators::required());
        $handler->add_validator('title', Validators::min_length(3));

        // Test validation failure
        $result = $handler->create(['title' => 'AB']); // Too short
        $this->assertTrue($result->is_error());
        $this->assertNotEmpty($result->get_field_errors('title'));

        // Test successful create
        $result = $handler->create([
            'title' => 'Test Item',
            'description' => 'A test description',
            'priority' => '5', // String that will be coerced to int
            'is_published' => 'yes', // String that will be coerced to bool
        ]);

        $this->assertTrue($result->is_success(), 'Create failed: ' . json_encode($result->get_errors()));
        $entity = $result->get_entity();
        $this->assertNotNull($entity);
        $this->assertGreaterThan(0, $entity->get_id(), 'Entity ID should be > 0 after create');

        // Verify type coercion worked
        $this->assertSame(5, $entity->get('priority'));
        $this->assertSame(true, $entity->get('is_published'));

        // Render edit form (with data)
        $editFormHtml = $renderer->render_editor($layout, [
            'title' => $entity->get('title'),
            'description' => $entity->get('description'),
            'priority' => $entity->get('priority'),
            'is_published' => $entity->get('is_published'),
        ]);

        $this->assertStringContainsString('value="Test Item"', $editFormHtml);
        $this->assertStringContainsString('value="5"', $editFormHtml);
        $this->assertStringContainsString('checked', $editFormHtml);

        // Test update
        $updateResult = $handler->update($entity->get_id(), [
            'title' => 'Updated Title',
            'priority' => 10,
        ]);

        $this->assertTrue(
            $updateResult->is_success(),
            'Update failed for ID ' . $entity->get_id() . ': ' . json_encode($updateResult->get_errors())
        );
        $this->assertEquals('Updated Title', $updateResult->get_entity()->get('title'));
        $this->assertSame(10, $updateResult->get_entity()->get('priority'));

        // Test list rendering
        $listResult = $handler->list();
        $this->assertTrue($listResult->is_success());

        $entities = array_map(function($e) {
            return [
                'title' => $e->get('title'),
                'description' => $e->get('description'),
                'priority' => $e->get('priority'),
                'is_published' => $e->get('is_published'),
            ];
        }, $listResult->get_entities());

        $listHtml = $renderer->render_list($dataset, $entities);
        $this->assertStringContainsString('<table', $listHtml);
        $this->assertStringContainsString('Updated Title', $listHtml);

        // Test delete
        $deleteResult = $handler->delete($entity->get_id());
        $this->assertTrue($deleteResult->is_success());

        // Verify deleted
        $readResult = $handler->read($entity->get_id());
        $this->assertTrue($readResult->is_error());
    }

    /**
     * Test rendering with tabs layout
     */
    public function test_tabbed_layout_rendering(): void {
        $dataset = new DataSet();
        $dataset
            ->add_string('title')
            ->add_string('content')
            ->add_string('seo_title')
            ->add_string('seo_description');

        $layout = new Layout($dataset);

        $layout->tabs(function(Tabs $tabs) {
            $tabs->tab('Content', function(Tab $t) {
                $t->field('title');
                $t->field('content');
            });
            $tabs->tab('SEO', function(Tab $t) {
                $t->field('seo_title');
                $t->field('seo_description');
            });
        });

        $layout->sidebar(function(Sidebar $sb) {
            $sb->actions(['save']);
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($layout, []);

        // Check tabs structure
        $this->assertStringContainsString('Content', $html);
        $this->assertStringContainsString('SEO', $html);
        $this->assertStringContainsString('name="title"', $html);
        $this->assertStringContainsString('name="seo_title"', $html);
    }

    /**
     * Test nested sections and tabs
     */
    public function test_nested_layout_rendering(): void {
        $dataset = new DataSet();
        $dataset
            ->add_string('title')
            ->add_string('subtitle')
            ->add_string('body');

        $layout = new Layout($dataset);

        $layout->section('Main', function(Section $s) {
            $s->field('title');
            $s->section('Nested', function(Section $nested) {
                $nested->field('subtitle');
            });
        });

        $layout->section('Content', function(Section $s) {
            $s->field('body');
        });

        $renderer = new HtmlRenderer();
        $html = $renderer->render_editor($layout, [
            'title' => 'Main Title',
            'subtitle' => 'Sub Title',
            'body' => 'Body content',
        ]);

        $this->assertStringContainsString('Main', $html);
        $this->assertStringContainsString('Nested', $html);
        $this->assertStringContainsString('value="Main Title"', $html);
        $this->assertStringContainsString('value="Sub Title"', $html);
    }

    /**
     * Test that the rendered form can be "submitted" through the handler
     */
    public function test_form_submission_simulation(): void {
        $dataset = new DataSet();
        $dataset
            ->add_string('name')
            ->add_string('email')
            ->add_boolean('subscribe');

        $layout = new Layout($dataset);
        $layout->section('Contact', function(Section $s) {
            $s->field('name');
            $s->field('email');
            $s->field('subscribe');
        });
        $layout->sidebar(function(Sidebar $sb) {
            $sb->actions(['save']);
        });

        $object = new PluralObject('contact_submission');
        $object->set_dataset($dataset);
        $object->register(['public' => false]);

        $handler = new PluralHandler($object);
        $handler->add_validator('email', Validators::required());
        $handler->add_validator('email', Validators::email());

        // Simulate form submission with invalid email
        $formData = [
            'name' => 'John Doe',
            'email' => 'not-an-email',
            'subscribe' => '1',
        ];

        $result = $handler->create($formData);
        $this->assertTrue($result->is_error());
        $this->assertNotEmpty($result->get_field_errors('email'));

        // Simulate form submission with valid data
        $formData['email'] = 'john@example.com';
        $result = $handler->create($formData);

        $this->assertTrue($result->is_success());
        $entity = $result->get_entity();
        $this->assertEquals('John Doe', $entity->get('name'));
        $this->assertEquals('john@example.com', $entity->get('email'));
        $this->assertTrue($entity->get('subscribe'));
    }
}
