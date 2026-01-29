<?php
/**
 * Обработчик форм лендинга
 * Сохраняет заявки в файл, отправляет на email и в Telegram
 */

// Настройки
$config = [
    // Email для уведомлений
    'email' => 'your-email@company.ru',
    'email_subject' => 'Новая заявка с сайта',

    // Telegram Bot (опционально)
    'telegram_bot_token' => '', // Вставьте токен бота
    'telegram_chat_id' => '',   // Вставьте ID чата

    // Файл для сохранения заявок
    'leads_file' => __DIR__ . '/leads.json',

    // Файл логов
    'log_file' => __DIR__ . '/logs.txt',
];

// Заголовки CORS и JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Только POST запросы
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Собираем данные из формы
$data = [
    'id' => uniqid('lead_'),
    'timestamp' => date('Y-m-d H:i:s'),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
    'referer' => $_SERVER['HTTP_REFERER'] ?? 'direct',

    // Основные поля
    'name' => sanitize($_POST['name'] ?? ''),
    'phone' => sanitize($_POST['phone'] ?? ''),
    'email' => sanitize($_POST['email'] ?? ''),

    // Дополнительные поля
    'org_type' => sanitize($_POST['org_type'] ?? ''),
    'object_type' => sanitize($_POST['object_type'] ?? ''),
    'service' => sanitize($_POST['service'] ?? ''),
    'problem' => sanitize($_POST['problem'] ?? ''),
    'address' => sanitize($_POST['address'] ?? ''),
    'call_time' => sanitize($_POST['call_time'] ?? ''),
    'message' => sanitize($_POST['message'] ?? ''),

    // Метаданные
    'source' => sanitize($_POST['source'] ?? 'unknown'),
    'priority' => sanitize($_POST['priority'] ?? 'normal'),
    'promo' => sanitize($_POST['promo'] ?? ''),

    // UTM метки
    'utm_source' => sanitize($_POST['utm_source'] ?? ''),
    'utm_medium' => sanitize($_POST['utm_medium'] ?? ''),
    'utm_campaign' => sanitize($_POST['utm_campaign'] ?? ''),
    'utm_term' => sanitize($_POST['utm_term'] ?? ''),
    'utm_content' => sanitize($_POST['utm_content'] ?? ''),
];

// Валидация телефона
if (empty($data['phone'])) {
    echo json_encode(['success' => false, 'error' => 'Укажите телефон']);
    exit;
}

// Очистка телефона
$data['phone_clean'] = preg_replace('/\D/', '', $data['phone']);

// Сохраняем в файл
saveLead($data, $config['leads_file']);

// Логируем
logMessage("Новая заявка: {$data['id']} - {$data['phone']}", $config['log_file']);

// Отправляем email
$emailSent = sendEmail($data, $config);

// Отправляем в Telegram
$telegramSent = sendTelegram($data, $config);

// Возвращаем успех
echo json_encode([
    'success' => true,
    'message' => 'Заявка успешно отправлена',
    'lead_id' => $data['id'],
    'notifications' => [
        'email' => $emailSent,
        'telegram' => $telegramSent
    ]
]);

// === Функции ===

/**
 * Очистка входных данных
 */
function sanitize($value) {
    if (is_array($value)) {
        return array_map('sanitize', $value);
    }
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

/**
 * Сохранение заявки в JSON файл
 */
function saveLead($data, $file) {
    $leads = [];

    if (file_exists($file)) {
        $content = file_get_contents($file);
        $leads = json_decode($content, true) ?? [];
    }

    $leads[] = $data;

    file_put_contents($file, json_encode($leads, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return true;
}

/**
 * Логирование
 */
function logMessage($message, $file) {
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] {$message}\n";
    file_put_contents($file, $logEntry, FILE_APPEND);
}

/**
 * Отправка email
 */
function sendEmail($data, $config) {
    if (empty($config['email'])) {
        return false;
    }

    // Определяем приоритет
    $priorityLabel = $data['priority'] === 'high' ? '🚨 СРОЧНАЯ ЗАЯВКА' : '📩 Новая заявка';

    // Формируем тело письма
    $body = "
{$priorityLabel}
================================

📅 Дата: {$data['timestamp']}
🆔 ID: {$data['id']}

👤 КОНТАКТ:
• Имя: " . ($data['name'] ?: 'Не указано') . "
• Телефон: {$data['phone']}
• Email: " . ($data['email'] ?: 'Не указан') . "

📋 ДЕТАЛИ:
• Источник: {$data['source']}
• Тип организации: " . ($data['org_type'] ?: 'Не указан') . "
• Тип объекта: " . ($data['object_type'] ?: 'Не указан') . "
• Услуга: " . ($data['service'] ?: 'Не указана') . "
• Проблема: " . ($data['problem'] ?: 'Не указана') . "
• Адрес: " . ($data['address'] ?: 'Не указан') . "
• Удобное время: " . ($data['call_time'] ?: 'Любое') . "
• Промокод: " . ($data['promo'] ?: 'Нет') . "

🔍 UTM:
• Source: " . ($data['utm_source'] ?: '-') . "
• Medium: " . ($data['utm_medium'] ?: '-') . "
• Campaign: " . ($data['utm_campaign'] ?: '-') . "

🌐 ТЕХНИЧЕСКАЯ ИНФОРМАЦИЯ:
• IP: {$data['ip']}
• Referer: {$data['referer']}

================================
Отправлено с лендинга
";

    $headers = [
        'From: noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
        'Reply-To: ' . ($data['email'] ?: $config['email']),
        'Content-Type: text/plain; charset=UTF-8',
        'X-Priority: ' . ($data['priority'] === 'high' ? '1' : '3'),
    ];

    return @mail(
        $config['email'],
        $config['email_subject'] . ' - ' . $data['phone'],
        $body,
        implode("\r\n", $headers)
    );
}

/**
 * Отправка в Telegram
 */
function sendTelegram($data, $config) {
    if (empty($config['telegram_bot_token']) || empty($config['telegram_chat_id'])) {
        return false;
    }

    // Определяем эмодзи приоритета
    $priorityEmoji = $data['priority'] === 'high' ? '🚨' : '📩';

    // Формируем сообщение
    $message = "{$priorityEmoji} *НОВАЯ ЗАЯВКА*\n\n";
    $message .= "📞 *Телефон:* `{$data['phone']}`\n";

    if (!empty($data['name'])) {
        $message .= "👤 *Имя:* {$data['name']}\n";
    }

    if (!empty($data['email'])) {
        $message .= "✉️ *Email:* {$data['email']}\n";
    }

    $message .= "\n";

    if (!empty($data['problem'])) {
        $problems = [
            'no-heat' => 'Нет тепла/отопления',
            'leak' => 'Течь/утечка газа',
            'noise' => 'Шум/вибрация',
            'error' => 'Ошибка на табло',
            'no-start' => 'Котёл не запускается',
            'other' => 'Другое'
        ];
        $problemText = $problems[$data['problem']] ?? $data['problem'];
        $message .= "⚠️ *Проблема:* {$problemText}\n";
    }

    if (!empty($data['service'])) {
        $services = [
            'maintenance' => 'Техобслуживание',
            'repair' => 'Ремонт',
            'license' => 'Лицензирование',
            'full' => 'Полный цикл'
        ];
        $serviceText = $services[$data['service']] ?? $data['service'];
        $message .= "🔧 *Услуга:* {$serviceText}\n";
    }

    if (!empty($data['org_type'])) {
        $orgTypes = [
            'tszh' => 'ТСЖ/УК',
            'enterprise' => 'Предприятие',
            'commercial' => 'БЦ/ТЦ',
            'developer' => 'Застройщик'
        ];
        $orgText = $orgTypes[$data['org_type']] ?? $data['org_type'];
        $message .= "🏢 *Тип:* {$orgText}\n";
    }

    if (!empty($data['address'])) {
        $message .= "📍 *Адрес:* {$data['address']}\n";
    }

    if (!empty($data['promo'])) {
        $message .= "🎁 *Промокод:* {$data['promo']}\n";
    }

    $message .= "\n🕐 {$data['timestamp']}\n";
    $message .= "📱 Источник: {$data['source']}";

    // Отправляем запрос в Telegram API
    $url = "https://api.telegram.org/bot{$config['telegram_bot_token']}/sendMessage";

    $postData = [
        'chat_id' => $config['telegram_chat_id'],
        'text' => $message,
        'parse_mode' => 'Markdown',
        'disable_web_page_preview' => true
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

/**
 * Защита от спама (простая проверка)
 */
function isSpam($data) {
    // Honeypot поле
    if (!empty($_POST['website'])) {
        return true;
    }

    // Проверка на слишком быструю отправку
    session_start();
    $lastSubmit = $_SESSION['last_form_submit'] ?? 0;
    $now = time();

    if ($now - $lastSubmit < 5) { // Минимум 5 секунд между отправками
        return true;
    }

    $_SESSION['last_form_submit'] = $now;

    return false;
}
