<?php
// GET /api/categories.php  -> [{id, name, description}, ...]  (for the Android category spinner)
require_once __DIR__ . '/../include/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    throw new ApiException('Method not allowed. Use GET', 405);
}

$rows = get_pdo()
    ->query('SELECT id, name, description FROM categories ORDER BY name ASC')
    ->fetchAll();

foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
}
unset($r);

json_response(200, $rows);