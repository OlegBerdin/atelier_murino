<?php
/**
 * Приём заявок с формы: письмо на e-mail + резервный лог.
 * Работает на обычном PHP-хостинге (reg.ru и аналоги).
 */
declare(strict_types=1);

/* ====== НАСТРОЙКИ (меняются под клиента) ====== */
$TO        = 'zakaz@stezhok-murino.ru';  // куда приходит заявка
$SITE_NAME = 'Ателье «Стежок»';          // название в теме письма
/* ============================================= */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function respond(bool $ok, string $message = '', string $error = '', int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $message, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, '', 'Метод не поддерживается.', 405);
}

$name     = trim((string) ($_POST['name'] ?? ''));
$phone    = trim((string) ($_POST['phone'] ?? ''));
$product  = trim((string) ($_POST['product'] ?? ''));
$consent  = ($_POST['consent'] ?? '') === '1';
$honeypot = trim((string) ($_POST['company'] ?? '')); // скрытое поле-ловушка для ботов

if ($honeypot !== '') {
    respond(true, 'Спасибо!'); // бот — делаем вид, что всё хорошо
}
if (!$consent) {
    respond(false, '', 'Нужно согласие на обработку персональных данных.', 422);
}
if (strlen($name) < 2 || strlen($name) > 160) {
    respond(false, '', 'Проверьте имя.', 422);
}

$digits = preg_replace('/\D+/', '', $phone);
if (strlen($digits) < 10 || strlen($digits) > 15) {
    respond(false, '', 'Проверьте номер телефона.', 422);
}

/* Антифлуд: не чаще одной заявки в 30 секунд с одной сессии */
session_start();
$now = time();
if (!empty($_SESSION['last_lead']) && $now - (int) $_SESSION['last_lead'] < 30) {
    respond(false, '', 'Слишком часто. Попробуйте через минуту.', 429);
}
$_SESSION['last_lead'] = $now;

/* Защита от подмены заголовков через поля формы */
$clean = static function (string $value): string {
    return str_replace(["\r", "\n", '%0a', '%0d'], ' ', $value);
};
$name    = $clean($name);
$phone   = $clean($phone);
$product = $clean($product);

$subject = 'Заявка с сайта ' . $SITE_NAME . ($product !== '' ? ': ' . $product : '');
$body = "Новая заявка с сайта\n\n"
    . 'Имя: ' . $name . "\n"
    . 'Телефон: ' . $phone . "\n"
    . ($product !== '' ? 'Услуга: ' . $product . "\n" : '')
    . 'Страница: ' . ($_SERVER['HTTP_REFERER'] ?? '—') . "\n"
    . 'Время: ' . date('d.m.Y H:i') . "\n"
    . 'IP: ' . ($_SERVER['REMOTE_ADDR'] ?? '—') . "\n";

$host = preg_replace('/^www\./', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$from = 'no-reply@' . ($host !== '' ? $host : 'localhost');

$headers = [
    'From'         => '=?UTF-8?B?' . base64_encode($SITE_NAME) . '?= <' . $from . '>',
    'Reply-To'     => $from,
    'Content-Type' => 'text/plain; charset=UTF-8',
    'X-Mailer'     => 'PHP/' . phpversion(),
];

$sent = @mail(
    $TO,
    '=?UTF-8?B?' . base64_encode($subject) . '?=',
    $body,
    $headers
);

/* Резервная копия заявки — на случай, если почта не дошла */
$logDir = __DIR__ . '/leads';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0750, true);
}
@file_put_contents(
    $logDir . '/leads.log',
    date('c') . ' | ' . $name . ' | ' . $phone . ' | ' . $product . "\n",
    FILE_APPEND | LOCK_EX
);

if (!$sent) {
    respond(false, '', 'Не удалось отправить письмо. Позвоните нам по телефону.', 500);
}

respond(true, 'Спасибо! Заявка отправлена.');
