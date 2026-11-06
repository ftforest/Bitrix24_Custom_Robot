<?php
// token_manager.php

const AUTH_FILE = __DIR__ . '/auth.json';

/**
 * Читает сохранённые токены из auth.json.
 */
function getAuth(): array {
    if (!file_exists(AUTH_FILE)) {
        throw new RuntimeException('Файл auth.json не найден. Переустановите приложение.');
    }

    $auth = json_decode(file_get_contents(AUTH_FILE), true);
    if (!is_array($auth) || empty($auth['access_token'])) {
        throw new RuntimeException('Некорректное содержимое auth.json.');
    }

    return $auth;
}

/**
 * Сохраняет обновлённые токены в auth.json.
 */
function saveAuth(array $auth): void {
    file_put_contents(AUTH_FILE, json_encode($auth, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

/**
 * Обменивает refresh_token на новую пару токенов.
 *
 * @param array $config Массив с client_id, client_secret (из config.php).
 * @return array Новые данные авторизации.
 */
function refreshAuth(array $config): array {
    $auth = getAuth();

    if (empty($auth['refresh_token'])) {
        throw new RuntimeException('В auth.json отсутствует refresh_token. Требуется переустановка приложения.');
    }

    $url = 'https://oauth.bitrix.info/oauth/token/?' . http_build_query([
        'grant_type'    => 'refresh_token',
        'client_id'     => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'refresh_token' => $auth['refresh_token'],
    ]);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Для локальной разработки

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);

    if ($httpCode !== 200 || empty($result['access_token'])) {
        $error = $result['error_description'] ?? $result['error'] ?? 'Неизвестная ошибка';
        throw new RuntimeException("Не удалось обновить токен: {$error}. Ответ: {$response}");
    }

    // Сохраняем новую пару токенов и вычисляем expires (Unix timestamp)
    $newAuth = [
        'access_token'  => $result['access_token'],
        'refresh_token' => $result['refresh_token'],
        'expires'       => time() + (int)($result['expires_in'] ?? 3600),
        'domain'        => $auth['domain'] ?? $result['domain'] ?? null,
        'member_id'     => $result['member_id'] ?? $auth['member_id'] ?? null,
        'client_endpoint' => $result['client_endpoint'] ?? $auth['client_endpoint'] ?? null,
    ];

    saveAuth($newAuth);

    return $newAuth;
}