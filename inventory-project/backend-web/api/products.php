<?php
// /api/products.php
//   GET    ?category_id=X &search=kw &sort=newest|low_stock &id=X
//   POST   multipart/form-data  (name, sku, category_id, unit_price, quantity,
//                                min_stock_threshold, description?, image?, user_id)
//   PUT    (or POST with _method=PUT)  id, user_id + any fields to change
//          stock: send  quantity=<new total>  OR  adjust=<+1 / -1>
//   DELETE ?id=X  (or POST with _method=DELETE)
require_once __DIR__ . '/../include/api_helpers.php';

$pdo    = get_pdo();
$method = $_SERVER['REQUEST_METHOD'];

// method override so Android can send multipart updates via POST
if ($method === 'POST' && isset($_POST['_method'])) {
    $override = strtoupper(trim((string)$_POST['_method']));
    if (in_array($override, ['PUT', 'DELETE'], true)) {
        $method = $override;
    }
}

switch ($method) {
    case 'GET':    handle_get($pdo);    break;
    case 'POST':   handle_post($pdo);   break;
    case 'PUT':    handle_put($pdo);    break;
    case 'DELETE': handle_delete($pdo); break;
    default:       throw new ApiException('Method not allowed', 405);
}

// =====================================================================
// GET
// =====================================================================
function handle_get(PDO $pdo): void
{
    if (isset($_GET['id']) && $_GET['id'] !== '') {
        $p = fetch_product($pdo, parse_int($_GET['id'], 'id', 1));
        if (!$p) {
            throw new ApiException('Product not found', 404);
        }
        json_response(200, $p);
    }

    $where  = [];
    $params = [];

    if (isset($_GET['category_id']) && $_GET['category_id'] !== '') {
        $where[]         = 'p.category_id = :cat';
        $params[':cat']  = parse_int($_GET['category_id'], 'category_id', 1);
    }

    $search = trim((string)($_GET['search'] ?? ''));
    if ($search !== '') {
        $like = '%' . addcslashes($search, '%_\\') . '%';
        $where[]        = '(p.name LIKE :s1 OR p.sku LIKE :s2)';
        $params[':s1']  = $like;
        $params[':s2']  = $like;
    }

    $order = (($_GET['sort'] ?? 'newest') === 'low_stock')
        ? '(p.quantity < p.min_stock_threshold) DESC, p.quantity ASC, p.name ASC'
        : 'p.id DESC';

    $sql = 'SELECT p.*, c.name AS category_name
              FROM products p
              JOIN categories c ON c.id = p.category_id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . " ORDER BY $order";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    json_response(200, array_map('format_product', $stmt->fetchAll()));
}

// =====================================================================
// POST  (create)
// =====================================================================
function handle_post(PDO $pdo): void
{
    $data   = get_input();
    $userId = require_user($pdo, $data['user_id'] ?? null);

    $name = trim((string)($data['name'] ?? ''));
    $sku  = trim((string)($data['sku'] ?? ''));
    if ($name === '' || $sku === '') {
        throw new ApiException('name and sku are required', 400);
    }
    if (strlen($name) > 255 || strlen($sku) > 255) {
        throw new ApiException('name and sku must be 255 characters or fewer', 400);
    }

    $categoryId = parse_int($data['category_id'] ?? null, 'category_id', 1);
    $price      = parse_price($data['unit_price'] ?? null);
    $quantity   = parse_int($data['quantity'] ?? null, 'quantity', 0);
    $minStock   = parse_int($data['min_stock_threshold'] ?? null, 'min_stock_threshold', 0);
    $desc       = trim((string)($data['description'] ?? ''));

    if (!category_exists($pdo, $categoryId)) {
        throw new ApiException('Category does not exist', 400);
    }
    $stmt = $pdo->prepare('SELECT 1 FROM products WHERE sku = :sku');
    $stmt->execute([':sku' => $sku]);
    if ($stmt->fetchColumn()) {
        throw new ApiException('SKU already exists', 400);
    }

    $imagePath = null;
    try {
        if (has_uploaded_image()) {
            $imagePath = save_uploaded_image($_FILES['image']);
        }

        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'INSERT INTO products (category_id, sku, name, description, unit_price, quantity, min_stock_threshold, image_path)
             VALUES (:cat, :sku, :name, :descr, :price, :qty, :min, :img)'
        );
        $stmt->execute([
            ':cat'   => $categoryId,
            ':sku'   => $sku,
            ':name'  => $name,
            ':descr' => $desc,
            ':price' => $price,
            ':qty'   => $quantity,
            ':min'   => $minStock,
            ':img'   => $imagePath,
        ]);
        $newId = (int)$pdo->lastInsertId();
        log_stock($pdo, $newId, $userId, 'CREATED', $quantity);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        delete_image_file($imagePath);   // don't leave orphan files
        throw $e;
    }

    json_response(201, fetch_product($pdo, $newId));
}

// =====================================================================
// PUT  (update details and/or stock)
// =====================================================================
function handle_put(PDO $pdo): void
{
    $data   = get_input();
    $id     = parse_int($data['id'] ?? ($_GET['id'] ?? null), 'id', 1);
    $userId = require_user($pdo, $data['user_id'] ?? null);

    $newImage = null;
    $oldImage = null;

    try {
        $pdo->beginTransaction();

        // lock the row so two devices can't overwrite each other's stock change
        $stmt = $pdo->prepare('SELECT * FROM products WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        $current = $stmt->fetch();
        if (!$current) {
            throw new ApiException('Product not found', 404);
        }

        $sets           = [];
        $params         = [':id' => $id];
        $detailsChanged = false;

        if (array_key_exists('name', $data)) {
            $v = trim((string)$data['name']);
            if ($v === '' || strlen($v) > 255) {
                throw new ApiException('name must be 1-255 characters', 400);
            }
            $sets[] = 'name = :name';  $params[':name'] = $v;  $detailsChanged = true;
        }

        if (array_key_exists('sku', $data)) {
            $v = trim((string)$data['sku']);
            if ($v === '' || strlen($v) > 255) {
                throw new ApiException('sku must be 1-255 characters', 400);
            }
            $chk = $pdo->prepare('SELECT 1 FROM products WHERE sku = :sku AND id <> :id');
            $chk->execute([':sku' => $v, ':id' => $id]);
            if ($chk->fetchColumn()) {
                throw new ApiException('SKU already exists', 400);
            }
            $sets[] = 'sku = :sku';  $params[':sku'] = $v;  $detailsChanged = true;
        }

        if (array_key_exists('category_id', $data)) {
            $v = parse_int($data['category_id'], 'category_id', 1);
            if (!category_exists($pdo, $v)) {
                throw new ApiException('Category does not exist', 400);
            }
            $sets[] = 'category_id = :cat';  $params[':cat'] = $v;  $detailsChanged = true;
        }

        if (array_key_exists('description', $data)) {
            $sets[] = 'description = :descr';
            $params[':descr'] = trim((string)$data['description']);
            $detailsChanged = true;
        }

        if (array_key_exists('unit_price', $data)) {
            $sets[] = 'unit_price = :price';
            $params[':price'] = parse_price($data['unit_price']);
            $detailsChanged = true;
        }

        if (array_key_exists('min_stock_threshold', $data)) {
            $sets[] = 'min_stock_threshold = :min';
            $params[':min'] = parse_int($data['min_stock_threshold'], 'min_stock_threshold', 0);
            $detailsChanged = true;
        }

        if (has_uploaded_image()) {
            $newImage = save_uploaded_image($_FILES['image']);
            $oldImage = $current['image_path'];
            $sets[]   = 'image_path = :img';
            $params[':img'] = $newImage;
            $detailsChanged = true;
        }

        // ---- stock: absolute "quantity" or relative "adjust" (+1 / -1) ----
        $oldQty = (int)$current['quantity'];
        $newQty = $oldQty;

        if (isset($data['adjust']) && $data['adjust'] !== '') {
            $newQty = $oldQty + parse_int($data['adjust'], 'adjust');
        } elseif (isset($data['quantity']) && $data['quantity'] !== '') {
            $newQty = parse_int($data['quantity'], 'quantity', 0);
        }
        if ($newQty < 0) {
            throw new ApiException('Stock cannot go below zero', 400);
        }
        if ($newQty !== $oldQty) {
            $sets[]         = 'quantity = :qty';
            $params[':qty'] = $newQty;
        }

        if (!$sets) {
            throw new ApiException('No changes provided', 400);
        }

        $sets[] = 'updated_at = NOW()';
        $upd = $pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $upd->execute($params);

        if ($detailsChanged) {
            log_stock($pdo, $id, $userId, 'UPDATED', 0);
        }
        if ($newQty !== $oldQty) {
            $diff = $newQty - $oldQty;
            log_stock($pdo, $id, $userId, $diff > 0 ? 'STOCK_IN' : 'STOCK_OUT', abs($diff));
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        delete_image_file($newImage);
        throw $e;
    }

    delete_image_file($oldImage);   // replaced image no longer needed
    json_response(200, fetch_product($pdo, $id));   // includes is_low_stock
}

// =====================================================================
// DELETE
// =====================================================================
function handle_delete(PDO $pdo): void
{
    $input = ($_SERVER['REQUEST_METHOD'] === 'GET') ? [] : get_input();
    $id    = parse_int($_GET['id'] ?? ($input['id'] ?? null), 'id', 1);

    $stmt = $pdo->prepare('SELECT image_path FROM products WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $product = $stmt->fetch();
    if (!$product) {
        throw new ApiException('Product not found', 404);
    }

    // NOTE: stock_logs.product_id is ON DELETE CASCADE, so this also removes
    // the product's audit rows (a 'DELETED' log row would be wiped instantly).
    $del = $pdo->prepare('DELETE FROM products WHERE id = :id');
    $del->execute([':id' => $id]);

    delete_image_file($product['image_path']);

    json_response(200, ['status' => 'success', 'message' => 'Product deleted', 'id' => $id]);
}