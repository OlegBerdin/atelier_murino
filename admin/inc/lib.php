<?php
/**
 * catalog-basic — общие функции админки.
 * PHP 7.4+ (на reg.ru обычно 8.x).
 */
declare(strict_types=1);

const SITE_ROOT   = __DIR__ . '/../..';
const DATA_FILE   = SITE_ROOT . '/data.json';
const CONFIG_FILE = __DIR__ . '/config.php';
const BACKUP_DIR  = __DIR__ . '/backups';
const UPLOAD_DIR  = SITE_ROOT . '/uploads';
const MAX_UPLOAD  = 6 * 1024 * 1024; // 6 МБ
const MAX_IMAGE_W = 1600;
const BACKUPS_KEEP = 20;
const LOGIN_LIMIT = 5;
const LOGIN_WINDOW = 900; // 15 минут
const SESSION_TTL = 43200; // 12 часов

/* ---------------- Базовое ---------------- */

function boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);
    session_start();
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Длина текста в символах. mbstring на shared-хостинге обычно есть,
 * но код не должен падать, если расширение отключено.
 */
function text_len(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function redirect(string $url): void
{
    header('Location: ' . $url, true, 303);
    exit;
}

function is_localhost(): bool
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host);
    return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
}

/* ---------------- CSRF ---------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function check_csrf(): void
{
    $sent = (string) ($_POST['_csrf'] ?? '');
    $real = (string) ($_SESSION['csrf'] ?? '');
    if ($real === '' || $sent === '' || !hash_equals($real, $sent)) {
        http_response_code(400);
        exit('Ошибка проверки формы (CSRF). Обновите страницу и попробуйте снова.');
    }
}

/* ---------------- Flash-сообщения ---------------- */

function flash(string $type, string $text): void
{
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

function take_flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/* ---------------- Авторизация ---------------- */

function read_config(): ?array
{
    if (!is_file(CONFIG_FILE)) {
        return null;
    }
    $config = include CONFIG_FILE;
    return is_array($config) ? $config : null;
}

function write_config(string $hash): bool
{
    $code = "<?php\n// Хеш пароля администратора. Не выкладывайте этот файл в открытый доступ.\nreturn "
        . var_export(['password_hash' => $hash, 'created' => date('c')], true) . ";\n";
    return file_put_contents(CONFIG_FILE, $code, LOCK_EX) !== false;
}

function is_logged_in(): bool
{
    if (empty($_SESSION['admin_logged'])) {
        return false;
    }
    $since = (int) ($_SESSION['admin_time'] ?? 0);
    if ($since === 0 || time() - $since > SESSION_TTL) {
        $_SESSION['admin_logged'] = false;
        return false;
    }
    return true;
}

function login_user(): void
{
    session_regenerate_id(true);
    $_SESSION['admin_logged'] = true;
    $_SESSION['admin_time'] = time();
}

function using_default_password(): bool
{
    $config = read_config();
    return $config !== null && password_verify('admin12345', (string) ($config['password_hash'] ?? ''));
}

/* ---------------- Антибрутфорс ---------------- */

function attempts_file(): string
{
    return __DIR__ . '/.attempts.json';
}

function attempts_read(): array
{
    if (!is_file(attempts_file())) {
        return [];
    }
    $data = json_decode((string) @file_get_contents(attempts_file()), true);
    return is_array($data) ? $data : [];
}

function attempts_write(array $data): void
{
    @file_put_contents(attempts_file(), json_encode($data), LOCK_EX);
}

function throttle_key(): string
{
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function login_blocked(): bool
{
    $key = throttle_key();
    $data = attempts_read();
    $entry = $data[$key] ?? null;
    if (!$entry) {
        return false;
    }
    if (time() - (int) $entry['first'] > LOGIN_WINDOW) {
        unset($data[$key]);
        attempts_write($data);
        return false;
    }
    return (int) $entry['count'] >= LOGIN_LIMIT;
}

function login_failed(): void
{
    $key = throttle_key();
    $data = attempts_read();
    $now = time();
    if (empty($data[$key]) || $now - (int) $data[$key]['first'] > LOGIN_WINDOW) {
        $data[$key] = ['first' => $now, 'count' => 1];
    } else {
        $data[$key]['count'] = (int) $data[$key]['count'] + 1;
    }
    attempts_write($data);
}

function login_success(): void
{
    $key = throttle_key();
    $data = attempts_read();
    unset($data[$key]);
    attempts_write($data);
}

/* ---------------- Данные ---------------- */

function load_data(): array
{
    if (!is_file(DATA_FILE)) {
        return ['site' => [], 'items' => []];
    }
    $raw = (string) @file_get_contents(DATA_FILE);
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = [];
    }
    if (empty($data['site']) || !is_array($data['site'])) {
        $data['site'] = [];
    }
    if (empty($data['items']) || !is_array($data['items'])) {
        $data['items'] = [];
    }
    return $data;
}

function backup_data(): void
{
    if (!is_file(DATA_FILE)) {
        return;
    }
    if (!is_dir(BACKUP_DIR)) {
        @mkdir(BACKUP_DIR, 0750, true);
    }
    @copy(DATA_FILE, BACKUP_DIR . '/data-' . date('Ymd-His') . '.json');

    $files = glob(BACKUP_DIR . '/data-*.json') ?: [];
    sort($files);
    while (count($files) > BACKUPS_KEEP) {
        @unlink((string) array_shift($files));
    }
}

function save_data(array $data): bool
{
    $data['updated'] = date('Y-m-d');
    $items = [];
    foreach ($data['items'] as $item) {
        if (is_array($item) && !empty($item['name'])) {
            $items[] = $item;
        }
    }
    $data['items'] = $items;

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    backup_data();

    $ok = @file_put_contents(DATA_FILE, $json . "\n", LOCK_EX);
    if ($ok === false) {
        return false;
    }
    @chmod(DATA_FILE, 0644);
    return true;
}

function find_item_index(array $items, string $id): int
{
    foreach ($items as $index => $item) {
        if ((string) ($item['id'] ?? '') === $id) {
            return (int) $index;
        }
    }
    return -1;
}

function new_id(): string
{
    return 'item-' . bin2hex(random_bytes(4));
}

/* ---------------- Загрузка фото ---------------- */

/**
 * @return array{0: ?string, 1: ?string} [путь от корня сайта, текст ошибки]
 */
function handle_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return [null, 'Не удалось загрузить фото. Попробуйте ещё раз.'];
    }
    if (($file['size'] ?? 0) > MAX_UPLOAD) {
        return [null, 'Фото больше 6 МБ. Сожмите его или выберите другое.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return [null, 'Файл не загружен.'];
    }

    $info = @getimagesize($tmp);
    if ($info === false) {
        return [null, 'Это не изображение. Нужен JPG, PNG или WebP.'];
    }

    $types = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];
    $ext = $types[$info[2]] ?? null;
    if ($ext === null) {
        return [null, 'Поддерживаются только JPG, PNG и WebP.'];
    }

    if (!is_dir(UPLOAD_DIR) && !@mkdir(UPLOAD_DIR, 0755, true)) {
        return [null, 'Нет папки uploads. Создайте её на хостинге (права 755).'];
    }
    if (!is_writable(UPLOAD_DIR)) {
        return [null, 'Папка uploads недоступна для записи. Поставьте права 755.'];
    }

    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    $target = UPLOAD_DIR . '/' . $name;

    $resized = resize_image($tmp, $target, $info[2], (int) $info[0], (int) $info[1]);
    if (!$resized && !@move_uploaded_file($tmp, $target)) {
        return [null, 'Не удалось сохранить фото на сервере.'];
    }
    @chmod($target, 0644);

    return ['uploads/' . $name, null];
}

function resize_image(string $src, string $dst, int $type, int $width, int $height): bool
{
    if ($width <= MAX_IMAGE_W || !function_exists('imagecreatetruecolor')) {
        return false; // уменьшать не нужно (или нет GD) — сохраним как есть
    }

    $newW = MAX_IMAGE_W;
    $newH = (int) round($height * MAX_IMAGE_W / $width);

    switch ($type) {
        case IMAGETYPE_JPEG:
            $image = @imagecreatefromjpeg($src);
            break;
        case IMAGETYPE_PNG:
            $image = @imagecreatefrompng($src);
            break;
        case IMAGETYPE_WEBP:
            $image = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false;
            break;
        default:
            $image = false;
    }
    if (!$image) {
        return false;
    }

    $canvas = imagecreatetruecolor($newW, $newH);
    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $newW, $newH, $transparent);
    }
    imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);

    switch ($type) {
        case IMAGETYPE_JPEG:
            $ok = imagejpeg($canvas, $dst, 82);
            break;
        case IMAGETYPE_PNG:
            $ok = imagepng($canvas, $dst, 7);
            break;
        default:
            $ok = imagewebp($canvas, $dst, 82);
    }

    imagedestroy($image);
    imagedestroy($canvas);
    return (bool) $ok;
}

function delete_upload(string $path): void
{
    if (strpos($path, 'uploads/') !== 0) {
        return; // шаблонные картинки из assets/ не трогаем
    }
    $full = SITE_ROOT . '/' . $path;
    $real = realpath($full);
    $base = realpath(UPLOAD_DIR);
    if ($real === false || $base === false) {
        return;
    }
    if (strpos($real, $base) === 0 && is_file($real)) {
        @unlink($real);
    }
}
