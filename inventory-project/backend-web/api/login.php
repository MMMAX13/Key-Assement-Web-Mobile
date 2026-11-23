<?php
// POST /api/login.php   body: email, password (form-data, urlencoded or JSON)
require_once __DIR__ . '/../include/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new ApiException('Method not allowed. Use POST', 405);
}

$data     = get_input();
$email    = strtolower(trim((string)($data['email'] ?? '')));
$password = (string)($data['password'] ?? '');

if ($email === '' || $password === '') {
    throw new ApiException('Email and password are required', 400);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    throw new ApiException('Invalid email format', 400);
}

$pdo  = get_pdo();
$stmt = $pdo->prepare(
    'SELECT id, full_name, email, password_hash, role
       FROM users
      WHERE email = :email
      LIMIT 1'
);
$stmt->execute([':email' => $email]);
$user = $stmt->fetch();

// same message for "no such user" and "wrong password" (don't reveal which)
if (!$user || !password_verify($password, $user['password_hash'])) {
    throw new ApiException('Invalid email or password', 401);
}

json_response(200, [
    'id'        => (int)$user['id'],
    'full_name' => $user['full_name'],
    'email'     => $user['email'],
    'role'      => $user['role'],
]);