<?php
// register_robot.php
$config = require __DIR__ . '/config.php';

$authFile = __DIR__ . '/auth.json';
$handlerUrl = $config['handler_url'];

// 1. Читаем токен из файла, созданного при установке приложения
if (!file_exists($authFile)) {
    die('Файл auth.json не найден. Убедитесь, что приложение было переустановлено, а install_handler.php отработал.');
}

$auth = json_decode(file_get_contents($authFile), true);
$accessToken = $auth['access_token'] ?? null;

if (!$accessToken) {
    die('В файле auth.json отсутствует access_token.');
}

echo "Access token успешно прочитан из auth.json.<br>";

// 2. Регистрируем робота через bizproc.robot.add
// ВАЖНО: Для роботов используется метод bizproc.robot.add, а не activity.add.
$portalDomain = $config['portal_domain']; // ЗАМЕНИТЕ на домен вашего портала!
$registerUrl = "https://{$portalDomain}/rest/bizproc.robot.add.json?auth={$accessToken}";

$robotParams = [
    'CODE'             => 'create_task_in_project',
    'HANDLER'          => $handlerUrl,
    'AUTH_USER_ID'     => 1, // ID администратора
    'USE_SUBSCRIPTION' => 'Y',
    'NAME'             => [
        'ru' => 'Создать задачу в проекте',
        'en' => 'Create task in project',
    ],
    'DESCRIPTION'      => [
        'ru' => 'Создает задачу в указанном проекте (группе) с заданным названием и ответственным.',
        'en' => 'Creates a task in the specified project with a given title and responsible person.',
    ],
    'PROPERTIES'       => [
        'ProjectID' => [
            'Name'     => ['ru' => 'ID проекта (группы)'],
            'Type'     => 'string',
            'Required' => 'Y',
        ],
        'TaskTitle' => [
            'Name'     => ['ru' => 'Название задачи'],
            'Type'     => 'string',
            'Required' => 'Y',
        ],
        'TaskDescription' => [
            'Name'     => ['ru' => 'Описание задачи'],
            'Type'     => 'text',
            'Required' => 'N',
        ],
        'ResponsibleID' => [
            'Name'     => ['ru' => 'ID ответственного'],
            'Type'     => 'user',
            'Required' => 'Y',
        ],
    ],
    'RETURN_PROPERTIES' => [
        'TaskID' => [
            'Name' => ['ru' => 'ID созданной задачи'],
            'Type' => 'int',
        ],
    ],
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $registerUrl);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($robotParams));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$registerResponse = curl_exec($ch);
$registerHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP код ответа: {$registerHttpCode}<br>";
echo "Ответ от Битрикс24: <pre>" . htmlspecialchars($registerResponse) . "</pre>";