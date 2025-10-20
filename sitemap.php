<?php
// Dynamic sitemap generator (basic). Outputs XML.
// Include primary pages + approved shops.
header('Content-Type: application/xml; charset=UTF-8');
$base = 'https://example.com'; // TODO: adjust to real domain or derive dynamically.
require_once __DIR__ . '/config/db.php';
$urls = [
  ['loc' => $base . '/', 'changefreq' => 'daily', 'priority' => '1.0'],
  ['loc' => $base . '/discover.php', 'changefreq' => 'daily', 'priority' => '0.9']
];
// Fetch approved shops
try {
  $stmt = $pdo->query("SELECT shop_id, registered_at FROM Barbershops WHERE status='approved' AND EXISTS (SELECT 1 FROM Shop_Subscriptions s WHERE s.shop_id=Barbershops.shop_id AND s.payment_status='paid' AND CURDATE() BETWEEN s.valid_from AND s.valid_to) ORDER BY shop_id ASC");
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $urls[] = [
      'loc' => $base . '/shop_details.php?id=' . (int)$row['shop_id'],
      'lastmod' => substr($row['registered_at'], 0, 10),
      'changefreq' => 'weekly',
      'priority' => '0.6'
    ];
  }
} catch (Throwable $e) { /* ignore */
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ($urls as $u) {
  echo "  <url>\n";
  echo "    <loc>" . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
  if (!empty($u['lastmod'])) echo "    <lastmod>" . htmlspecialchars($u['lastmod'], ENT_XML1) . "</lastmod>\n";
  if (!empty($u['changefreq'])) echo "    <changefreq>{$u['changefreq']}</changefreq>\n";
  if (!empty($u['priority'])) echo "    <priority>{$u['priority']}</priority>\n";
  echo "  </url>\n";
}
echo "</urlset>";
