<?php
if (!defined('ABSPATH')) { exit; }

$forms = RIBO_DB::list_forms();
$selected = isset($_GET['form_id']) ? sanitize_text_field(wp_unslash($_GET['form_id'])) : '';
if (!$selected && !empty($forms)) {
  $selected = $forms[0]['id'];
}

$form = $selected ? RIBO_DB::get_form($selected) : null;
$schema = $form ? json_decode($form['schema_json'], true) : null;
$fields = (is_array($schema) && !empty($schema['fields']) && is_array($schema['fields'])) ? $schema['fields'] : [];
$mapping = (is_array($schema) && !empty($schema['mapping']) && is_array($schema['mapping'])) ? $schema['mapping'] : [];

$suggestions = [
  'crm.email',
  'crm.full_name',
  'crm.first_name',
  'crm.last_name',
  'crm.phone',
  'crm.company',
  'crm.website',
  'crm.message',
];
?>


<div class="ribo-page-title">Field Mapping</div>
<p class="ribo-page-sub">Configure how your form fields map to CRM fields (used when sending submissions).</p>

<div class="ribo-card" style="max-width: 1100px;">
  <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex; gap:12px; align-items:end; flex-wrap:wrap;">
    <input type="hidden" name="page" value="ribo-crm-mapping" />
    <div style="min-width:320px;">
      <label class="ribo-label">Select Form</label>
      <select class="ribo-input" name="form_id">
        <?php foreach ($forms as $f): ?>
          <option value="<?php echo esc_attr($f['id']); ?>" <?php selected($selected, $f['id']); ?>><?php echo esc_html($f['name'] . ' (' . $f['id'] . ')'); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="ribo-btn ribo-btn-secondary" type="submit">Load</button>
  </form>

  <div style="height:14px;"></div>

  <?php if (!$form): ?>
    <p class="desc">No forms found yet. Create a form first.</p>
  <?php else: ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="ribo_save_mapping" />
      <input type="hidden" name="form_id" value="<?php echo esc_attr($selected); ?>" />
      <?php wp_nonce_field('ribo_save_mapping'); ?>

      <div class="ribo-card" style="background: #f8fafc; border:1px solid var(--ribo-border); box-shadow:none;">
        <p class="desc" style="margin:0;">Tip: This mapping is saved inside the form schema under <code>mapping</code>. Each submission will use it to build the CRM payload.</p>
      </div>

      <div style="height:14px;"></div>

      <table class="ribo-table">
        <thead>
          <tr>
            <th style="width:260px;">Field</th>
            <th style="width:140px;">Type</th>
            <th>CRM Mapping Key</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($fields)): ?>
            <tr><td colspan="3">This form has no fields.</td></tr>
          <?php else: foreach ($fields as $fld):
            $fid = isset($fld['id']) ? (string)$fld['id'] : '';
            $lbl = isset($fld['label']) ? (string)$fld['label'] : $fid;
            $typ = isset($fld['type']) ? (string)$fld['type'] : '';
            $val = isset($mapping[$fid]) ? (string)$mapping[$fid] : '';
          ?>
            <tr>
              <td>
                <div style="font-weight:700; color:var(--ribo-text);">
                  <?php echo esc_html($lbl); ?>
                </div>
                <div style="color:var(--ribo-muted); font-size:12px;"><code><?php echo esc_html($fid); ?></code></div>
              </td>
              <td><?php echo esc_html($typ); ?></td>
              <td>
                <input class="ribo-input" name="mapping[<?php echo esc_attr($fid); ?>]" value="<?php echo esc_attr($val); ?>" placeholder="e.g. crm.email" list="ribo-crm-keys" />
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>

      <datalist id="ribo-crm-keys">
        <?php foreach ($suggestions as $s): ?>
          <option value="<?php echo esc_attr($s); ?>"></option>
        <?php endforeach; ?>
      </datalist>

      <div class="ribo-actions" style="justify-content:flex-end; margin-top:14px;">
        <button class="ribo-btn ribo-btn-primary" type="submit">Save Mapping</button>
      </div>
    </form>
  <?php endif; ?>
</div>
