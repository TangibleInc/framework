<?php
namespace Tangible\Framework\Tests;

use Tangible\DataObject\DataSet;
use Tangible\DataObject\SingularObject;
use Tangible\DataObject\PluralObject;
use Tangible\DataObject\Storage\OptionStorage;
use Tangible\DataObject\Storage\CustomPostTypeStorage;

/**
 * Tests for the DataObject feature
 *
 * This tests the three-layer architecture:
 * - Layer 1: Data Definition (DataSet with field types)
 * - Layer 2: Storage adapters (SingularObject for options, PluralObject for CPT)
 *
 * Starting with simple fields only: string, integer, boolean
 */
class DataObject_TestCase extends \WP_UnitTestCase {

    public const CPT_SETTINGS = [
        'label'           => 'My Views',
        'labels'          => [
            'name'                => 'My Views',
            'singular_name'       => 'View',
            'add_new'             => 'Add New View',
            'add_new_item'        => 'Add New View',
            'edit_item'           => 'Edit View',
            'new_item'            => 'New View',
            'view_item'           => 'View',
            'search_items'        => 'Search Views',
            'not_found'           => 'No Views Found',
            'not_found_in_trash'  => 'No views found in Trash',
            'menu_name'           => 'Tangible Algolia',
        ],
        'menu_position'         => 25,
        'menu_icon'             => 'dashicons-admin-generic',
        'supports'              => ['title', 'editor'],
        'register_meta_box_cb'  => 'add_meta_boxes',
    ];
    /**
     * ==========================================================================
     * LAYER 1: DataSet - Field Definition Tests
     * ==========================================================================
     */

    public function test_dataset_can_be_instantiated(): void
    {
        $dataset = new DataSet();
        $this->assertInstanceOf(DataSet::class, $dataset);
    }

    public function test_dataset_can_add_string_field(): void {
        $dataset = new DataSet();
        $dataset->add_string('title');

        $fields = $dataset->get_fields();
        $this->assertArrayHasKey('title', $fields);
        $this->assertEquals(DataSet::TYPE_STRING, $fields['title']['type']);
    }

    public function test_dataset_can_add_integer_field(): void {
        $dataset = new DataSet();
        $dataset->add_integer('count');

        $fields = $dataset->get_fields();
        $this->assertArrayHasKey('count', $fields);
        $this->assertEquals(DataSet::TYPE_INTEGER, $fields['count']['type']);
    }

    public function test_dataset_can_add_boolean_field(): void {
        $dataset = new DataSet();
        $dataset->add_boolean('is_active');

        $fields = $dataset->get_fields();
        $this->assertArrayHasKey('is_active', $fields);
        $this->assertEquals(DataSet::TYPE_BOOLEAN, $fields['is_active']['type']);
    }

    public function test_dataset_can_add_multiple_fields(): void {
        $dataset = new DataSet();
        $dataset->add_string('title');
        $dataset->add_integer('count');
        $dataset->add_boolean('is_active');

        $fields = $dataset->get_fields();
        $this->assertCount(3, $fields);
    }

    public function test_dataset_returns_self_for_fluent_interface(): void {
        $dataset = new DataSet();

        $result = $dataset->add_string('title');
        $this->assertSame($dataset, $result);

        $result = $dataset->add_integer('count');
        $this->assertSame($dataset, $result);

        $result = $dataset->add_boolean('is_active');
        $this->assertSame($dataset, $result);
    }

    /**
     * ==========================================================================
     * LAYER 2: SingularObject - Option Storage Tests
     * ==========================================================================
     */

    public function test_singular_object_can_be_instantiated(): void {
        $object = new SingularObject('my_settings');
        $this->assertInstanceOf(SingularObject::class, $object);
    }

    public function test_singular_object_can_set_dataset(): void {
        $dataset = new DataSet();
        $dataset->add_string('title');

        $object = new SingularObject('my_settings');
        $result = $object->set_dataset($dataset);

        $this->assertSame($object, $result); // fluent interface
        $this->assertSame($dataset, $object->get_dataset());
    }

    public function test_singular_object_uses_option_storage_by_default(): void {
        $object = new SingularObject('my_settings');
        $storage = $object->get_storage();

        $this->assertInstanceOf(OptionStorage::class, $storage);
    }

    public function test_singular_object_can_save_and_retrieve_string_value(): void {
        $dataset = new DataSet();
        $dataset->add_string('title');

        $object = new SingularObject('test_settings');
        $object->set_dataset($dataset);

        $object->set('title', 'Hello World');
        $this->assertEquals('Hello World', $object->get('title'));
    }

    public function test_singular_object_can_save_and_retrieve_integer_value(): void {
        $dataset = new DataSet();
        $dataset->add_integer('count');

        $object = new SingularObject('test_settings');
        $object->set_dataset($dataset);

        $object->set('count', 42);
        $this->assertEquals(42, $object->get('count'));
    }

    public function test_singular_object_can_save_and_retrieve_boolean_value(): void {
        $dataset = new DataSet();
        $dataset->add_boolean('is_active');

        $object = new SingularObject('test_settings');
        $object->set_dataset($dataset);

        $object->set('is_active', true);
        $this->assertTrue($object->get('is_active'));

        $object->set('is_active', false);
        $this->assertFalse($object->get('is_active'));
    }

    public function test_singular_object_persists_to_wordpress_options(): void {
        $dataset = new DataSet();
        $dataset->add_string('title');
        $dataset->add_integer('count');

        $object = new SingularObject('persist_test_settings');
        $object->set_dataset($dataset);

        $object->set('title', 'Persisted Title');
        $object->set('count', 100);
        $object->save();

        // Verify it's actually in WordPress options
        $saved = get_option('persist_test_settings');
        $this->assertIsArray($saved);
        $this->assertEquals('Persisted Title', $saved['title']);
        $this->assertEquals(100, $saved['count']);
    }

    public function test_singular_object_loads_from_wordpress_options(): void {
        // Pre-populate option
        update_option('preload_test_settings', [
            'title' => 'Preloaded Title',
            'count' => 50,
        ]);

        $dataset = new DataSet();
        $dataset->add_string('title');
        $dataset->add_integer('count');

        $object = new SingularObject('preload_test_settings');
        $object->set_dataset($dataset);
        $object->load();

        $this->assertEquals('Preloaded Title', $object->get('title'));
        $this->assertEquals(50, $object->get('count'));
    }

    public function test_singular_object_returns_null_for_undefined_field(): void {
        $dataset = new DataSet();
        $dataset->add_string('title');

        $object = new SingularObject('test_settings');
        $object->set_dataset($dataset);

        $this->assertNull($object->get('nonexistent'));
    }

    /**
     * ==========================================================================
     * LAYER 2: PluralObject - Custom Post Type Storage Tests
     * ==========================================================================
     */

    public function test_plural_object_can_be_instantiated(): void {
        $object = new PluralObject('book');
        $this->assertInstanceOf(PluralObject::class, $object);
    }

    public function test_plural_object_can_set_dataset(): void {
        $dataset = new DataSet();
        $dataset->add_string('title');

        $object = new PluralObject('book');
        $result = $object->set_dataset($dataset);

        $this->assertSame($object, $result); // fluent interface
        $this->assertSame($dataset, $object->get_dataset());
    }

    public function test_plural_object_uses_cpt_storage_by_default(): void {
        $object = new PluralObject('book');
        $storage = $object->get_storage();

        $this->assertInstanceOf(CustomPostTypeStorage::class, $storage);
    }

    public function test_plural_object_can_create_entity(): void {
        $dataset = new DataSet();
        $dataset->add_string('title');
        $dataset->add_string('author');

        $object = new PluralObject('book');
        $object->set_dataset($dataset);
        $object->register( self::CPT_SETTINGS );

        $entity = $object->create([
            'title' => 'The Great Gatsby',
            'author' => 'F. Scott Fitzgerald',
        ]);

        $this->assertIsInt($entity->get_id());
        $this->assertEquals('The Great Gatsby', $entity->get('title'));
        $this->assertEquals('F. Scott Fitzgerald', $entity->get('author'));
    }

    public function test_plural_object_can_find_entity_by_id(): void {
        $dataset = new DataSet();
        $dataset->add_string('title');

        $object = new PluralObject('book');
        $object->set_dataset($dataset);
        $object->register( self::CPT_SETTINGS );

        $created = $object->create(['title' => 'Test Book']);
        $id = $created->get_id();

        $found = $object->find($id);
        $this->assertEquals('Test Book', $found->get('title'));
    }

    public function test_plural_object_can_update_entity(): void {
        $dataset = new DataSet();
        $dataset->add_string('title');
        $dataset->add_integer('pages');

        $object = new PluralObject('book');
        $object->set_dataset($dataset);
        $object->register( self::CPT_SETTINGS );

        $entity = $object->create([
            'title' => 'Original Title',
            'pages' => 100,
        ]);

        $entity->set('title', 'Updated Title');
        $entity->set('pages', 200);
        $object->save( $entity );

        // Reload and verify
        $reloaded = $object->find($entity->get_id());
        $this->assertEquals('Updated Title', $reloaded->get('title'));
        $this->assertEquals(200, $reloaded->get('pages'));
    }

    public function test_plural_object_can_delete_entity(): void {
        $dataset = new DataSet();
        $dataset->add_string('title');

        $object = new PluralObject('book');
        $object->set_dataset($dataset);
        $object->register( self::CPT_SETTINGS );

        $entity = $object->create(['title' => 'To Be Deleted']);
        $id = $entity->get_id();

        $object->delete($entity);

        $found = $object->find($id);
        $this->assertNull($found);
    }

    public function test_plural_object_can_list_all_entities(): void {
        $dataset = new DataSet();
        $dataset->add_string('title');

        $object = new PluralObject('book');
        $object->set_dataset($dataset);
        $object->register( self::CPT_SETTINGS );

        $object->create(['title' => 'Book One']);
        $object->create(['title' => 'Book Two']);
        $object->create(['title' => 'Book Three']);

        $all = $object->all();
        $this->assertCount(3, $all);
    }

    public function test_plural_object_stores_fields_as_json_meta(): void {
        $dataset = new DataSet();
        $dataset->add_string('author');
        $dataset->add_integer('pages');
        $dataset->add_boolean('published');

        $object = new PluralObject('book');
        $object->set_dataset($dataset);
        $object->register( self::CPT_SETTINGS );

        $entity = $object->create([
            'author' => 'Test Author',
            'pages' => 300,
            'published' => true,
        ]);

        $post_id = $entity->get_id();

        // Verify stored as JSON in post meta
        $json = get_post_meta($post_id, '_tangible_data', true);
        $this->assertNotEmpty($json);

        $data = json_decode($json, true);
        $this->assertEquals('Test Author', $data['author']);
        $this->assertEquals(300, $data['pages']);
        $this->assertEquals(true, $data['published']);
    }

    public function test_plural_object_registers_custom_post_type(): void {
        $object = new PluralObject('test_book');
        $object->register( self::CPT_SETTINGS );

        $this->assertTrue(post_type_exists('test_book'));
    }

    public function test_plural_object_find_returns_null_for_nonexistent_id(): void {
        $dataset = new DataSet();
        $dataset->add_string('title');

        $object = new PluralObject('book');
        $object->set_dataset($dataset);
        $object->register( self::CPT_SETTINGS );

        $found = $object->find(999999);
        $this->assertNull($found);
    }

    /**
     * ==========================================================================
     * Type Coercion / Validation Tests
     * ==========================================================================
     */

    public function test_integer_field_coerces_string_to_integer(): void {
        $dataset = new DataSet();
        $dataset->add_integer('count');

        $object = new SingularObject('coercion_test');
        $object->set_dataset($dataset);

        $object->set('count', '42');
        $this->assertSame(42, $object->get('count'));
    }

    public function test_boolean_field_coerces_truthy_values(): void {
        $dataset = new DataSet();
        $dataset->add_boolean('flag');

        $object = new SingularObject('bool_coercion_test');
        $object->set_dataset($dataset);

        $object->set('flag', '1');
        $this->assertSame(true, $object->get('flag'));

        $object->set('flag', '0');
        $this->assertSame(false, $object->get('flag'));

        $object->set('flag', 'yes');
        $this->assertSame(true, $object->get('flag'));
    }

    public function test_string_field_coerces_other_types_to_string(): void {
        $dataset = new DataSet();
        $dataset->add_string('value');

        $object = new SingularObject('string_coercion_test');
        $object->set_dataset($dataset);

        $object->set('value', 123);
        $this->assertSame('123', $object->get('value'));
    }
}
