<?php
namespace Tangible\Framework\Tests;

use Tangible\DataObject\DataSet;
use Tangible\DataObject\SingularObject;
use Tangible\RequestHandler\SingularHandler;
use Tangible\RequestHandler\Result;
use Tangible\RequestHandler\ValidationError;
use Tangible\RequestHandler\Validators;

/**
 * Tests for the SingularHandler (Layer 4: Request Handling for Singular Objects)
 *
 * This tests the request handling layer for singular objects:
 * - Read/Update operations with validation
 * - Custom validators
 * - Lifecycle hooks (before/after update)
 * - Result for success/error responses
 */
class SingularHandler_TestCase extends \WP_UnitTestCase {

    private DataSet $dataset;
    private SingularObject $object;

    public function setUp(): void {
        parent::setUp();

        $this->dataset = new DataSet();
        $this->dataset->add_string('site_title');
        $this->dataset->add_integer('posts_per_page');
        $this->dataset->add_boolean('is_public');

        $this->object = new SingularObject('test_settings');
        $this->object->set_dataset($this->dataset);
    }

    /**
     * ==========================================================================
     * Basic Instantiation
     * ==========================================================================
     */

    public function test_singular_handler_can_be_instantiated(): void {
        $handler = new SingularHandler($this->object);
        $this->assertInstanceOf(SingularHandler::class, $handler);
    }

    /**
     * ==========================================================================
     * Read/Update Operations
     * ==========================================================================
     */

    public function test_read_returns_result(): void {
        $handler = new SingularHandler($this->object);

        $result = $handler->read();

        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->is_success());
    }

    public function test_read_returns_all_field_values(): void {
        $this->object->set('site_title', 'My Site');
        $this->object->set('posts_per_page', 10);
        $this->object->set('is_public', true);
        $this->object->save();

        $handler = new SingularHandler($this->object);
        $result = $handler->read();

        $data = $result->get_data();
        $this->assertEquals('My Site', $data['site_title']);
        $this->assertEquals(10, $data['posts_per_page']);
        $this->assertTrue($data['is_public']);
    }

    public function test_update_returns_result(): void {
        $handler = new SingularHandler($this->object);

        $result = $handler->update([
            'site_title' => 'New Title',
        ]);

        $this->assertInstanceOf(Result::class, $result);
    }

    public function test_update_success_modifies_values(): void {
        $handler = new SingularHandler($this->object);

        $result = $handler->update([
            'site_title' => 'Updated Title',
            'posts_per_page' => 20,
            'is_public' => false,
        ]);

        $this->assertTrue($result->is_success());
        $this->assertFalse($result->is_error());

        $data = $result->get_data();
        $this->assertEquals('Updated Title', $data['site_title']);
        $this->assertEquals(20, $data['posts_per_page']);
        $this->assertFalse($data['is_public']);
    }

    public function test_update_persists_to_storage(): void {
        $handler = new SingularHandler($this->object);

        $handler->update([
            'site_title' => 'Persisted Title',
        ]);

        // Create a new handler and read to verify persistence
        $newObject = new SingularObject('test_settings');
        $newObject->set_dataset($this->dataset);
        $newObject->load();

        $this->assertEquals('Persisted Title', $newObject->get('site_title'));
    }

    /**
     * ==========================================================================
     * Validation
     * ==========================================================================
     */

    public function test_update_coerces_types_from_dataset(): void {
        $handler = new SingularHandler($this->object);

        $result = $handler->update([
            'site_title' => 'Test',
            'posts_per_page' => '15',    // string should become int
            'is_public' => '1',          // string should become bool
        ]);

        $this->assertTrue($result->is_success());
        $data = $result->get_data();
        $this->assertSame(15, $data['posts_per_page']);
        $this->assertSame(true, $data['is_public']);
    }

    public function test_custom_validator_can_reject_value(): void {
        $handler = new SingularHandler($this->object);
        $handler->add_validator('site_title', function($value) {
            if (strlen($value) < 3) {
                return new ValidationError('Title must be at least 3 characters', 'site_title');
            }
            return true;
        });

        $result = $handler->update(['site_title' => 'AB']);

        $this->assertTrue($result->is_error());
        $this->assertNotEmpty($result->get_errors());
        $this->assertNotEmpty($result->get_field_errors('site_title'));
    }

    public function test_custom_validator_passes_valid_value(): void {
        $handler = new SingularHandler($this->object);
        $handler->add_validator('site_title', function($value) {
            if (strlen($value) < 3) {
                return new ValidationError('Title must be at least 3 characters', 'site_title');
            }
            return true;
        });

        $result = $handler->update(['site_title' => 'Valid Title']);

        $this->assertTrue($result->is_success());
    }

    public function test_multiple_validators_can_be_added(): void {
        $handler = new SingularHandler($this->object);

        $handler->add_validator('site_title', function($value) {
            if (strlen($value) < 3) {
                return new ValidationError('Title too short', 'site_title');
            }
            return true;
        });

        $handler->add_validator('posts_per_page', function($value) {
            if ($value < 1) {
                return new ValidationError('Must be at least 1', 'posts_per_page');
            }
            return true;
        });

        $result = $handler->update([
            'site_title' => 'AB',
            'posts_per_page' => 0,
        ]);

        $this->assertTrue($result->is_error());
        $this->assertNotEmpty($result->get_field_errors('site_title'));
        $this->assertNotEmpty($result->get_field_errors('posts_per_page'));
    }

    public function test_validation_failure_does_not_persist(): void {
        $this->object->set('site_title', 'Original');
        $this->object->save();

        $handler = new SingularHandler($this->object);
        $handler->add_validator('site_title', Validators::min_length(5));

        $result = $handler->update(['site_title' => 'AB']);

        $this->assertTrue($result->is_error());

        // Verify original value is unchanged
        $newObject = new SingularObject('test_settings');
        $newObject->set_dataset($this->dataset);
        $newObject->load();

        $this->assertEquals('Original', $newObject->get('site_title'));
    }

    /**
     * ==========================================================================
     * Lifecycle Hooks
     * ==========================================================================
     */

    public function test_before_update_can_modify_data(): void {
        $handler = new SingularHandler($this->object);
        $handler->before_update(function($current, $data) {
            $data['site_title'] = strtoupper($data['site_title']);
            return $data;
        });

        $result = $handler->update(['site_title' => 'lowercase']);

        $this->assertTrue($result->is_success());
        $this->assertEquals('LOWERCASE', $result->get_data()['site_title']);
    }

    public function test_before_update_receives_current_data(): void {
        $this->object->set('site_title', 'Current Value');
        $this->object->save();

        $handler = new SingularHandler($this->object);
        $receivedCurrent = null;

        $handler->before_update(function($current, $data) use (&$receivedCurrent) {
            $receivedCurrent = $current;
            return $data;
        });

        $handler->update(['site_title' => 'New Value']);

        $this->assertNotNull($receivedCurrent);
        $this->assertEquals('Current Value', $receivedCurrent['site_title']);
    }

    public function test_after_update_receives_updated_data(): void {
        $handler = new SingularHandler($this->object);
        $receivedData = null;

        $handler->after_update(function($data) use (&$receivedData) {
            $receivedData = $data;
        });

        $handler->update(['site_title' => 'Updated']);

        $this->assertNotNull($receivedData);
        $this->assertEquals('Updated', $receivedData['site_title']);
    }

    /**
     * ==========================================================================
     * Fluent Interface
     * ==========================================================================
     */

    public function test_fluent_interface(): void {
        $handler = new SingularHandler($this->object);

        $result = $handler
            ->add_validator('site_title', fn($v) => true)
            ->before_update(fn($c, $d) => $d)
            ->after_update(fn($d) => null)
            ->set_capability('manage_options');

        $this->assertInstanceOf(SingularHandler::class, $result);
    }

    /**
     * ==========================================================================
     * Built-in Validators Integration
     * ==========================================================================
     */

    public function test_validators_integrate_with_singular_handler(): void {
        $handler = new SingularHandler($this->object);

        $handler
            ->add_validator('site_title', Validators::required())
            ->add_validator('site_title', Validators::min_length(3))
            ->add_validator('posts_per_page', Validators::min(1))
            ->add_validator('posts_per_page', Validators::max(100));

        // Valid data
        $result = $handler->update([
            'site_title' => 'Valid Title',
            'posts_per_page' => 50,
        ]);
        $this->assertTrue($result->is_success());

        // Invalid data
        $result = $handler->update([
            'site_title' => 'AB',
            'posts_per_page' => 150,
        ]);
        $this->assertTrue($result->is_error());
        $this->assertNotEmpty($result->get_field_errors('site_title'));
        $this->assertNotEmpty($result->get_field_errors('posts_per_page'));
    }
}
