<?php
if (!defined('ABSPATH')) { exit; }

$form_id = isset($_GET['form_id']) ? sanitize_text_field($_GET['form_id']) : '';
$form = $form_id ? RIBO_DB::get_form($form_id) : null;

$default_schema = [
  'id' => $form_id ?: 'form_' . substr(md5(uniqid('', true)), 0, 10),
  'name' => $form ? $form['name'] : 'Website Contact Form',
  'status' => $form ? $form['status'] : 'draft',
  'version' => $form ? (int)$form['version'] : RIBO_WP_INBOUND_SCHEMA_VERSION,
  'fields' => [
    ['id'=>'fld_name','type'=>'text','label'=>'Full Name','required'=>true,'settings'=>['placeholder'=>'Your name','payload_key'=>'name']],
    ['id'=>'fld_email','type'=>'email','label'=>'Email','required'=>false,'settings'=>['placeholder'=>'you@company.com','payload_key'=>'email']],
    ['id'=>'fld_phone','type'=>'phone','label'=>'Phone number','required'=>false,'settings'=>['placeholder'=>'+1 (555) 000-0000','payload_key'=>'phone']],
    ['id'=>'fld_notes','type'=>'textarea','label'=>'Notes','required'=>false,'settings'=>['placeholder'=>'Tell us a little about the project...','payload_key'=>'notes']],
  ],
  'ui' => ['submit_text'=>'Send'],
  'mapping' => [
    'fld_name' => 'name',
    'fld_email' => 'email',
    'fld_phone' => 'phone',
    'fld_notes' => 'notes'
  ]
];

$schema_json = $form ? $form['schema_json'] : wp_json_encode($default_schema, JSON_PRETTY_PRINT);
$name = $form ? $form['name'] : $default_schema['name'];
$status = $form ? $form['status'] : $default_schema['status'];
$id = $form ? $form['id'] : $default_schema['id'];

$schema = json_decode($schema_json, true);
if (!is_array($schema)) {
  $schema = $default_schema;
}
$schema = RIBO_DB::normalize_schema($schema, [
  'id' => $id,
  'name' => $name,
  'status' => $status,
]);
$schema_json = wp_json_encode($schema, JSON_PRETTY_PRINT);

// Make schema available to builder.js
wp_add_inline_script('ribo-builder-js', 'window.RIBO_BUILDER_SCHEMA = ' . wp_json_encode($schema) . ';', 'before');
?>

<?php if (isset($_GET['name_error']) && $_GET['name_error'] === 'max_length'): ?>
<script>
window.addEventListener('load', function(){
  window.alert('Form creation failed. Form name must be 40 characters or less.');
});
</script>
<?php endif; ?>


<div class="ribo-page-title">Form Builder</div>
<p class="ribo-page-sub">Build and customize your CRM inbound forms.</p>

<div class="ribo-builder-page">

  <?php if (isset($_GET['saved'])): ?>
    <div class="notice notice-success is-dismissible"><p>Saved.</p></div>
  <?php endif; ?>

  <?php if (isset($_GET['name_error']) && $_GET['name_error'] === 'max_length'): ?>
    <div class="notice notice-error is-dismissible"><p>Form name must be 40 characters or less.</p></div>
  <?php endif; ?>

  <div class="ribo-topbar">
    <div class="left">
      <span class="ribo-pill"><strong id="riboFormTitle" class="ribo-form-title"><?php echo esc_html($name ?: 'Untitled Form'); ?></strong></span>
      <span class="ribo-pill ribo-muted">Form ID: <code><?php echo esc_html($id); ?></code></span>
      <span class="ribo-pill ribo-muted">Tip: Double‑click a field to add • Drag to reorder</span>
    </div>
    <div class="right">
      <button type="button" class="button" id="riboTogglePreview">Preview Mode</button>
      <button type="button" class="button" id="riboToggleJson">Toggle Schema JSON</button>
    </div>
  </div>

  <form id="riboBuilderForm" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="ribo_save_form">
    <?php wp_nonce_field('ribo_save_form'); ?>

    <input type="hidden" id="riboFormId" name="id" value="<?php echo esc_attr($id); ?>">

    <!-- The server handler expects schema_json; we keep it hidden and let JS populate it -->
    <textarea id="riboSchemaJson" name="schema_json" style="display:none;"><?php echo esc_textarea($schema_json); ?></textarea>

    <div class="ribo-builder">
      <!-- Left: palette -->
      <div class="ribo-card">
        <h2>Add Fields</h2>
        <div class="ribo-card-body">

          <div class="ribo-palette-group" id="riboPalettePayload">
            <div class="ribo-palette-group-title">Payload Fields</div>

            <div class="ribo-field-item" data-preset="name" data-type="text"><span class="ribo-field-badge">A</span><span>Full Name</span></div>
            <div class="ribo-field-item" data-preset="email" data-type="email"><span class="ribo-field-badge">@</span><span>Email</span></div>
            <div class="ribo-field-item" data-preset="phone" data-type="phone"><span class="ribo-field-badge">☎</span><span>Phone</span></div>
            <div class="ribo-field-item" data-preset="company" data-type="text"><span class="ribo-field-badge">A</span><span>Company</span></div>
            <div class="ribo-field-item" data-preset="position" data-type="text"><span class="ribo-field-badge">A</span><span>Position</span></div>
            <div class="ribo-field-item" data-preset="website" data-type="text"><span class="ribo-field-badge">A</span><span>Website</span></div>
            <div class="ribo-field-item" data-preset="address" data-type="textarea"><span class="ribo-field-badge">¶</span><span>Address</span></div>
            <div class="ribo-field-item" data-preset="value" data-type="number"><span class="ribo-field-badge">#</span><span>Value</span></div>
            <div class="ribo-field-item" data-preset="notes" data-type="textarea"><span class="ribo-field-badge">¶</span><span>Notes</span></div>
          </div>

          <div class="ribo-palette-group" id="riboPaletteAdvanced">
            <div class="ribo-palette-group-title">Advanced</div>
            <div class="ribo-field-item" data-type="hidden"><span class="ribo-field-badge">H</span><span>Hidden</span></div>
            <div class="ribo-field-item" data-type="file"><span class="ribo-field-badge">⭳</span><span>File (URL)</span></div>
            <div class="ribo-field-item" data-type="recaptcha"><span class="ribo-field-badge">✓</span><span>reCAPTCHA (placeholder)</span></div>
          </div>

          <p class="description ribo-muted">Drag payload fields onto the canvas. Preset fields submit to fixed payload keys such as payload.name, payload.email, and payload.notes.</p>
        </div>
      </div>

      <!-- Middle: canvas -->
      <div class="ribo-card">
        <h2>Form Preview</h2>
        <div class="ribo-card-body ribo-canvas">
          <div id="riboCanvasEmpty" class="ribo-canvas-empty">
            <strong>Drop fields here</strong><br>
            Drag from the left panel to build your form.
          </div>
          <ul id="riboCanvasList" class="ribo-canvas-list"></ul>
          <div id="riboPreviewHost" class="ribo-preview-host" style="display:none;"></div>
        </div>
      </div>

      <!-- Right: options -->
      <div class="ribo-card ribo-options">
        <h2>Field Options</h2>
        <div class="ribo-card-body">

          <div class="ribo-form-row">
            <label for="riboFormName">Form name</label>
            <input id="riboFormName" type="text" value="<?php echo esc_attr($name); ?>" name="name" maxlength="40">
          </div>

          <div class="ribo-inline">
            <div class="ribo-form-row">
              <label for="riboFormStatus">Status</label>
              <select id="riboFormStatus" name="status">
                <option value="draft" <?php selected($status,'draft'); ?>>Draft</option>
                <option value="published" <?php selected($status,'published'); ?>>Published</option>
              </select>
            </div>
            <div class="ribo-form-row">
              <label for="riboSubmitText">Submit button</label>
              <input id="riboSubmitText" type="text" value="">
            </div>
          </div>

          <div id="riboShortcodeHint" style="display:none;" class="ribo-form-row">
            <label>Shortcode</label>
            <code>[ribo_form id="<?php echo esc_attr($id); ?>"]</code>
            <p class="description ribo-muted">Paste into any page/post to render the form.</p>
          </div>

          <hr>

          <div id="riboOptionsBody"></div>

          <hr>

          <?php submit_button('Save Form'); ?>

          <div id="riboRawJsonWrap" style="display:none; margin-top:12px;">
            <label style="font-size:12px;font-weight:600;">Schema JSON (auto-generated)</label>
            <pre id="riboSchemaPreview" style="white-space:pre-wrap; max-height:260px; overflow:auto; background:#f6f7f7; padding:10px; border-radius:10px; border:1px solid #dcdcde;"></pre>
          </div>

        </div>
      </div>
    </div>

  </form>
</div>
