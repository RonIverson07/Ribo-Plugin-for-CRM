<?php
if (!defined('ABSPATH')) { exit; }
$rows = RIBO_DB::list_pending(200);
?>

<div class="ribo-page-title">Pending Sync</div>
<p class="ribo-page-sub">Monitor submissions waiting to be synced with the CRM.</p>

<div class="ribo-card">
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="ribo_bulk_pending" />
    <?php wp_nonce_field('ribo_pending_actions'); ?>

    <div class="ribo-actions" style="justify-content:space-between; margin-bottom:12px; flex-wrap:wrap; gap:12px;">
      <div class="desc">Tip: Select rows and run bulk actions (resend or delete).</div>
      <div style="display:flex; gap:10px;">
        <select class="ribo-input" name="bulk_action" style="min-width:180px;">
          <option value="">Bulk actions</option>
          <option value="resend">Resend</option>
          <option value="delete">Delete</option>
        </select>
        <button class="ribo-btn ribo-btn-secondary" type="submit" onclick="return confirm('Apply bulk action to selected rows?');">Apply</button>
      </div>
    </div>

  <table class="ribo-table">
    <thead>
      <tr>
        <th style="width:40px;"><input type="checkbox" onclick="document.querySelectorAll('.ribo-pending-checkbox').forEach(cb => cb.checked = this.checked);" /></th>
        <th>Date</th>
        <th>Form</th>
        <th>Submission ID</th>
        <th>Attempts</th>
        <th>Next Retry</th>
        <th>Status</th>
        <th>Last Error</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="9">No queued submissions.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><input class="ribo-pending-checkbox" type="checkbox" name="ids[]" value="<?php echo esc_attr((int)$r['id']); ?>" /></td>
          <td><?php echo esc_html($r['created_at']); ?></td>
          <td><code><?php echo esc_html($r['form_id']); ?></code></td>
          <td><code><?php echo esc_html($r['submission_id']); ?></code></td>
          <td><?php echo esc_html($r['attempts']); ?></td>
          <td><?php echo esc_html($r['next_retry_at'] ?: '—'); ?></td>
          <td>
            <?php $st = strtolower((string)$r['status']); ?>
            <span class="ribo-pill <?php echo esc_attr($st === 'failed' ? 'failed' : ($st === 'pending' ? 'pending' : 'draft')); ?>"><?php echo esc_html($st ?: 'pending'); ?></span>
          </td>
          <td><code><?php echo esc_html(mb_strimwidth((string)$r['last_error'], 0, 120, '…')); ?></code></td>
          <td>
            <a class="ribo-btn ribo-btn-secondary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ribo_resend_pending&id=' . (int)$r['id']), 'ribo_pending_actions')); ?>" style="text-decoration:none; padding:8px 12px;">Resend</a>
            <a class="ribo-btn ribo-btn-danger" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ribo_delete_pending&id=' . (int)$r['id']), 'ribo_pending_actions')); ?>" onclick="return confirm('Delete this queued record?');" style="text-decoration:none; padding:8px 12px;">Delete</a>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </form>
</div>
