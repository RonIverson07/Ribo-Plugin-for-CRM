<?php
if (!defined('ABSPATH')) { exit; }

$api_key  = (string)get_option('ribo_crm_api_key', '');

$retry = (int)get_option('ribo_retry_max_attempts', 3);
$cron  = (int)get_option('ribo_cron_interval_minutes', 5);

$masked = $api_key ? str_repeat('•', max(0, strlen($api_key) - 4)) . substr($api_key, -4) : '';

$last_time = (string)get_option('ribo_last_test_time', '');
$last_code = (int)get_option('ribo_last_test_code', 0);
$last_err  = (string)get_option('ribo_last_test_error', '');

$is_ok = ($last_code >= 200 && $last_code < 300);
$connected_flag = isset($_GET['connected']) ? sanitize_text_field($_GET['connected']) : '';
?>


<div class="ribo-page-title">CRM Connection</div>
<p class="ribo-page-sub">Connect this WordPress site to your RIBO CRM so leads can sync automatically.</p>

<?php if ($connected_flag === '1'): ?>
  <div class="notice notice-success"><p><strong>Connected!</strong> Your settings were saved and the connection was verified.</p></div>
<?php elseif ($connected_flag === '0'): ?>
  <div class="notice notice-error"><p><strong>Not connected.</strong> We saved your settings but could not verify the connection. Check your Connection Key, then try again.</p></div>
<?php endif; ?>

<div class="ribo-grid two">
  <div class="ribo-card">
    <h2>Quick Connect</h2>
    <p class="desc">A simple, non-technical setup. Paste your Connection Key, then click <strong>Connect &amp; Verify</strong>.</p>

    <div class="ribo-helpbox" style="margin-bottom:14px;">
      <div class="ribo-step"><span class="ribo-step-num">1</span><div>Copy your <strong>Connection Key</strong> from RIBO CRM (Settings → Integrations / API Keys) and paste it here.</div></div>
    </div>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="ribo_quick_connect">
      <?php wp_nonce_field('ribo_quick_connect'); ?>

      <div class="ribo-field">
        <label for="ribo_crm_api_key">Connection Key</label>
        <div class="ribo-input-with-btn">
          <input type="password" name="ribo_crm_api_key" id="ribo_crm_api_key" value="" placeholder="Paste your Connection Key" autocomplete="new-password" />
          <button type="button" class="ribo-btn ribo-btn-secondary ribo-inline-btn" id="ribo-toggle-key">Show</button>
        </div>
        <?php if ($masked): ?>
          <p class="desc" style="margin-top:8px;">Saved key: <code><?php echo esc_html($masked); ?></code></p>
        <?php else: ?>
          <p class="desc" style="margin-top:8px;">No key saved yet.</p>
        <?php endif; ?>
        <p class="desc" style="margin-top:8px;">Tip: If you already saved a key and you don’t want to change it, leave this field blank.</p>
      </div>

      <details class="ribo-advanced">
        <summary>Advanced settings</summary>
        <div class="ribo-row" style="margin-top:12px;">
          <div class="ribo-field">
            <label>Retry Attempts</label>
            <input type="number" min="1" max="10" name="ribo_retry_max_attempts" value="<?php echo esc_attr($retry); ?>" />
          </div>
          <div class="ribo-field">
            <label>Cron Interval (min)</label>
            <input type="number" min="1" max="60" name="ribo_cron_interval_minutes" value="<?php echo esc_attr($cron); ?>" />
          </div>
        </div>
        <p class="desc" style="margin:0;">Usually you can keep the defaults. Retries help when your CRM is temporarily offline.</p>
      </details>

      <div class="ribo-actions" style="margin-top:14px;">
        <button class="ribo-btn ribo-btn-primary" type="submit">Connect &amp; Verify</button>
      </div>
    </form>
  </div>

  <div class="ribo-card">
    <h2>Connection Status</h2>
    <p class="desc">See the last verification result and run a manual test anytime.</p>

    <div style="display:flex; align-items:center; gap:10px; margin: 2px 0 14px;">
      <?php if ($last_time): ?>
        <span class="ribo-pill <?php echo $is_ok ? 'ok' : 'bad'; ?>"><?php echo $is_ok ? 'Connected' : 'Not Connected'; ?></span>
        <span class="desc">Last checked: <strong><?php echo esc_html($last_time); ?></strong></span>
      <?php else: ?>
        <span class="ribo-pill draft">Not tested yet</span>
        <span class="desc">Click Test Connection below.</span>
      <?php endif; ?>
    </div>

    <div class="ribo-kv">
      <div class="ribo-kv-row"><div class="ribo-kv-key">Last HTTP Code</div><div class="ribo-kv-val"><?php echo $last_code ? esc_html($last_code) : '—'; ?></div></div>
    </div>

    <?php if ($last_err && !$is_ok): ?>
      <div class="ribo-callout" style="margin-top:12px;">
        <div class="ribo-callout-title">Last error</div>
        <code style="display:block; white-space:pre-wrap;"><?php echo esc_html($last_err); ?></code>
      </div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:14px;">
      <input type="hidden" name="action" value="ribo_test_connection">
      <?php wp_nonce_field('ribo_test_connection'); ?>
      <button class="ribo-btn ribo-btn-secondary" type="submit">Test Connection</button>
    </form>

    <hr style="border:0; border-top:1px solid #e6eaf2; margin: 16px 0;" />

    <h3 style="margin:0 0 8px; font-size:16px;">Need help finding your Connection Key?</h3>
    <p class="desc" style="margin-bottom:10px;">In RIBO CRM, look for a page like <strong>Settings</strong> → <strong>Integrations</strong> or <strong>API Keys</strong>. Generate a key for “WordPress” (or “Inbound Forms”), then copy/paste it here.</p>

  </div>
</div>

<script>
(function(){
  var btn = document.getElementById('ribo-toggle-key');
  var inp = document.getElementById('ribo_crm_api_key');
  if (!btn || !inp) return;
  btn.addEventListener('click', function(){
    if (inp.type === 'password') {
      inp.type = 'text';
      btn.textContent = 'Hide';
    } else {
      inp.type = 'password';
      btn.textContent = 'Show';
    }
  });
})();
</script>
