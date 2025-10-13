<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

try {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) $data = [];

    $lat0 = isset($data['lat']) ? (float)$data['lat'] : null;
    $lng0 = isset($data['lng']) ? (float)$data['lng'] : null;
    $q = isset($data['q']) ? trim((string)$data['q']) : '';
    $city = isset($data['city']) ? trim((string)$data['city']) : '';
    $fallbackCity = isset($data['fallbackCity']) ? trim((string)$data['fallbackCity']) : '';
    $verified = isset($data['verified']) ? (string)$data['verified'] : '';

    if ($lat0 === null || $lng0 === null || $lat0 < -90 || $lat0 > 90 || $lng0 < -180 || $lng0 > 180) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid coordinates']);
        exit;
    }

    $w = ["b.status='approved'"];
    $params = [':lat0' => $lat0, ':lng0' => $lng0];
    if ($q !== '') {
        $w[] = '(b.shop_name LIKE :kw OR b.city LIKE :kw)';
        $params[':kw'] = "%$q%";
    }
    if ($city !== '') {
        $w[] = 'b.city = :city';
        $params[':city'] = $city;
    }
    if ($verified === '1') {
        $w[] = 'u.is_verified = 1';
    }
    $whereSql = implode(' AND ', $w);

    $sql = "SELECT b.shop_id, b.shop_name, b.city, b.address, LEFT(IFNULL(b.description,''),160) description, u.is_verified,
                   COALESCE(AVG(r.rating),0) avg_rating, COUNT(r.review_id) reviews_count,
                   (6371 * ACOS(LEAST(GREATEST(COS(RADIANS(:lat0)) * COS(RADIANS(b.latitude)) * COS(RADIANS(b.longitude) - RADIANS(:lng0)) + SIN(RADIANS(:lat0)) * SIN(RADIANS(b.latitude)),-1),1))) AS distance_km
            FROM Barbershops b
            JOIN Users u ON b.owner_id=u.user_id
            LEFT JOIN Reviews r ON r.shop_id=b.shop_id
            WHERE $whereSql AND b.latitude IS NOT NULL AND b.longitude IS NOT NULL
            GROUP BY b.shop_id
            ORDER BY distance_km ASC
            LIMIT 3";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) $stmt->bindValue($k, $v);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Fallback: If no shops have coordinates saved yet, but we have a city hint,
    // return top rated shops in that city (still honoring status/filters) without distance.
    if (!$rows && $fallbackCity !== '') {
        $w2 = ["b.status='approved'"];
        $p2 = [];
        if ($q !== '') {
            $w2[] = '(b.shop_name LIKE :kw2 OR b.city LIKE :kw2)';
            $p2[':kw2'] = "%$q%";
        }
        if ($verified === '1') {
            $w2[] = 'u.is_verified = 1';
        }
        $fcity = $fallbackCity;
        $fcityNorm = preg_replace('/\s+City$/i', '', $fcity);
        $w2[] = '(b.city = :fcity OR b.city LIKE :fcityLike OR b.city = :fcityNorm OR b.city LIKE :fcityNormLike)';
        $p2[':fcity'] = $fcity;
        $p2[':fcityLike'] = $fcity . '%';
        $p2[':fcityNorm'] = $fcityNorm;
        $p2[':fcityNormLike'] = $fcityNorm . '%';
        $where2 = implode(' AND ', $w2);
        $sql2 = "SELECT b.shop_id, b.shop_name, b.city, b.address, LEFT(IFNULL(b.description,''),160) description, u.is_verified,
                        COALESCE(AVG(r.rating),0) avg_rating, COUNT(r.review_id) reviews_count
                 FROM Barbershops b
                 JOIN Users u ON b.owner_id=u.user_id
                 LEFT JOIN Reviews r ON r.shop_id=b.shop_id
                 WHERE $where2
                 GROUP BY b.shop_id
                 ORDER BY avg_rating DESC, reviews_count DESC, b.shop_name ASC
                 LIMIT 3";
        $st2 = $pdo->prepare($sql2);
        foreach ($p2 as $k => $v) $st2->bindValue($k, $v);
        $st2->execute();
        $rows = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    echo json_encode(['ok' => true, 'shops' => $rows]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error']);
}
