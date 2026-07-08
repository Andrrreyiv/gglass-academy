<?php
/**
 * Gglass Academy — приём заявок с лендинга.
 * Отправляет заявку на почту клиента и дублирует в локальный лог (152-ФЗ: данные
 * обрабатываются на территории РФ, на российском хостинге).
 *
 * Настройки берутся из окружения хостинга, чтобы не хранить их в репозитории:
 *   LEAD_TO         — e-mail получателя (по умолчанию gglass-detailing@yandex.ru)
 *   LEAD_FROM       — адрес отправителя на домене (напр. no-reply@gglass-academy.ru)
 *   LEAD_RELAY_URL  — HTTP(S)-эндпоинт рассылки (Google Apps Script /exec, вебхук и т.п.).
 *                     Если задан — заявка уходит через него по 443 порту (в обход SMTP,
 *                     который Timeweb режет). mail() используется только как резерв.
 *   LEAD_RELAY_TOKEN — необязательный общий секрет; передаётся в relay и проверяется
 *                      на стороне эндпоинта (защита публичного /exec от спама).
 * Доставка: LEAD_RELAY_URL (HTTP-API, приоритет) -> иначе mail()/SMTP (см. README).
 */

header('Content-Type: application/json; charset=utf-8');

// --- только POST ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// --- анти-спам: honeypot (скрытое поле не должно заполняться) ---
if (!empty($_POST['company'])) {
    // тихо «принимаем», но не обрабатываем
    echo json_encode(['ok' => true]);
    exit;
}

// --- чистка входных данных ---
function clean($v, $max = 200) {
    $v = is_string($v) ? $v : '';
    $v = trim($v);
    $v = str_replace(["\r", "\n"], ' ', $v); // защита от header-injection
    return mb_substr($v, 0, $max);
}

// --- доставка через HTTP-API (443, в обход заблокированного SMTP) ---
// POST-форма на LEAD_RELAY_URL (Google Apps Script /exec или любой вебхук).
// true = приняли (2xx/3xx), false = сеть/эндпоинт недоступны -> сработает резерв mail().
function lead_relay_post($url, array $fields) {
    $body = http_build_query($fields);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,  // GAS /exec отвечает 302 на googleusercontent
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $resp !== false && $code >= 200 && $code < 400;
    }
    // резерв, если cURL не собран в PHP
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => $body,
        'timeout' => 15,
    ]]);
    return @file_get_contents($url, false, $ctx) !== false;
}

$name    = clean($_POST['name']    ?? '', 100);
$phone   = clean($_POST['phone']   ?? '', 30);
$goal    = clean($_POST['goal']    ?? '', 60);
$consent = ($_POST['consent'] ?? '') === '1';
$page    = clean($_POST['page']    ?? '', 300);

// --- валидация ---
$digits = preg_replace('/\D/', '', $phone);
if ($name === '' || strlen($digits) < 11 || $goal === '' || !$consent) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'validation']);
    exit;
}

// --- метаданные заявки ---
$ts = date('Y-m-d H:i:s');
$ip = $_SERVER['REMOTE_ADDR'] ?? '-';

// --- письмо клиенту (используется резервом mail()) ---
$to      = getenv('LEAD_TO')   ?: 'gglass-detailing@yandex.ru';

// ВРЕМЕННО (по просьбе Андрея): дублируем каждую заявку разработчику для контроля.
// УБРАТЬ при переносе на боевой сайт — удалить строки до пометки [/временно]
// или очистить env LEAD_CC_DEV на хостинге.
$ccDev   = getenv('LEAD_CC_DEV') ?: 'brykun123@gmail.com';
if ($ccDev !== '') {
    $to .= ',' . $ccDev; // mail() принимает получателей через запятую
}
// [/временно]

$host    = $_SERVER['HTTP_HOST'] ?? 'gglass-academy.ru';
$from    = getenv('LEAD_FROM')  ?: ('no-reply@' . preg_replace('/^www\./', '', $host));

$subject = '=?UTF-8?B?' . base64_encode('Новая заявка с сайта Gglass') . '?=';

$body =
    "Новая заявка на обучение\n" .
    "------------------------------\n" .
    "Имя:      {$name}\n" .
    "Телефон:  {$phone}\n" .
    "Цель:     {$goal}\n" .
    "------------------------------\n" .
    "Время:    {$ts}\n" .
    "Страница: {$page}\n" .
    "IP:       {$ip}\n" .
    "Согласие на обработку ПДн (152-ФЗ): да\n";

$headers  = "From: Gglass <{$from}>\r\n";
$headers .= "Reply-To: {$from}\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

// --- доставка: HTTP-API relay (приоритет, 443) -> иначе mail() ---
$relayUrl   = getenv('LEAD_RELAY_URL')   ?: '';
$relayToken = getenv('LEAD_RELAY_TOKEN') ?: '';
$sent    = false;
$channel = 'none';

if ($relayUrl !== '') {
    $payload = [
        'name' => $name, 'phone' => $phone, 'goal' => $goal,
        'page' => $page, 'consent' => '1', 'ts' => $ts, 'ip' => $ip,
    ];
    if ($relayToken !== '') { $payload['token'] = $relayToken; }
    $sent    = lead_relay_post($relayUrl, $payload);
    $channel = $sent ? 'relay' : 'relay_failed';
}

if (!$sent) {
    // резерв: локальный mail()/SMTP (может быть заблокирован egress-фильтром хостинга)
    $mailed = @mail($to, $subject, $body, $headers);
    if ($mailed) { $sent = true; $channel = ($channel === 'relay_failed') ? 'relay_failed_mail' : 'mail'; }
}

// --- дублируем в лог (на случай проблем с доставкой) ---
$logDir = __DIR__ . '/leads';
if (!is_dir($logDir)) { @mkdir($logDir, 0750, true); }
$line = json_encode([
    'ts' => $ts, 'name' => $name, 'phone' => $phone, 'goal' => $goal,
    'consent' => true, 'page' => $page, 'ip' => $ip,
    'sent' => (bool)$sent, 'channel' => $channel,
], JSON_UNESCAPED_UNICODE);
@file_put_contents($logDir . '/leads-' . date('Y-m') . '.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);

// --- ответ (заявка всё равно сохранена в лог, поэтому ok=true) ---
echo json_encode(['ok' => true]);
