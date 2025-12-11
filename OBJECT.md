# Tangible Object

A WordPress tool suite for building data-driven admin interfaces with a clean four-layer architecture.

## Architecture

The suite separates concerns into four distinct layers:

1. **DataSet** - Define field types and coercion rules
2. **EditorLayout** - Compose the editor structure (sections, tabs, fields)
3. **Renderer** - Generate HTML output from the layout
4. **RequestHandler** - Handle CRUD operations with validation

## Complete Example: Contact Form Entries Admin Page

This example shows how to create a WordPress admin page for managing contact form entries, with list, create, and edit views.

### Step 1: Define Your Data Object

Create a file to define and configure your data object. This is typically done once during plugin initialization.

```php
<?php
// my-plugin/includes/contact-object.php

use Tangible\DataObject\DataSet;
use Tangible\DataObject\PluralObject;
use Tangible\EditorLayout\Layout;
use Tangible\EditorLayout\Section;
use Tangible\EditorLayout\Sidebar;
use Tangible\RequestHandler\PluralHandler;
use Tangible\RequestHandler\Validators;
use Tangible\Renderer\HtmlRenderer;

/**
 * Contact data object configuration.
 * Returns all components needed for the admin page.
 */
function get_contact_object() {
    // =========================================================================
    // LAYER 1: Data Definition
    // =========================================================================
    $dataset = new DataSet();
    $dataset
        ->add_string('name')
        ->add_string('email')
        ->add_string('message')
        ->add_boolean('subscribe');

    // =========================================================================
    // LAYER 2: Editor Composition
    // =========================================================================
    $layout = new Layout($dataset);

    $layout->section('Contact Information', function(Section $s) {
        $s->field('name')
          ->placeholder('Full name')
          ->help('The sender\'s full name');
        $s->field('email')
          ->placeholder('email@example.com');
    });

    $layout->section('Message', function(Section $s) {
        $s->field('message');
        $s->field('subscribe');
    });

    $layout->sidebar(function(Sidebar $sb) {
        $sb->actions(['save', 'delete']);
    });

    // =========================================================================
    // LAYER 3: UI Presentation
    // =========================================================================
    $renderer = new HtmlRenderer();

    // =========================================================================
    // LAYER 4: Request Handling
    // =========================================================================
    // Note: CPT slugs must be 20 characters or less
    $object = new PluralObject('contact_entry');
    $object->set_dataset($dataset);
    $object->register([
        'public' => false,
        'label' => 'Contact Entries',
    ]);

    $handler = new PluralHandler($object);
    $handler
        ->add_validator('name', Validators::required())
        ->add_validator('email', Validators::required())
        ->add_validator('email', Validators::email())
        ->before_create(function($data) {
            $data['created_at'] = current_time('mysql');
            return $data;
        });

    return [
        'dataset' => $dataset,
        'layout' => $layout,
        'renderer' => $renderer,
        'handler' => $handler,
    ];
}
```

### Step 2: Create the Admin Page

Register the admin menu and handle the different views.

```php
<?php
// my-plugin/includes/contact-admin-page.php

/**
 * Register the admin menu.
 */
add_action('admin_menu', function() {
    add_menu_page(
        'Contact Entries',
        'Contacts',
        'manage_options',
        'contact-entries',
        'render_contact_admin_page',
        'dashicons-email',
        30
    );
});

/**
 * Main admin page controller.
 * Routes to the appropriate view based on query parameters.
 */
function render_contact_admin_page() {
    $contact = get_contact_object();
    $action = $_GET['action'] ?? 'list';
    $id = isset($_GET['id']) ? (int) $_GET['id'] : null;

    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        handle_contact_form_submission($contact, $action, $id);
        return;
    }

    // Render the appropriate view
    switch ($action) {
        case 'new':
            render_contact_create_view($contact);
            break;
        case 'edit':
            render_contact_edit_view($contact, $id);
            break;
        default:
            render_contact_list_view($contact);
            break;
    }
}
```

### Step 3: Implement the List View

```php
<?php
/**
 * Render the list view showing all contact entries.
 */
function render_contact_list_view($contact) {
    $handler = $contact['handler'];
    $dataset = $contact['dataset'];
    $renderer = $contact['renderer'];

    // Get all entries
    $result = $handler->list();
    $entities = $result->get_entities();

    // Prepare data for rendering
    $rows = array_map(function($entity) {
        return [
            'id' => $entity->get_id(),
            'name' => $entity->get('name'),
            'email' => $entity->get('email'),
            'message' => $entity->get('message'),
            'subscribe' => $entity->get('subscribe'),
        ];
    }, $entities);

    // Page header
    ?>
    <div class="wrap">
        <h1>
            Contact Entries
            <a href="<?php echo admin_url('admin.php?page=contact-entries&action=new'); ?>"
               class="page-title-action">Add New</a>
        </h1>

        <?php if (empty($rows)): ?>
            <p>No contact entries found.</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Subscribed</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?php echo esc_html($row['name']); ?></td>
                            <td><?php echo esc_html($row['email']); ?></td>
                            <td><?php echo $row['subscribe'] ? 'Yes' : 'No'; ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=contact-entries&action=edit&id=' . $row['id']); ?>">
                                    Edit
                                </a>
                                |
                                <a href="<?php echo wp_nonce_url(
                                    admin_url('admin.php?page=contact-entries&action=delete&id=' . $row['id']),
                                    'delete_contact_' . $row['id']
                                ); ?>"
                                   onclick="return confirm('Are you sure?');">
                                    Delete
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
```

### Step 4: Implement the Create View

```php
<?php
/**
 * Render the create view with an empty form.
 */
function render_contact_create_view($contact, $errors = [], $data = []) {
    $layout = $contact['layout'];
    $renderer = $contact['renderer'];

    ?>
    <div class="wrap">
        <h1>Add New Contact Entry</h1>

        <?php if (!empty($errors)): ?>
            <div class="notice notice-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo esc_html($error->get_field() . ': ' . $error->get_message()); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo admin_url('admin.php?page=contact-entries&action=new'); ?>">
            <?php wp_nonce_field('create_contact'); ?>

            <?php
            // Render form fields from layout (without the outer <form> tag)
            // For now, we render the full form and strip tags, or render fields manually
            echo $renderer->render_editor($layout, $data);
            ?>
        </form>

        <p>
            <a href="<?php echo admin_url('admin.php?page=contact-entries'); ?>">
                &larr; Back to list
            </a>
        </p>
    </div>
    <?php
}
```

### Step 5: Implement the Edit View

```php
<?php
/**
 * Render the edit view with populated form.
 */
function render_contact_edit_view($contact, $id, $errors = []) {
    $handler = $contact['handler'];
    $layout = $contact['layout'];
    $renderer = $contact['renderer'];

    // Load the entity
    $result = $handler->read($id);

    if ($result->is_error()) {
        wp_die('Contact entry not found.');
    }

    $entity = $result->get_entity();
    $data = [
        'name' => $entity->get('name'),
        'email' => $entity->get('email'),
        'message' => $entity->get('message'),
        'subscribe' => $entity->get('subscribe'),
    ];

    ?>
    <div class="wrap">
        <h1>Edit Contact Entry</h1>

        <?php if (!empty($errors)): ?>
            <div class="notice notice-error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo esc_html($error->get_field() . ': ' . $error->get_message()); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="<?php echo admin_url('admin.php?page=contact-entries&action=edit&id=' . $id); ?>">
            <?php wp_nonce_field('update_contact_' . $id); ?>

            <?php echo $renderer->render_editor($layout, $data); ?>
        </form>

        <p>
            <a href="<?php echo admin_url('admin.php?page=contact-entries'); ?>">
                &larr; Back to list
            </a>
        </p>
    </div>
    <?php
}
```

### Step 6: Handle Form Submissions

```php
<?php
/**
 * Handle form submissions for create, update, and delete.
 */
function handle_contact_form_submission($contact, $action, $id) {
    $handler = $contact['handler'];

    switch ($action) {
        case 'new':
            // Verify nonce
            if (!wp_verify_nonce($_POST['_wpnonce'], 'create_contact')) {
                wp_die('Security check failed.');
            }

            // Attempt to create
            $result = $handler->create([
                'name' => sanitize_text_field($_POST['name'] ?? ''),
                'email' => sanitize_email($_POST['email'] ?? ''),
                'message' => sanitize_textarea_field($_POST['message'] ?? ''),
                'subscribe' => !empty($_POST['subscribe']),
            ]);

            if ($result->is_error()) {
                // Re-render form with errors
                render_contact_create_view($contact, $result->get_errors(), $_POST);
            } else {
                // Redirect to edit view with success message
                $new_id = $result->get_entity()->get_id();
                wp_redirect(admin_url('admin.php?page=contact-entries&action=edit&id=' . $new_id . '&created=1'));
                exit;
            }
            break;

        case 'edit':
            // Verify nonce
            if (!wp_verify_nonce($_POST['_wpnonce'], 'update_contact_' . $id)) {
                wp_die('Security check failed.');
            }

            // Attempt to update
            $result = $handler->update($id, [
                'name' => sanitize_text_field($_POST['name'] ?? ''),
                'email' => sanitize_email($_POST['email'] ?? ''),
                'message' => sanitize_textarea_field($_POST['message'] ?? ''),
                'subscribe' => !empty($_POST['subscribe']),
            ]);

            if ($result->is_error()) {
                // Re-render form with errors
                render_contact_edit_view($contact, $id, $result->get_errors());
            } else {
                // Redirect back with success message
                wp_redirect(admin_url('admin.php?page=contact-entries&action=edit&id=' . $id . '&updated=1'));
                exit;
            }
            break;

        case 'delete':
            // Verify nonce
            if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_contact_' . $id)) {
                wp_die('Security check failed.');
            }

            // Delete the entry
            $handler->delete($id);

            // Redirect to list
            wp_redirect(admin_url('admin.php?page=contact-entries&deleted=1'));
            exit;
            break;
    }
}
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
    $data['created_at'] = current_time('mysql');
    return $data;
});

// React after create
$handler->after_create(function($entity) {
    do_action('my_plugin_contact_created', $entity);
});

// Modify data before update (receives entity and new data)
$handler->before_update(function($entity, array $data) {
    $data['updated_at'] = current_time('mysql');
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

## SingularObject for Settings Pages

For single-instance data (like plugin settings), use `SingularObject`:

```php
<?php
use Tangible\DataObject\SingularObject;
use Tangible\DataObject\DataSet;
use Tangible\EditorLayout\Layout;
use Tangible\EditorLayout\Section;
use Tangible\EditorLayout\Sidebar;
use Tangible\RequestHandler\SingularHandler;
use Tangible\Renderer\HtmlRenderer;

// Define settings
$dataset = new DataSet();
$dataset
    ->add_string('api_key')
    ->add_boolean('debug_mode')
    ->add_integer('cache_ttl');

// Create layout
$layout = new Layout($dataset);
$layout->section('API Settings', function(Section $s) {
    $s->field('api_key')->help('Your API key from the dashboard');
    $s->field('debug_mode');
    $s->field('cache_ttl')->help('Cache time-to-live in seconds');
});
$layout->sidebar(function(Sidebar $sb) {
    $sb->actions(['save']);
});

// Create object and handler
$settings = new SingularObject('my_plugin_settings');
$settings->set_dataset($dataset);

$handler = new SingularHandler($settings);
$renderer = new HtmlRenderer();

// Settings page callback
function render_settings_page() {
    global $handler, $layout, $renderer;

    // Handle save
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('save_settings');

        $result = $handler->update([
            'api_key' => sanitize_text_field($_POST['api_key'] ?? ''),
            'debug_mode' => !empty($_POST['debug_mode']),
            'cache_ttl' => (int) ($_POST['cache_ttl'] ?? 3600),
        ]);

        if ($result->is_success()) {
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }
    }

    // Get current values
    $result = $handler->read();
    $data = $result->get_data();

    // Render form
    ?>
    <div class="wrap">
        <h1>Plugin Settings</h1>
        <form method="post">
            <?php wp_nonce_field('save_settings'); ?>
            <?php echo $renderer->render_editor($layout, $data); ?>
        </form>
    </div>
    <?php
}
```

## Requirements

- PHP 8.0+
- WordPress 5.0+
