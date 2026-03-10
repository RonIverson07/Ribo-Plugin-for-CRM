<?php
if (!defined('ABSPATH')) { exit; }
$forms = RIBO_DB::list_forms();
?>

<div class="ribo-page-title">All Forms</div>
<p class="ribo-page-sub"><?php echo esc_html(count($forms)); ?> forms total</p>

<div class="ribo-card">
  <div class="ribo-actions" style="justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:12px;">
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <input type="hidden" name="action" value="ribo_import_form" />
      <?php wp_nonce_field('ribo_import_form'); ?>
      <label class="ribo-btn ribo-btn-secondary" style="cursor:pointer;">
        Import JSON
        <input type="file" name="schema_file" accept="application/json" style="display:none;" onchange="this.form.submit();" />
      </label>
      <span class="desc">Upload a form schema JSON to create a new form.</span>
    </form>

    <a class="ribo-btn ribo-btn-primary" href="<?php echo esc_url(admin_url('admin.php?page=ribo-crm-builder')); ?>" style="text-decoration:none;">Create New Form</a>
  </div>

  <table class="ribo-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Form ID</th>
        <th>Status</th>
        <th>Shortcode</th>
        <th>Updated</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($forms)): ?>
        <tr><td colspan="6">No forms yet.</td></tr>
      <?php else: foreach ($forms as $f): ?>
        <tr>
          <td><strong class="ribo-name-wrap"><?php echo nl2br(esc_html(ribo_wrap_text_lines($f['name'], 20))); ?></strong></td>
          <td><code><?php echo esc_html($f['id']); ?></code></td>
          <td>
            <?php
              $st = strtolower((string)$f['status']);
              $pill = in_array($st, ['published','draft'], true) ? $st : 'draft';
            ?>
            <span class="ribo-pill <?php echo esc_attr($pill); ?>"><?php echo esc_html($st ?: 'draft'); ?></span>
          </td>
          <td><code>[ribo_form id="<?php echo esc_attr($f['id']); ?>"]</code></td>
          <td><?php echo esc_html($f['updated_at']); ?></td>
          <td>
            <a class="ribo-btn ribo-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=ribo-crm-builder&form_id=' . urlencode($f['id']))); ?>" style="text-decoration:none; padding:8px 12px;">Edit</a>
            <a class="ribo-btn ribo-btn-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ribo_duplicate_form&id=' . urlencode($f['id'])), 'ribo_duplicate_form')); ?>" style="text-decoration:none; padding:8px 12px;">Duplicate</a>
            <a class="ribo-btn ribo-btn-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ribo_export_form&id=' . urlencode($f['id'])), 'ribo_export_form')); ?>" style="text-decoration:none; padding:8px 12px;">Export</a>
            <a class="ribo-btn ribo-btn-danger" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ribo_delete_form&id=' . urlencode($f['id'])), 'ribo_delete_form')); ?>" onclick="return confirm('Delete this form?');" style="text-decoration:none; padding:8px 12px;">Delete</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
