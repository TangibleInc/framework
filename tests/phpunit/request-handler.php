<?php
namespace Tangible\Framework\Tests;

use Tangible\DataObject\DataSet;
use Tangible\DataObject\PluralObject;
use Tangible\RequestHandler\Handler;
use Tangible\RequestHandler\Result;
use Tangible\RequestHandler\ValidationError;
use Tangible\RequestHandler\Validators;

/**
 * Tests for the Handler (Layer 4: Request Handling)
 *
 * This tests the request handling layer:
 * - CRUD operations with validation
 * - Custom validators
 * - Lifecycle hooks (before/after create, update, delete)
 * - Permission checks
 * - Result for success/error responses
 */
class Handler_TestCase extends \WP_UnitTestCase {

    private DataSet $dataset;
    private PluralObject $object;

    public function setUp(): void {
        parent::setUp();

        $this->dataset = new DataSet();
        $this->dataset->add_string('title');
        $this->dataset->add_integer('count');
        $this->dataset->add_boolean('is_active');

        $this->object = new PluralObject('test_entity');
        $this->object->set_dataset($this->dataset);
        $this->object->register([
            'public' => false,
            'show_ui' => true,
        ]);
    }

    /**
     * ==========================================================================
     * Basic Instantiation
     * ==========================================================================
     */

    public function test_handler_can_be_instantiated(): void {
        $handler = new Handler($this->object);
        $this->assertInstanceOf(Handler::class, $handler);
    }

    /**
     * ==========================================================================
     * CRUD Operations
     * ==========================================================================
     */

    public function test_create_returns_request_result(): void {
        $handler = new Handler($this->object);

        $result = $handler->create([
            'title' => 'Test Entity',
            'count' => 10,
            'is_active' => true,
        ]);

        $this->assertInstanceOf(Result::class, $result);
    }

    public function test_create_success_contains_entity(): void {
        $handler = new Handler($this->object);

        $result = $handler->create([
            'title' => 'Test Entity',
            'count' => 10,
            'is_active' => true,
        ]);

        $this->assertTrue($result->is_success());
        $this->assertFalse($result->is_error());
        $this->assertNotNull($result->get_entity());
        $this->assertEquals('Test Entity', $result->get_entity()->get('title'));
    }

    public function test_read_returns_entity(): void {
        $handler = new Handler($this->object);

        $createResult = $handler->create(['title' => 'Test Entity']);
        $id = $createResult->get_entity()->get_id();

        $result = $handler->read($id);

        $this->assertTrue($result->is_success());
        $this->assertEquals('Test Entity', $result->get_entity()->get('title'));
    }

    public function test_read_nonexistent_returns_error(): void {
        $handler = new Handler($this->object);

        $result = $handler->read(999999);

        $this->assertTrue($result->is_error());
        $this->assertFalse($result->is_success());
    }

    public function test_update_modifies_entity(): void {
        $handler = new Handler($this->object);

        $createResult = $handler->create(['title' => 'Original Title']);
        $id = $createResult->get_entity()->get_id();

        $result = $handler->update($id, ['title' => 'Updated Title']);

        $this->assertTrue($result->is_success());
        $this->assertEquals('Updated Title', $result->get_entity()->get('title'));

        // Verify persistence
        $readResult = $handler->read($id);
        $this->assertEquals('Updated Title', $readResult->get_entity()->get('title'));
    }

    public function test_update_nonexistent_returns_error(): void {
        $handler = new Handler($this->object);

        $result = $handler->update(999999, ['title' => 'Test']);

        $this->assertTrue($result->is_error());
    }

    public function test_delete_removes_entity(): void {
        $handler = new Handler($this->object);

        $createResult = $handler->create(['title' => 'To Delete']);
        $id = $createResult->get_entity()->get_id();

        $result = $handler->delete($id);

        $this->assertTrue($result->is_success());

        // Verify deletion
        $readResult = $handler->read($id);
        $this->assertTrue($readResult->is_error());
    }

    public function test_delete_nonexistent_returns_error(): void {
        $handler = new Handler($this->object);

        $result = $handler->delete(999999);

        $this->assertTrue($result->is_error());
    }

    public function test_list_returns_all_entities(): void {
        $handler = new Handler($this->object);

        $handler->create(['title' => 'Entity One']);
        $handler->create(['title' => 'Entity Two']);
        $handler->create(['title' => 'Entity Three']);

        $result = $handler->list();

        $this->assertTrue($result->is_success());
        $this->assertCount(3, $result->get_entities());
    }

    /**
     * ==========================================================================
     * Validation
     * ==========================================================================
     */

    public function test_create_coerces_types_from_dataset(): void {
        $handler = new Handler($this->object);

        $result = $handler->create([
            'title' => 'Test',
            'count' => '42',       // string should become int
            'is_active' => '1',    // string should become bool
        ]);

        $this->assertTrue($result->is_success());
        $this->assertSame(42, $result->get_entity()->get('count'));
        $this->assertSame(true, $result->get_entity()->get('is_active'));
    }

    public function test_custom_validator_can_reject_value(): void {
        $handler = new Handler($this->object);
        $handler->add_validator('title', function($value) {
            if (strlen($value) < 3) {
                return new ValidationError('Title must be at least 3 characters', 'title');
            }
            return true;
        });

        $result = $handler->create(['title' => 'AB']);

        $this->assertTrue($result->is_error());
        $this->assertNotEmpty($result->get_errors());
        $this->assertNotEmpty($result->get_field_errors('title'));
    }

    public function test_custom_validator_passes_valid_value(): void {
        $handler = new Handler($this->object);
        $handler->add_validator('title', function($value) {
            if (strlen($value) < 3) {
                return new ValidationError('Title must be at least 3 characters', 'title');
            }
            return true;
        });

        $result = $handler->create(['title' => 'Valid Title']);

        $this->assertTrue($result->is_success());
    }

    public function test_multiple_validators_can_be_added(): void {
        $handler = new Handler($this->object);

        $handler->add_validator('title', function($value) {
            if (strlen($value) < 3) {
                return new ValidationError('Title too short', 'title');
            }
            return true;
        });

        $handler->add_validator('count', function($value) {
            if ($value < 0) {
                return new ValidationError('Count must be positive', 'count');
            }
            return true;
        });

        $result = $handler->create([
            'title' => 'AB',
            'count' => -5,
        ]);

        $this->assertTrue($result->is_error());
        $this->assertNotEmpty($result->get_field_errors('title'));
        $this->assertNotEmpty($result->get_field_errors('count'));
    }

    public function test_validation_runs_on_update(): void {
        $handler = new Handler($this->object);
        $handler->add_validator('title', function($value) {
            if (strlen($value) < 3) {
                return new ValidationError('Title too short', 'title');
            }
            return true;
        });

        $createResult = $handler->create(['title' => 'Valid Title']);
        $id = $createResult->get_entity()->get_id();

        $result = $handler->update($id, ['title' => 'AB']);

        $this->assertTrue($result->is_error());
    }

    /**
     * ==========================================================================
     * Lifecycle Hooks
     * ==========================================================================
     */

    public function test_before_create_can_modify_data(): void {
        $handler = new Handler($this->object);
        $handler->before_create(function($data) {
            $data['title'] = strtoupper($data['title']);
            return $data;
        });

        $result = $handler->create(['title' => 'lowercase']);

        $this->assertTrue($result->is_success());
        $this->assertEquals('LOWERCASE', $result->get_entity()->get('title'));
    }

    public function test_after_create_receives_entity(): void {
        $handler = new Handler($this->object);
        $receivedEntity = null;

        $handler->after_create(function($entity) use (&$receivedEntity) {
            $receivedEntity = $entity;
        });

        $result = $handler->create(['title' => 'Test']);

        $this->assertNotNull($receivedEntity);
        $this->assertEquals('Test', $receivedEntity->get('title'));
    }

    public function test_before_update_can_modify_data(): void {
        $handler = new Handler($this->object);

        $createResult = $handler->create(['title' => 'Original']);
        $id = $createResult->get_entity()->get_id();

        $handler->before_update(function($id, $data) {
            $data['title'] = $data['title'] . ' (modified)';
            return $data;
        });

        $result = $handler->update($id, ['title' => 'Updated']);

        $this->assertEquals('Updated (modified)', $result->get_entity()->get('title'));
    }

    public function test_after_update_receives_entity(): void {
        $handler = new Handler($this->object);
        $receivedEntity = null;

        $createResult = $handler->create(['title' => 'Original']);
        $id = $createResult->get_entity()->get_id();

        $handler->after_update(function($entity) use (&$receivedEntity) {
            $receivedEntity = $entity;
        });

        $handler->update($id, ['title' => 'Updated']);

        $this->assertNotNull($receivedEntity);
        $this->assertEquals('Updated', $receivedEntity->get('title'));
    }

    public function test_before_delete_can_cancel_deletion(): void {
        $handler = new Handler($this->object);

        $createResult = $handler->create(['title' => 'Protected']);
        $id = $createResult->get_entity()->get_id();

        $handler->before_delete(function($entity) {
            return false; // Cancel deletion
        });

        $result = $handler->delete($id);

        $this->assertTrue($result->is_error());

        // Verify entity still exists
        $readResult = $handler->read($id);
        $this->assertTrue($readResult->is_success());
    }

    public function test_after_delete_receives_id(): void {
        $handler = new Handler($this->object);
        $receivedId = null;

        $createResult = $handler->create(['title' => 'To Delete']);
        $id = $createResult->get_entity()->get_id();

        $handler->after_delete(function($deletedId) use (&$receivedId) {
            $receivedId = $deletedId;
        });

        $handler->delete($id);

        $this->assertEquals($id, $receivedId);
    }

    /**
     * ==========================================================================
     * Fluent Interface
     * ==========================================================================
     */

    public function test_fluent_interface(): void {
        $handler = new Handler($this->object);

        $result = $handler
            ->add_validator('title', fn($v) => true)
            ->before_create(fn($d) => $d)
            ->after_create(fn($e) => null)
            ->before_update(fn($i, $d) => $d)
            ->after_update(fn($e) => null)
            ->before_delete(fn($e) => true)
            ->after_delete(fn($i) => null)
            ->set_capability('edit_posts');

        $this->assertInstanceOf(Handler::class, $result);
    }

    /**
     * ==========================================================================
     * ValidationError
     * ==========================================================================
     */

    public function test_validation_error_stores_message_and_field(): void {
        $error = new ValidationError('Test message', 'test_field');

        $this->assertEquals('Test message', $error->get_message());
        $this->assertEquals('test_field', $error->get_field());
    }

    public function test_validation_error_field_is_optional(): void {
        $error = new ValidationError('General error');

        $this->assertEquals('General error', $error->get_message());
        $this->assertNull($error->get_field());
    }

    /**
     * ==========================================================================
     * Built-in Validators
     * ==========================================================================
     */

    public function test_validators_required_rejects_null(): void {
        $validator = Validators::required();

        $result = $validator(null);

        $this->assertInstanceOf(ValidationError::class, $result);
    }

    public function test_validators_required_rejects_empty_string(): void {
        $validator = Validators::required();

        $result = $validator('');

        $this->assertInstanceOf(ValidationError::class, $result);
    }

    public function test_validators_required_passes_non_empty_value(): void {
        $validator = Validators::required();

        $this->assertTrue($validator('hello'));
        $this->assertTrue($validator(0));
        $this->assertTrue($validator(false));
    }

    public function test_validators_min_length_rejects_short_string(): void {
        $validator = Validators::min_length(5);

        $result = $validator('abc');

        $this->assertInstanceOf(ValidationError::class, $result);
    }

    public function test_validators_min_length_passes_long_enough_string(): void {
        $validator = Validators::min_length(5);

        $this->assertTrue($validator('hello'));
        $this->assertTrue($validator('hello world'));
    }

    public function test_validators_min_length_passes_exact_length(): void {
        $validator = Validators::min_length(5);

        $this->assertTrue($validator('12345'));
    }

    public function test_validators_max_length_rejects_long_string(): void {
        $validator = Validators::max_length(5);

        $result = $validator('hello world');

        $this->assertInstanceOf(ValidationError::class, $result);
    }

    public function test_validators_max_length_passes_short_enough_string(): void {
        $validator = Validators::max_length(5);

        $this->assertTrue($validator('hi'));
        $this->assertTrue($validator('hello'));
    }

    public function test_validators_min_rejects_small_number(): void {
        $validator = Validators::min(10);

        $result = $validator(5);

        $this->assertInstanceOf(ValidationError::class, $result);
    }

    public function test_validators_min_passes_large_enough_number(): void {
        $validator = Validators::min(10);

        $this->assertTrue($validator(10));
        $this->assertTrue($validator(100));
    }

    public function test_validators_min_works_with_floats(): void {
        $validator = Validators::min(1.5);

        $this->assertInstanceOf(ValidationError::class, $validator(1.0));
        $this->assertTrue($validator(1.5));
        $this->assertTrue($validator(2.0));
    }

    public function test_validators_max_rejects_large_number(): void {
        $validator = Validators::max(10);

        $result = $validator(15);

        $this->assertInstanceOf(ValidationError::class, $result);
    }

    public function test_validators_max_passes_small_enough_number(): void {
        $validator = Validators::max(10);

        $this->assertTrue($validator(5));
        $this->assertTrue($validator(10));
    }

    public function test_validators_in_rejects_value_not_in_list(): void {
        $validator = Validators::in(['draft', 'published', 'archived']);

        $result = $validator('deleted');

        $this->assertInstanceOf(ValidationError::class, $result);
    }

    public function test_validators_in_passes_value_in_list(): void {
        $validator = Validators::in(['draft', 'published', 'archived']);

        $this->assertTrue($validator('draft'));
        $this->assertTrue($validator('published'));
        $this->assertTrue($validator('archived'));
    }

    public function test_validators_in_uses_strict_comparison(): void {
        $validator = Validators::in([1, 2, 3]);

        // '1' (string) should not match 1 (int) with strict comparison
        $result = $validator('1');

        $this->assertInstanceOf(ValidationError::class, $result);
    }

    public function test_validators_email_rejects_invalid_email(): void {
        $validator = Validators::email();

        $this->assertInstanceOf(ValidationError::class, $validator('not-an-email'));
        $this->assertInstanceOf(ValidationError::class, $validator('missing@tld'));
        $this->assertInstanceOf(ValidationError::class, $validator('@example.com'));
    }

    public function test_validators_email_passes_valid_email(): void {
        $validator = Validators::email();

        $this->assertTrue($validator('user@example.com'));
        $this->assertTrue($validator('user.name+tag@example.co.uk'));
    }

    public function test_validators_email_passes_empty_value(): void {
        // Empty should pass - use required() if field is mandatory
        $validator = Validators::email();

        $this->assertTrue($validator(''));
        $this->assertTrue($validator(null));
    }

    public function test_validators_integrate_with_handler(): void {
        $handler = new Handler($this->object);

        $handler
            ->add_validator('title', Validators::required())
            ->add_validator('title', Validators::min_length(3))
            ->add_validator('count', Validators::min(0))
            ->add_validator('count', Validators::max(100));

        // Valid data
        $result = $handler->create([
            'title' => 'Valid Title',
            'count' => 50,
        ]);
        $this->assertTrue($result->is_success());

        // Invalid data
        $result = $handler->create([
            'title' => 'AB',
            'count' => 150,
        ]);
        $this->assertTrue($result->is_error());
        $this->assertNotEmpty($result->get_field_errors('title'));
        $this->assertNotEmpty($result->get_field_errors('count'));
    }
}
