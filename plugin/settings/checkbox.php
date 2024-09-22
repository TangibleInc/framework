<?php
namespace tangible\framework;
/**
 * Render checkbox as a plugin setting field
 * 
 * This workaround is needed for plugin settings page using the classic
 * form submit in WordPress admin, because an unchecked field value doesn't
 * get passed to the POST request. The solution is to toggle a hidden input
 * field to ensure it gets saved.
 * 
 * @see https://stackoverflow.com/questions/1809494/post-unchecked-html-checkboxes#answer-25764926
 * 
 * This will become unnecessary when using the new AJAX/API/Form module, because
 * they use a smart JavaScript function to gather form fields data.
 */
function render_setting_field_checkbox($config, $type='checkbox') {

  foreach ([
    'name',
    'value',
    'label',
    'description'
  ] as $key) {
    $$key = $config[$key];
  }

  $checked = $value==='true';

  if ($type === 'checkbox'): ?>
      <label>
          <input type="hidden" name="<?php echo $name; ?>" value="<?php echo $checked ? 'true' : 'false'; ?>" autocomplete="off">
          <input type="checkbox" value="true" autocomplete="off"
              onclick="this.previousSibling.value=this.previousSibling.value==='true'?'false':'true'" 
              <?php echo $checked ? 'checked' : ''; ?> />
          <?php echo esc_html($label); ?>
      </label>
  <?php else: ?>
      <div class="tangible-card">
          <label class="tangible-feature-switch">
              <input type="hidden" name="<?php echo $name; ?>" value="<?php echo $checked ? 'true' : 'false'; ?>" autocomplete="off" class="feature-hidden-input">
              <input type="checkbox" value="true" autocomplete="off"
                  onclick="this.previousElementSibling.value = this.checked ? 'true' : 'false'" 
                  <?php echo $checked ? 'checked' : ''; ?> />
              <span class="tangible-feature-switch-label"><?php echo esc_html($label); ?></span>
              <span class="tangible-slider tangible-slider-round"></span>
          </label>
          <?php if (!empty($description)): ?>
              <div class="feature-description">
                  <?php
                  if (is_callable($description)) {
                      $description(
                          tangible\framework\get_plugin_feature_settings($plugin, $feature),
                          tangible\framework\get_plugin_feature_settings_key($plugin, $feature),
                          $is_enabled
                      );
                  } else {
                      echo $description;
                  }
                  ?>
              </div>
          <?php endif; ?>
      </div>
    <?php endif; 
};
