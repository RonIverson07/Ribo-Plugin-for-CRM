<?php
if (!defined('ABSPATH')) { exit; }

$filters = [
  'level' => isset($_GET['level']) ? sanitize_text_field(wp_unslash($_GET['level'])) : '',
  'event_type' => isset($_GET['event']) ? sanitize_text_field(wp_unslash($_GET['event'])) : '',
  'form_id' => isset($_GET['form_id']) ? sanitize_text_field(wp_unslash($_GET['form_id'])) : '',
  'date_from' => isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : '',
  'date_to' => isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : '',
];

$logs = RIBO_Logger::list_logs(200, $filters);
?>

<div class="ribo-page-title">Logs</div>
<p class="ribo-page-sub"><?php echo esc_html(count($logs)); ?> entries</p>

<div class="ribo-card">
  <div class="ribo-actions" style="justify-content:space-between; gap:12px; margin-bottom:12px; flex-wrap:wrap;">
    <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
      <input type="hidden" name="page" value="ribo-crm-logs" />
      <div>
        <label class="ribo-label">Level</label>
        <select class="ribo-input" name="level" style="min-width:140px;">
          <option value="">All</option>
          <?php foreach (['info','warn','error'] as $lvl): ?>
            <option value="<?php echo esc_attr($lvl); ?>" <?php selected($filters['level'], $lvl); ?>><?php echo esc_html($lvl); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="ribo-label">Event</label>
        <input class="ribo-input" type="text" name="event" value="<?php echo esc_attr($filters['event_type']); ?>" placeholder="e.g. retry_failed" />
      </div>
      <div>
        <label class="ribo-label">Form ID</label>
        <input class="ribo-input" type="text" name="form_id" value="<?php echo esc_attr($filters['form_id']); ?>" placeholder="e.g. form_abc" />
      </div>
      <div>
        <label class="ribo-label">From</label>
        <input class="ribo-input" type="date" name="date_from" value="<?php echo esc_attr($filters['date_from']); ?>" />
      </div>
      <div>
        <label class="ribo-label">To</label>
        <input class="ribo-input" type="date" name="date_to" value="<?php echo esc_attr($filters['date_to']); ?>" />
      </div>
      <button class="ribo-btn ribo-btn-secondary" type="submit">Filter</button>
      <a class="ribo-btn ribo-btn-secondary" href="<?php echo esc_url(admin_url('admin.php?page=ribo-crm-logs')); ?>" style="text-decoration:none;">Reset</a>
    </form>

    <div style="display:flex; gap:10px;">
      <a class="ribo-btn ribo-btn-secondary" href="<?php echo esc_url(wp_nonce_url(add_query_arg(array_filter([
        'action' => 'ribo_export_logs_csv',
        'level' => $filters['level'],
        'event' => $filters['event_type'],
        'form_id' => $filters['form_id'],
        'date_from' => $filters['date_from'],
        'date_to' => $filters['date_to'],
      ]), admin_url('admin-post.php')), 'ribo_export_logs_csv')); ?>" style="text-decoration:none;">Export CSV</a>

      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="ribo_clear_logs">
        <?php wp_nonce_field('ribo_clear_logs'); ?>
        <button class="ribo-btn ribo-btn-secondary" type="submit" onclick="return confirm('Clear logs?');">Clear Logs</button>
      </form>
    </div>
  </div>

  <table class="ribo-table">
    <thead>
      <tr>
        <th>Time</th>
        <th>Level</th>
        <th>Event</th>
        <th>Form</th>
        <th>Submission</th>
        <th>Message</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($logs)): ?>
        <tr><td colspan="6">No logs yet.</td></tr>
      <?php else: foreach ($logs as $l): ?>
        <tr>
          <td><?php echo esc_html($l['created_at']); ?></td>
          <td><span class="ribo-pill <?php echo esc_attr(in_array($l['level'], ['error','warn'], true) ? ($l['level']==='error'?'failed':'pending') : 'draft'); ?>"><?php echo esc_html($l['level']); ?></span></td>
          <td><?php echo esc_html($l['event_type']); ?></td>
          <td><?php echo $l['form_id'] ? '<code>'.esc_html($l['form_id']).'</code>' : '—'; ?></td>
          <td><?php echo $l['submission_id'] ? '<code>'.esc_html($l['submission_id']).'</code>' : '—'; ?></td>
          <td><?php echo esc_html($l['message']); ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>
