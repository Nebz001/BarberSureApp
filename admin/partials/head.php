<?php

/** Shared <head> for admin pages */
$title = $title ?? 'Admin • BarberSure';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title><?= e($title) ?></title>
  <link rel="icon" type="image/svg+xml" href="../assets/images/favicon.svg" />
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet" />
  <?php if (function_exists('get_admin_template_asset')): ?>
    <link rel="stylesheet" href="<?= e(get_admin_template_asset('main-*.css')) ?>" />
  <?php else: ?>
    <link rel="stylesheet" href="/Admin-template/dist-modern/assets/main-D9K-blpF.css" />
  <?php endif; ?>
  <style>
    :root {
      --adm-bg: #0d1117;
      --adm-bg-alt: #161b22;
      --adm-border: #30363d;
      --adm-card-bg: #1e2732;
      --adm-text: #d1d5db;
      --adm-text-soft: #94a3b8;
      --adm-accent: #0ea5e9;
      /* sky-500 */
      --adm-accent-soft: #3b82f6;
      /* blue-500 */
      --adm-danger: #ef4444;
      --adm-warning: #f59e0b;
      --adm-success: #10b981;
      /* Typography scale */
      --adm-font-base: 17px;
      /* base up from default 16 for readability */
      --adm-line-height: 1.5;
    }

    body.admin-layout {
      font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      background: var(--adm-bg);
      color: var(--adm-text);
      font-size: var(--adm-font-base);
      line-height: var(--adm-line-height);
      -webkit-font-smoothing: antialiased;
    }

    body.admin-layout .text-muted,
    body.admin-layout .text-secondary,
    body.admin-layout .small,
    body.admin-layout .stat-small {
      color: var(--adm-text-soft) !important;
    }

    h1,
    h2,
    h3,
    h4,
    h5,
    h6 {
      color: var(--adm-text);
      line-height: 1.25;
      font-weight: 600;
    }

    h1 {
      font-size: 2.1rem;
    }

    h2 {
      font-size: 1.65rem;
    }

    h3 {
      font-size: 1.35rem;
    }

    h4 {
      font-size: 1.15rem;
    }

    h5 {
      font-size: 1rem;
    }

    h6 {
      font-size: .85rem;
      letter-spacing: .5px;
      text-transform: uppercase;
    }

    a {
      color: var(--adm-accent);
    }

    a:hover {
      color: var(--adm-accent-soft);
    }

    .card {
      background: var(--adm-card-bg);
      border: 1px solid var(--adm-border);
      color: var(--adm-text);
    }

    .card .card-header {
      background: linear-gradient(180deg, var(--adm-bg-alt), var(--adm-card-bg));
      border-bottom: 1px solid var(--adm-border);
    }

    .table {
      color: var(--adm-text);
    }

    .table thead th {
      background: var(--adm-bg-alt);
      border-bottom: 1px solid var(--adm-border);
      color: var(--adm-text-soft);
    }

    /* Normalize Bootstrap “light” variants inside dark theme */
    .table-light,
    .table-light>td,
    .table-light>th {
      background-color: #1f2732 !important;
      color: var(--adm-text-soft) !important;
    }

    .table-hover tbody tr.table-light:hover>* {
      --bs-table-accent-bg: #243041;
      color: var(--adm-text) !important;
    }

    .bg-light,
    .card.bg-light {
      background: #1e2732 !important;
      color: var(--adm-text) !important;
    }

    .text-bg-light {
      background: #1e2732 !important;
      color: var(--adm-text) !important;
    }

    .table tbody tr {
      border-bottom: 1px solid var(--adm-border);
    }

    .table tbody tr:hover {
      background: #243041;
    }

    .badge {
      background: var(--adm-bg-alt);
      color: var(--adm-text);
      border: 1px solid var(--adm-border);
      font-weight: 500;
      letter-spacing: .3px;
    }

    .badge.text-bg-warning {
      background: #4a3a13;
      color: #fbbf24;
    }

    .badge.text-bg-info {
      background: #292d36;
      color: #e5e7eb;
    }

    .badge.text-bg-secondary {
      background: #374151;
      color: #d1d5db;
    }

    .badge.text-bg-success {
      background: #064e3b;
      color: #34d399;
    }

    .btn-primary {
      background: var(--adm-accent);
      border-color: var(--adm-accent-soft);
    }

    .btn-primary:hover {
      background: var(--adm-accent-soft);
      border-color: var(--adm-accent-soft);
    }

    .btn-outline-secondary {
      color: var(--adm-text-soft);
      border-color: var(--adm-border);
    }

    .btn-outline-secondary:hover {
      background: var(--adm-bg-alt);
      color: var(--adm-text);
    }

    .btn-secondary {
      background: var(--adm-bg-alt);
      border-color: var(--adm-border);
    }

    .btn-secondary:hover {
      background: #243041;
    }

    input.form-control,
    select.form-select,
    textarea.form-control {
      background: var(--adm-bg-alt);
      border: 1px solid var(--adm-border);
      color: var(--adm-text);
    }

    input.form-control:focus,
    select.form-select:focus,
    textarea.form-control:focus {
      background: var(--adm-bg-alt);
      color: var(--adm-text);
      border-color: var(--adm-accent);
      box-shadow: 0 0 0 .15rem rgba(14, 165, 233, .25);
    }

    .admin-shell {
      background: var(--adm-bg);
    }

    .admin-sidebar {
      background: var(--adm-bg-alt);
      border-right: 1px solid var(--adm-border);
    }

    .admin-sidebar .nav-link {
      color: var(--adm-text-soft);
    }

    .admin-sidebar .nav-link.active,
    .admin-sidebar .nav-link:hover {
      background: #243041;
      color: var(--adm-text);
    }

    .admin-main .card-title,
    .card h5,
    .card h6 {
      color: var(--adm-text);
    }

    .form-label {
      color: var(--adm-text-soft);
    }

    .border-top,
    .border-bottom,
    .border-start,
    .border-end {
      border-color: var(--adm-border) !important;
    }

    hr {
      border-color: var(--adm-border);
    }

    .list-group-item {
      background: var(--adm-card-bg);
      color: var(--adm-text);
      border-color: var(--adm-border);
    }

    .nav-pills .nav-link.active,
    .nav-pills .show>.nav-link {
      background: linear-gradient(135deg, var(--adm-accent), var(--adm-accent-soft));
      border: 0;
      color: #fff;
    }

    /* Scrollbar (optional) */
    ::-webkit-scrollbar {
      width: 10px;
    }

    ::-webkit-scrollbar-track {
      background: var(--adm-bg);
    }

    ::-webkit-scrollbar-thumb {
      background: #2f3845;
      border-radius: 6px;
    }

    ::-webkit-scrollbar-thumb:hover {
      background: #3d4957;
    }

    /* Layout container: content + left sidebar */
    .admin-shell {
      display: flex;
      flex-direction: row;
      min-height: 100vh;
      width: 100%;
      position: relative;
    }

    .admin-sidebar {
      width: 240px;
      flex: 0 0 240px;
      padding: .75rem .75rem 1.5rem;
      /* reduced top padding */
      display: flex;
      flex-direction: column;
      position: fixed;
      inset: 0 auto 0 0;
      /* top:0; right:auto; bottom:0; left:0 */
      height: 100vh;
      overflow-y: auto;
      z-index: 1030;
      /* above main content */
      background: var(--adm-bg-alt);
    }

    .admin-main {
      margin-left: 240px;
      /* reserve space */
      flex: 1 1 auto;
      min-width: 0;
    }

    .admin-sidebar .nav-link {
      display: flex;
      align-items: center;
      gap: .6rem;
      font-size: .95rem;
      /* larger for readability */
      font-weight: 500;
      padding: .6rem .85rem;
      border-radius: .55rem;
      color: var(--adm-text-soft);
    }

    .admin-sidebar .nav-link i {
      font-size: 1rem;
    }

    .admin-sidebar .nav-link.active,
    .admin-sidebar .nav-link:hover {
      text-decoration: none;
    }

    .admin-sidebar .brand {
      font-weight: 600;
      font-size: 1rem;
      letter-spacing: .5px;
      padding: .25rem .5rem 1rem;
      display: flex;
      align-items: center;
      gap: .55rem;
      color: var(--adm-text);
    }

    .admin-sidebar .brand .logo-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: var(--adm-accent);
      box-shadow: 0 0 0 4px rgba(14, 165, 233, .25);
    }

    .admin-sidebar .nav {
      gap: .15rem;
    }

    .admin-sidebar .nav-link {
      position: relative;
    }

    .admin-sidebar .nav-link i {
      width: 18px;
      text-align: center;
    }

    .admin-sidebar .nav-link.active:before {
      content: "";
      position: absolute;
      left: -.75rem;
      top: 50%;
      transform: translateY(-50%);
      width: 4px;
      height: 26px;
      border-radius: 2px;
      background: var(--adm-accent);
    }

    .admin-main {
      flex: 1 1 auto;
      min-width: 0;
    }

    /* Prevent layout shift when scrollbar appears (reserve space) */
    html {
      scrollbar-gutter: stable both-edges;
    }

    /* Tablet: keep sidebar fixed but narrower */
    @media (max-width: 992px) {
      .admin-sidebar {
        width: 200px;
        flex: 0 0 200px;
      }

      .admin-main {
        margin-left: 200px;
      }
    }

    /* Phones: off‑canvas sidebar */
    @media (max-width: 576px) {
      .admin-sidebar {
        transform: translateX(-100%);
        transition: transform .3s ease;
        box-shadow: 0 0 0 1px var(--adm-border), 0 6px 18px -4px rgba(0, 0, 0, .6);
      }

      .admin-sidebar.is-open {
        transform: translateX(0);
      }

      .admin-main {
        margin-left: 0;
      }

      body.admin-layout {
        overflow-x: hidden;
      }

      .admin-toggle-btn {
        position: fixed;
        top: .65rem;
        left: .65rem;
        z-index: 1100;
        background: var(--adm-bg-alt);
        border: 1px solid var(--adm-border);
        color: var(--adm-text);
        width: 42px;
        height: 42px;
        border-radius: .75rem;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 2px 6px rgba(0, 0, 0, .4);
      }

      .admin-toggle-btn:active {
        transform: scale(.94);
      }
    }

    .stat-small {
      font-size: .7rem;
      /* slightly larger */
      letter-spacing: .55px;
      text-transform: uppercase;
      font-weight: 600;
      opacity: .9;
    }

    .kpi-value {
      font-size: 1.55rem;
      /* increased */
      font-weight: 600;
      letter-spacing: .5px;
    }

    .table td,
    .table th {
      font-size: .95rem;
    }

    .form-label {
      font-size: .9rem;
    }

    .badge {
      font-size: .75rem;
    }

    /* Recent Activity feed enhancements */
    .activity-feed {
      font-size: 1rem;
      line-height: 1.4;
    }

    .activity-feed .activity-item {
      padding: .85rem 0;
      display: flex;
      gap: .9rem;
      border-bottom: 1px solid var(--adm-border);
    }

    .activity-feed .activity-item:last-child {
      border-bottom: 0;
    }

    .activity-feed .activity-icon {
      width: 46px;
      height: 46px;
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--adm-bg-alt);
      font-size: 1.25rem;
      color: var(--adm-accent);
      flex-shrink: 0;
      box-shadow: 0 0 0 1px var(--adm-border);
    }

    .activity-feed .activity-main {
      flex: 1 1 auto;
    }

    .activity-feed .activity-label {
      font-weight: 600;
      letter-spacing: .25px;
    }

    .activity-feed .activity-time {
      font-size: .7rem;
      text-transform: uppercase;
      letter-spacing: .6px;
      margin-top: .3rem;
      color: var(--adm-text-soft);
    }

    /* Quick Alerts styling */
    .quick-alerts {
      font-size: .95rem;
      line-height: 1.45;
      margin: 0;
      padding: 0;
      list-style: none;
    }

    .quick-alerts .qa-item {
      display: flex;
      gap: .75rem;
      padding: .65rem 0;
      border-bottom: 1px solid var(--adm-border);
    }

    .quick-alerts .qa-item:last-child {
      border-bottom: 0;
    }

    .quick-alerts .qa-ico {
      width: 38px;
      height: 38px;
      border-radius: 11px;
      background: var(--adm-bg-alt);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.05rem;
      color: var(--adm-accent);
      flex-shrink: 0;
      box-shadow: 0 0 0 1px var(--adm-border);
    }

    .quick-alerts .qa-text {
      flex: 1 1 auto;
      font-weight: 500;
      letter-spacing: .2px;
    }

    .quick-alerts .qa-text small {
      display: block;
      font-size: .7rem;
      text-transform: uppercase;
      letter-spacing: .55px;
      margin-top: .25rem;
      color: var(--adm-text-soft);
      font-weight: 500;
    }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <script>
    // Adjust Chart.js defaults for dark mode once loaded
    document.addEventListener('DOMContentLoaded', () => {
      if (window.Chart) {
        Chart.defaults.color = getComputedStyle(document.documentElement).getPropertyValue('--adm-text').trim() || '#d1d5db';
        Chart.defaults.borderColor = getComputedStyle(document.documentElement).getPropertyValue('--adm-border').trim() || '#30363d';
        Chart.defaults.plugins.legend.labels.color = Chart.defaults.color;
        Chart.defaults.plugins.title.color = Chart.defaults.color;
        // Slightly transparent grid lines
        ['x', 'y'].forEach(axis => {
          if (Chart.defaults.scales[axis]) {
            Chart.defaults.scales[axis].grid.color = 'rgba(255,255,255,0.07)';
            Chart.defaults.scales[axis].ticks.color = Chart.defaults.color;
          }
        });
      }
      // Mobile sidebar toggle
      const toggleBtn = document.querySelector('.admin-toggle-btn');
      const sidebar = document.querySelector('.admin-sidebar');
      if (toggleBtn && sidebar) {
        const openSidebar = () => sidebar.classList.add('is-open');
        const closeSidebar = () => sidebar.classList.remove('is-open');
        toggleBtn.addEventListener('click', () => {
          sidebar.classList.toggle('is-open');
        });
        // Close when clicking a nav link (small screens)
        sidebar.addEventListener('click', (e) => {
          if (e.target.closest('.nav-link')) {
            if (window.matchMedia('(max-width: 576px)').matches) closeSidebar();
          }
        });
        // Close on escape
        document.addEventListener('keydown', (e) => {
          if (e.key === 'Escape') closeSidebar();
        });
      }
    });
  </script>
</head>

<body data-page="dashboard" class="admin-layout">
  <button class="admin-toggle-btn d-lg-none d-sm-flex d-md-none" type="button" aria-label="Toggle navigation"><i class="bi bi-list fs-4"></i></button>
  <div class="admin-shell">