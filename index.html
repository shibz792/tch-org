<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';
$config = settings();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="<?= e($config['primary_color']) ?>">
    <title><?= e($config['organization_name']) ?> — Organization</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet" href="/assets/css/brand.css">
    <link rel="stylesheet" href="/assets/css/chart-avatars.css">
    <link rel="stylesheet" href="/assets/css/polish.css">
    <link rel="stylesheet" href="/assets/css/presentation-motion.css">
    <link rel="stylesheet" href="/assets/css/chart-effects.css">
    <script src="/assets/js/d3.min.js" defer></script>
    <script src="/assets/js/chart.js" defer></script>
</head>
<body>
<a class="skip-link" href="#chart">Skip to organization chart</a>
<header class="site-header">
    <a class="brand" href="/" aria-label="Organization chart home">
        <img class="brand-logo" src="/assets/tch-logo.png" alt="<?= e($config['organization_name']) ?>">
        <span class="brand-copy"><strong><?= e($config['organization_name']) ?></strong><small><?= e($config['organization_tagline']) ?></small></span>
    </a>
    <div class="header-actions">
        <button class="icon-button" id="themeToggle" aria-label="Toggle dark mode">◐</button>
        <a class="text-button" href="/admin/">Admin</a>
    </div>
</header>

<main>
    <section class="chart-heading">
        <div>
            <span class="eyebrow">Organization directory</span>
            <h1>Meet the people<br><em>moving us forward.</em></h1>
            <p>Explore teams, reporting lines and the people behind the work.</p>
        </div>
        <div class="stat-card"><strong id="peopleCount">—</strong><span>People</span><small>Across the organization</small></div>
    </section>

    <section class="workspace" aria-label="Organization chart workspace">
        <div class="presentation-bar">
            <div><img src="/assets/tch-logo.png" alt=""><span>Organization Overview</span></div>
            <button id="exitFullscreen" class="presentation-exit">Exit presentation</button>
        </div>
        <div class="toolbar">
            <label class="search"><span>⌕</span><input id="searchInput" type="search" placeholder="Find a person, role or team…" autocomplete="off"></label>
            <select id="departmentFilter" aria-label="Filter by department"><option value="">All departments</option></select>
            <div class="toolbar-group">
                <button id="zoomOut" class="icon-button" aria-label="Zoom out">−</button>
                <button id="fitChart" class="icon-button" aria-label="Fit chart">⌗</button>
                <button id="zoomIn" class="icon-button" aria-label="Zoom in">+</button>
                <button id="fullscreen" class="text-button">Present</button>
                <button id="printChart" class="text-button">Print</button>
            </div>
        </div>
        <div id="chart" class="chart-canvas" tabindex="0" aria-label="Interactive organization chart">
            <div class="loading">Preparing the organization…</div>
        </div>
        <div id="mobileChart" class="mobile-chart" aria-live="polite"></div>
    </section>
</main>

<aside id="profileDrawer" class="profile-drawer" aria-hidden="true" aria-label="Person profile">
    <button id="closeDrawer" class="drawer-close" aria-label="Close profile">×</button>
    <div id="profileContent"></div>
</aside>
<div id="drawerBackdrop" class="drawer-backdrop"></div>
</body>
</html>
