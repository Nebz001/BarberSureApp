<?php
// Unified layout start (no top header) for admin pages
// Usage: set $title before including, then include this file, then your page content inside <main class="admin-main"> ... and finally include footer.php
require_once __DIR__ . '/auth_check.php';
if (!isset($title)) {
    $title = 'Admin • BarberSure';
}
include __DIR__ . '/head.php';
// For right-sidebar layout we include the main content first in pages, then sidebar is appended at end OR we keep order and use CSS flex-row-reverse; simpler is to use flex and push sidebar visually to right by ordering.
// We'll keep sidebar inclusion here and rely on CSS placing it at the right side (flex default order: content before sidebar, but we want sidebar last so pages should output main before including footer). Since pages start <main> AFTER this file, we move sidebar inclusion to after main in each page would require edits; instead apply flex row direction.
// Simpler: Add a wrapping utility via CSS; for now keep as-is.
include __DIR__ . '/sidebar.php';
