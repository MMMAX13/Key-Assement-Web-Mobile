<?php
// includes/api_helpers.php - include this at the top of every /api/ endpoint

require_once __DIR__ . '/../core/db.php';

// ---------------------------------------------------------------------
// Never leak PHP warnings / stack traces into JSON responses
// ---------------------------------------------------------------------
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start(); // swallow any accidental output

class ApiException extends Exception
{
    private int $httpCode;

    public function __construct(string $message, int $httpCode = 400)
    {
        parent::__construct($message);
        $this->httpCode = $httpCode;
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }
}

function json_response(int $code, $data): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(int $code, string $message): void
{
    json_response($code, ['status' => 'error', 'message' => $message]);
}

// Warnings/notices become exceptions so try-catch / the handler below catches them
set_error_handler(function ($severity, $message, $file, $line) {
    if ($severity & (E_DEPRECATED | E_USER_DEPRECATED)) {
        return true;
    }
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e) {
    if ($e instanceof ApiException) {
        json_error($e->getHttpCode(), $e->getMessage());
    }
    error_log((string)$e);                       // real details go to the server log only
    json_error(500, 'Internal server error');
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_length()) {
            ob_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
    }
});

// ---------------------------------------------------------------------
// Request input (form-data, urlencoded or JSON; works for PUT too)
// ---------------------------------------------------------------------
function get_input(): array
{
    $method = $_SERVER['REQUEST_METHOD'];
    $ctype  = $_SERVER['CONTENT_TYPE'] ?? '';

    if ($method === 'POST' && !empty($_POST)) {
        return $_POST;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }

    if (stripos($ctype, 'application/json') !== false) {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ApiException('Invalid JSON body', 400);
        }
        return $data;
    }

    // multipart can't be parsed by PHP on real PUT requests -> use POST + _method=PUT
    parse_str($raw, $data);
    return is_array($data) ? $data : [];
}

// ---------------------------------------------------------------------
// Validation helpers
// ---------------------------------------------------------------------
function parse_int($value, string $field, ?int $min = null): int
{
    $v = filter_var($value, FILTER_VALIDATE_INT);
    if ($v === false) {
        throw new ApiException("$field must be a whole number", 400);
    }
    if ($min !== null && $v < $min) {
        throw new ApiException("$field must be at least $min", 400);
    }
    return $v;
}

function parse_price($value): float
{
    $v = filter_var($value, FILTER_VALIDATE_FLOAT);
    if ($v === false || $v < 0 || $v > 99999999.99) {
        throw new ApiException('unit_price must be a valid non-negative number', 400);
    }
    return round($v, 2);
}

function require_user(PDO $pdo, $value): int
{
    if ($value === null || $value === '') {
        throw new ApiException('user_id is required', 400);
    }
    $id   = parse_int($value, 'user_id', 1);
    $stmt = $pdo->prepare('SELECT id FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetch()) {
        throw new ApiException('Invalid user', 401);
    }
    return $id;
}

function category_exists(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM categories WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return (bool)$stmt->fetchColumn();
}

// ---------------------------------------------------------------------
// Product formatting
// ---------------------------------------------------------------------
function base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // scripts live in /api/, so go up one level to the backend-web root
    $dir = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
    return $scheme . '://' . $host . $dir;
}

function format_product(array $r): array
{
    $qty = (int)$r['quantity'];
    $min = (int)$r['min_stock_threshold'];

    return [
        'id'                  => (int)$r['id'],
        'category_id'         => (int)$r['category_id'],
        'category_name'       => $r['category_name'] ?? null,
        'sku'                 => $r['sku'],
        'name'                => $r['name'],
        'description'         => $r['description'],
        'unit_price'          => (float)$r['unit_price'],
        'quantity'            => $qty,
        'min_stock_threshold' => $min,
        'image_path'          => $r['image_path'],
        'image_url'           => !empty($r['image_path']) ? base_url() . '/' . $r['image_path'] : null,
        'is_low_stock'        => $qty < $min,
        'updated_at'          => $r['updated_at'] ?? null,
    ];
}

function fetch_product(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.*, c.name AS category_name
           FROM products p
           JOIN categories c ON c.id = p.category_id
          WHERE p.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ? format_product($row) : null;
}

function log_stock(PDO $pdo, int $productId, int $userId, string $action, int $qtyChanged, string $platform = 'MOBILE'): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO stock_logs (product_id, user_id, action_type, quantity_changed, platform)
         VALUES (:pid, :uid, :action, :qty, :platform)'
    );
    $stmt->execute([
        ':pid'      => $productId,
        ':uid'      => $userId,
        ':action'   => $action,
        ':qty'      => $qtyChanged,
        ':platform' => $platform,
    ]);
}

// ---------------------------------------------------------------------
// Image upload (JPG / PNG / WEBP, max 5MB) -> uploads/products/
// ---------------------------------------------------------------------
function has_uploaded_image(): bool
{
    return isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE;
}

function save_uploaded_image(array $file): string
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ApiException('Image exceeds the maximum size of 5MB', 400);
        }
        throw new ApiException('Image upload failed (error code ' . $file['error'] . ')', 400);
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new ApiException('Image exceeds the maximum size of 5MB', 400);
    }

    // check the real MIME type, not just the extension
    $mime    = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new ApiException('Invalid image type. Only JPG, PNG and WEBP are allowed', 400);
    }

    $dir = dirname(__DIR__) . '/uploads/products';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new ApiException('Could not create upload folder', 500);
    }

    $name = 'item_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new ApiException('Could not save uploaded image', 500);
    }

    return 'uploads/products/' . $name;   // relative path stored in the DB
}

function delete_image_file(?string $relativePath): void
{
    if (empty($relativePath)) {
        return;
    }
    $full = dirname(__DIR__) . '/uploads/products/' . basename($relativePath);
    if (is_file($full)) {
        @unlink($full);
    }
}