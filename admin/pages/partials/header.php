<?php
if (!defined('ABSPATH')) { exit; }

$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : 'ribo-crm';
$items = [
  'ribo-crm' => ['Connection','admin.php?page=ribo-crm','dashicons-admin-links'],
  'ribo-crm-forms' => ['Forms','admin.php?page=ribo-crm-forms','dashicons-feedback'],
  'ribo-crm-builder' => ['Form Builder','admin.php?page=ribo-crm-builder','dashicons-welcome-widgets-menus'],
  'ribo-crm-mapping' => ['Field Mapping','admin.php?page=ribo-crm-mapping','dashicons-randomize'],
  'ribo-crm-pending' => ['Pending Sync','admin.php?page=ribo-crm-pending','dashicons-update'],
  'ribo-crm-logs' => ['Logs','admin.php?page=ribo-crm-logs','dashicons-clipboard'],
];

function ribo_is_active($current, $key) { return $current === $key; }
?>
<div class="ribo-admin-app">
  <header class="ribo-navtabs-bar">
    <div class="ribo-navtabs-bar__inner">
      <div class="ribo-brand ribo-brand--top">
        <div class="ribo-brand-badge">R</div>
        <div>
          <div class="ribo-brand-title">RIBO CRM</div>
        </div>
      </div>

      <nav class="ribo-tabs" aria-label="RIBO CRM navigation">
        <?php foreach ($items as $key => $meta):
          [$label,$url,$icon] = $meta;
          $active = ribo_is_active($page, $key) ? ' is-active' : '';
        ?>
          <a class="ribo-tab<?php echo esc_attr($active); ?>" href="<?php echo esc_url(admin_url($url)); ?>">
            <span class="dashicons <?php echo esc_attr($icon); ?>"></span>
            <span><?php echo esc_html($label); ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
    </div>
  </header>

  <main class="ribo-main">
