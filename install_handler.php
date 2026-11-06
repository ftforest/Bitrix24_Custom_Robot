<?php
// install_handler.php

const AUTH_FILE = __DIR__ . '/auth.json';

function saveAuth(array $auth): void {
    file_put_contents(AUTH_FILE, json_encode($auth, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

if (isset($_POST['auth']) && is_array($_POST['auth'])) {
    saveAuth($_POST['auth']);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success']);
    exit();
}

http_response_code(400);
echo 'Auth data is required';