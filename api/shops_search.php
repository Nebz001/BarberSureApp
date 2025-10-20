<?php
// Lightweight JSON API for shop search (progressive enhancement for discover.php)
// GET params: q, city, service, verified, page, per_page
// Returns JSON: { data:[], total:int, page:int, per_page:int }
// Note: Public endpoint - apply basic rate limiting placeholder if needed.

header('Content-Type: application/json');

session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/functions.php';

function in_get($k, $d = null)
{
  return isset($_GET[$k]) ? trim((string)$_GET[$k]) : $d;
}

$q = in_get('q', '');
$city = in_get('city', '');
$service = in_get('service', '');
$verified = in_get('verified', '');
$page = max(1, (int)in_get('page', 1));
$perPage = (int)in_get('per_page', 12);
if (!in_array($perPage, [6, 12, 24, 36], true)) $perPage = 12;

$params = [];
$w = [
  "b.status='approved'",
  "EXISTS (SELECT 1 FROM Shop_Subscriptions s WHERE s.shop_id=b.shop_id AND s.payment_status='paid' AND CURDATE() BETWEEN s.valid_from AND s.valid_to)"
];
if ($q !== '') {
  $w[] = "(b.shop_name LIKE :kw OR b.city LIKE :kw)";
  $params[':kw'] = "%$q%";
}
if ($city !== '') {
  $w[] = "b.city=:city";
  $params[':city'] = $city;
}
if ($verified === '1') {
  $w[] = "u.is_verified=1";
}
if ($service !== '') {
  $w[] = "EXISTS(SELECT 1 FROM Services s WHERE s.shop_id=b.shop_id AND s.service_name LIKE :svc)";
  $params[':svc'] = "%$service%";
}
$whereSql = implode(' AND ', $w);

// count
$cStmt = $pdo->prepare("SELECT COUNT(DISTINCT b.shop_id) FROM Barbershops b JOIN Users u ON b.owner_id=u.user_id WHERE $whereSql");
foreach ($params as $k => $v) $cStmt->bindValue($k, $v);
$cStmt->execute();
$total = (int)$cStmt->fetchColumn();
$offset = ($page - 1) * $perPage;
if ($offset >= $total && $total > 0) {
  $page = (int)ceil($total / $perPage);
  $offset = ($page - 1) * $perPage;
}

$sql = "SELECT b.shop_id,b.shop_name,b.city,b.address,LEFT(IFNULL(b.description,''),180) AS description,u.is_verified,
      COALESCE(AVG(r.rating),0) avg_rating, COUNT(r.review_id) reviews_count
      FROM Barbershops b
      JOIN Users u ON b.owner_id=u.user_id
      LEFT JOIN Reviews r ON r.shop_id=b.shop_id
      WHERE $whereSql
      GROUP BY b.shop_id
      ORDER BY b.shop_name ASC
      LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

http_response_code(200);
echo json_encode([
  'data' => $data,
  'total' => $total,
  'page' => $page,
  'per_page' => $perPage
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
