# Tangible Object

A WordPress tool suite for building data-driven admin interfaces with a clean four-layer architecture.

## Architecture

The suite separates concerns into four distinct layers:

1. **DataSet** - Define field types and coercion rules
2. **EditorLayout** - Compose the editor structure (sections, tabs, fields)
3. **Renderer** - Generate HTML output from the layout
4. **RequestHandler** - Handle CRUD operations with validation

## Quick Start

Here's a complete example creating a simple "Contact" data object:

```php
<?php
use Tangible\DataObject\DataSet;
use Tangible\DataObject\PluralObject;
use Tangible\EditorLayout\Layout;
use Tangible\EditorLayout\Section;
use Tangible\EditorLayout\Sidebar;
use Tangible\RequestHandler\PluralHandler;
use Tangible\RequestHandler\Validators;
use Tangible\Renderer\HtmlRenderer;

// =============================================================================
// LAYER 1: Data Definition
// =============================================================================
// Define WHAT data exists and its types.

$dataset = new DataSet();
$dataset
    ->add_string('name')
    ->add_string('email')
    ->add_string('message')
    ->add_boolean('subscribe');

// =============================================================================
// LAYER 2: Editor Composition
// =============================================================================
// Define HOW the data is organized for editing.

$layout = new Layout($dataset);

$layout->section('Contact Information', function(Section $s) {
    $s->field('name')
      ->placeholder('Your name')
      ->help('Enter your full name');
    $s->field('email')
      ->placeholder('you@example.com');
});

$layout->section('Message', function(Section $s) {
    $s->field('message')
      ->placeholder('Your message...');
    $s->field('subscribe');
});

$layout->sidebar(function(Sidebar $sb) {
    $sb->actions(['save', 'delete']);
});

// =============================================================================
// LAYER 3: UI Presentation
// =============================================================================
// Define HOW it looks in the browser.

$renderer = new HtmlRenderer();

// =============================================================================
// LAYER 4: Request Handling
// =============================================================================
// Define HOW requests are processed with validation and hooks.

// Create the data object (uses Custom Post Type storage by default)
// Note: CPT slugs must be 20 characters or less
$object = new PluralObject('contact');
$object->set_dataset($dataset);
$object->register(['public' => false]);

// Create the handler with validators
$handler = new PluralHandler($object);
$handler
    ->add_validator('name', Validators::required())
    ->add_validator('email', Validators::required())
    ->add_validator('email', Validators::email());

// =============================================================================
// USAGE
// =============================================================================

// Render an empty form for creating new entries
$createFormHtml = $renderer->render_editor($layout, []);

// Handle form submission
$result = $handler->create([
    'name' => $_POST['name'] ?? '',
    'email' => $_POST['email'] ?? '',
    'message' => $_POST['message'] ?? '',
    'subscribe' => $_POST['subscribe'] ?? false,
]);

if ($result->is_error()) {
    // Get validation errors
    $errors = $result->get_errors();
    foreach ($errors as $error) {
        echo $error->get_field() . ': ' . $error->get_message();
    }
} else {
    // Success - get the created entity
    $entity = $result->get_entity();
    $id = $entity->get_id();
}

// Render edit form with existing data
$entity = $handler->read($id)->get_entity();
$editFormHtml = $renderer->render_editor($layout, [
    'name' => $entity->get('name'),
    'email' => $entity->get('email'),
    'message' => $entity->get('message'),
    'subscribe' => $entity->get('subscribe'),
]);

// Update an entity
$handler->update($id, ['name' => 'Updated Name']);

// Delete an entity
$handler->delete($id);

// List all entities
$listResult = $handler->list();
$entities = $listResult->get_entities();
```

## DataSet Field Types

```php
$dataset = new DataSet();
$dataset->add_string('title');      // Text fields
$dataset->add_integer('count');     // Number fields (renders as type="number")
$dataset->add_boolean('is_active'); // Checkbox fields
```

Type coercion happens automatically:
- Strings like `'5'` become integers when the field is `add_integer()`
- Values like `'yes'`, `'true'`, `'1'`, `'on'` become `true` for boolean fields

## EditorLayout Structure

### Sections

```php
$layout->section('Section Label', function(Section $s) {
    $s->field('field_name')
      ->placeholder('Placeholder text')
      ->help('Help text shown below the field')
      ->readonly()           // Make field read-only
      ->width('50%');        // Set field width

    $s->columns(2);          // Display fields in 2 columns
    $s->condition('other_field', true); // Show section only when other_field is true
});
```

### Tabs

```php
use Tangible\EditorLayout\Tabs;
use Tangible\EditorLayout\Tab;

$layout->tabs(function(Tabs $tabs) {
    $tabs->tab('Content', function(Tab $t) {
        $t->field('title');
        $t->field('body');
    });
    $tabs->tab('Settings', function(Tab $t) {
        $t->field('is_published');
    });
});
```

### Nesting

Sections and tabs can be nested arbitrarily:

```php
$layout->section('Main', function(Section $s) {
    $s->field('title');

    // Nested section
    $s->section('Advanced', function(Section $nested) {
        $nested->field('slug');
    });

    // Tabs inside section
    $s->tabs(function(Tabs $tabs) {
        $tabs->tab('Details', function(Tab $t) {
            $t->field('description');
        });
    });
});
```

### Sidebar

```php
$layout->sidebar(function(Sidebar $sb) {
    $sb->field('status')->readonly();
    $sb->actions(['save', 'delete']);
});
```

## Validators

Built-in validators:

```php
use Tangible\RequestHandler\Validators;

$handler
    ->add_validator('field', Validators::required())
    ->add_validator('field', Validators::min_length(3))
    ->add_validator('field', Validators::max_length(100))
    ->add_validator('count', Validators::min(0))
    ->add_validator('count', Validators::max(100))
    ->add_validator('status', Validators::in(['draft', 'published']))
    ->add_validator('email', Validators::email());
```

Custom validators:

```php
$handler->add_validator('slug', function($value) {
    if (preg_match('/[^a-z0-9-]/', $value)) {
        return new \Tangible\RequestHandler\ValidationError(
            'Slug can only contain lowercase letters, numbers, and hyphens'
        );
    }
    return true;
});
```

## Lifecycle Hooks

```php
// Modify data before create
$handler->before_create(function(array $data) {
    $data['created_at'] = time();
    return $data;
});

// React after create
$handler->after_create(function($entity) {
    do_action('my_plugin_contact_created', $entity);
});

// Modify data before update (receives entity and new data)
$handler->before_update(function($entity, array $data) {
    $data['updated_at'] = time();
    return $data;
});

// React after update
$handler->after_update(function($entity) {
    // Send notification, clear cache, etc.
});

// Cancel deletion by returning false
$handler->before_delete(function($entity) {
    if ($entity->get('is_protected')) {
        return false; // Cancels deletion
    }
    return true;
});

// React after delete (receives the deleted ID)
$handler->after_delete(function($id) {
    // Cleanup related data
});
```

## SingularObject

For single-instance data (like plugin settings), use `SingularObject`:

```php
use Tangible\DataObject\SingularObject;
use Tangible\RequestHandler\SingularHandler;

$dataset = new DataSet();
$dataset
    ->add_string('api_key')
    ->add_boolean('debug_mode')
    ->add_integer('cache_ttl');

$settings = new SingularObject('my_plugin_settings');
$settings->set_dataset($dataset);

$handler = new SingularHandler($settings);

// Read current values
$result = $handler->read();
$data = $result->get_data();

// Update values
$handler->update([
    'api_key' => 'new-key',
    'debug_mode' => true,
    'cache_ttl' => 3600,
]);
```

## Rendering Lists

```php
$listResult = $handler->list();
$entities = array_map(function($e) {
    return [
        'name' => $e->get('name'),
        'email' => $e->get('email'),
    ];
}, $listResult->get_entities());

$tableHtml = $renderer->render_list($dataset, $entities);
```

## Requirements

- PHP 8.0+
- WordPress 5.0+
