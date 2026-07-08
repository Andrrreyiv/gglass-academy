<?php
/**
 * Gglass Academy — приём заявок с лендинга.
 * Отправляет заявку на почту клиента и дублирует в локальный лог (152-ФЗ: данные
 * обрабатываются на территории РФ, на российском хостинге).
 *
 * Настройки берутся из окружения хостинга, чтобы не хранить их в репозитории:
 *   LEAD_TO   — e-mail получателя (по умолчанию gglass-detailing@yandex.ru)
 *   LEAD_FROM — адрес отправителя на домене (напр. no-reply@gglass-academy.ru)
 * Для доставки через Яндекс рекомендуется SMTP (см. README, раздел «Phase 2»).
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

// --- письмо клиенту ---
$to      = getenv('LEAD_TO')   ?: 'gglass-detailing@yandex.ru';
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

$sent = @mail($to, $subject, $body, $headers);

// --- дублируем в лог (на случай проблем с почтой) ---
$logDir = __DIR__ . '/leads';
if (!is_dir($logDir)) { @mkdir($logDir, 0750, true); }
$line = json_encode([
    'ts' => $ts, 'name' => $name, 'phone' => $phone, 'goal' => $goal,
    'consent' => true, 'page' => $page, 'ip' => $ip, 'mail_sent' => (bool)$sent,
], JSON_UNESCAPED_UNICODE);
@file_put_contents($logDir . '/leads-' . date('Y-m') . '.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);

// --- ответ ---
echo json_encode(['ok' => true]);
