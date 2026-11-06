<?php
// robot_handler.php

require_once __DIR__ . '/token_manager.php';

$config = require __DIR__ . '/config.php';
// 1. Получаем данные от Битрикс24
$payload = $_POST;

// Для отладки можно записывать входящие данные в файл
//file_put_contents('robot_debug.log', print_r($payload, true), FILE_APPEND);

// 2. Извлекаем необходимые данные
$eventToken   = $payload['event_token'] ?? null;
$auth         = $payload['auth'] ?? [];
$properties   = $payload['properties'] ?? [];

// Данные для авторизации, которые присылает сам Битрикс24
//$accessToken  = $auth['access_token'] ?? null;
$domain       = $auth['domain'] ?? null;

// Входные параметры, которые мы задали при регистрации
$projectId    = $properties['ProjectID'] ?? null;
$taskTitle    = $properties['TaskTitle'] ?? 'Задача из бизнес-процесса';
$taskDesc     = $properties['TaskDescription'] ?? '';

// --- Начало исправления для ответственного ---
$rawResponsible = $properties['ResponsibleID'] ?? null;

if (is_string($rawResponsible) && preg_match('/^user_/usi', $rawResponsible, $out)) {
    $rawResponsible = preg_replace('/^user_/usi', '', $rawResponsible);
}

// Если пришёл массив (некоторые типы полей могут возвращать массив), берём первое значение
if (is_array($rawResponsible)) {
    $rawResponsible = reset($rawResponsible);
}
$responsibleId = (int) $rawResponsible;
// Если ID всё ещё пустой (0), берём ID пользователя, от имени которого запущен робот
if ($responsibleId <= 0) {
    $responsibleId = (int) ($auth['user_id'] ?? 1);
}
// --- Конец исправления ---

// Проверка, что пришли все обязательные данные
if (!$eventToken || !$domain) {
    http_response_code(400);
    echo json_encode(['error' => 'Не получены обязательные данные авторизации.']);
    exit;
}

// 3. Формируем поля для создания задачи (метод tasks.task.add)
$taskFields = [
    'TITLE'          => $taskTitle,
    'DESCRIPTION'    => $taskDesc,
    'RESPONSIBLE_ID' => (int)$responsibleId,
];

// Если указан ID проекта, привязываем задачу к нему
if (!empty($projectId)) {
    $taskFields['GROUP_ID'] = (int)$projectId;
}

/**
 * Вызывает REST-метод с автоматическим обновлением токена при 401.
 */
function callRestWithRefresh(string $method, array $params, string $domain, array $config): array {
    $auth = getAuth(); // Всегда берём актуальный токен из auth.json
    $url = "https://{$domain}/rest/{$method}.json?auth={$auth['access_token']}";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);

    // Если токен истёк — обновляем и повторяем запрос один раз
    if ($httpCode === 401 || (isset($result['error']) && $result['error'] === 'expired_token')) {
        $newAuth = refreshAuth($config);
        $url = "https://{$domain}/rest/{$method}.json?auth={$newAuth['access_token']}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $result = json_decode($response, true);
    }

    return ['httpCode' => $httpCode, 'result' => $result];
}

// --- Создание задачи ---
$taskResponse = callRestWithRefresh('tasks.task.add', ['fields' => $taskFields], $domain, $config);

if ($taskResponse['httpCode'] === 200 && isset($taskResponse['result']['result']['task']['id'])) {
    $taskId = $taskResponse['result']['result']['task']['id'];

    // --- Возврат результата в бизнес-процесс ---
    callRestWithRefresh('bizproc.event.send', [
        'EVENT_TOKEN'   => $eventToken,
        'RETURN_VALUES' => ['TaskID' => $taskId],
        'LOG_MESSAGE'   => "Успешно создана задача №{$taskId} в проекте {$projectId}.",
    ], $domain, $config);

    header('Content-Type: application/json');
    echo json_encode(['result' => true, 'taskId' => $taskId]);
} else {
    $errorMessage = $taskResponse['result']['error_description']
        ?? $taskResponse['result']['error']
        ?? 'Неизвестная ошибка при создании задачи.';

    // Логируем ошибку в бизнес-процесс
    callRestWithRefresh('bizproc.event.send', [
        'EVENT_TOKEN'   => $eventToken,
        'RETURN_VALUES' => [],
        'LOG_MESSAGE'   => "Ошибка создания задачи: {$errorMessage}",
    ], $domain, $config);

    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['result' => false, 'error' => $errorMessage]);
}