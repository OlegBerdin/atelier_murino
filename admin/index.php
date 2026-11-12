<?php
/**
 * catalog-basic — админка сайта.
 * Меняет data.json: товары/услуги, фото, контакты.
 */
declare(strict_types=1);

require __DIR__ . '/inc/lib.php';
boot_session();

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store, max-age=0');

$config = read_config();
$action = (string) ($_POST['action'] ?? '');

/* ================= POST-действия ================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();

    switch ($action) {
        case 'setup':
            if ($config !== null) {
                redirect('index.php');
            }
            if (!is_localhost()) {
                flash('err', 'Настройка пароля недоступна. Создайте admin/inc/config.php локально и загрузите на хостинг.');
                redirect('index.php');
            }
            $password = (string) ($_POST['password'] ?? '');
            $repeat = (string) ($_POST['password_repeat'] ?? '');
            if (strlen($password) < 8) {
                flash('err', 'Пароль должен быть не короче 8 символов.');
                redirect('index.php');
            }
            if ($password !== $repeat) {
                flash('err', 'Пароли не совпадают.');
                redirect('index.php');
            }
            if (!write_config(password_hash($password, PASSWORD_DEFAULT))) {
                flash('err', 'Не удалось сохранить пароль: папка admin/inc недоступна для записи.');
                redirect('index.php');
            }
            login_user();
            flash('ok', 'Пароль установлен. Добро пожаловать!');
            redirect('index.php');

        case 'login':
            if ($config === null) {
                redirect('index.php');
            }
            if (login_blocked()) {
                flash('err', 'Слишком много попыток. Подождите 15 минут.');
                redirect('index.php');
            }
            $password = (string) ($_POST['password'] ?? '');
            if (password_verify($password, (string) ($config['password_hash'] ?? ''))) {
                login_success();
                login_user();
                redirect('index.php');
            }
            login_failed();
            usleep(400000);
            flash('err', 'Неверный пароль.');
            redirect('index.php');

        case 'logout':
            $_SESSION = [];
            session_destroy();
            redirect('index.php');
    }

    /* Дальше — только для авторизованных */
    if (!is_logged_in()) {
        redirect('index.php');
    }

    $data = load_data();

    switch ($action) {
        case 'save_product':
            $id = trim((string) ($_POST['id'] ?? ''));
            $name = trim((string) ($_POST['name'] ?? ''));
            $price = trim((string) ($_POST['price'] ?? ''));
            $unit = trim((string) ($_POST['unit'] ?? ''));
            $desc = trim((string) ($_POST['desc'] ?? ''));
            $badge = trim((string) ($_POST['badge'] ?? ''));
            $image = trim((string) ($_POST['current_image'] ?? ''));
            $visible = isset($_POST['visible']);

            $back = 'index.php?tab=products' . ($id !== '' ? '&edit=' . urlencode($id) : '');

            if ($name === '' || $price === '') {
                flash('err', 'Заполните хотя бы название и цену.');
                redirect($back);
            }
            if (text_len($name) > 80 || text_len($desc) > 400 || text_len($price) > 30 || text_len($unit) > 20 || text_len($badge) > 24) {
                flash('err', 'Слишком длинный текст в одном из полей.');
                redirect($back);
            }

            $uploaded = null;
            $uploadError = null;
            if (!empty($_FILES['image']['name'])) {
                [$uploaded, $uploadError] = handle_upload($_FILES['image']);
                if ($uploadError !== null) {
                    flash('err', $uploadError);
                    redirect($back);
                }
            }

            $oldImage = $image;
            if ($uploaded !== null) {
                $image = $uploaded;
            }

            $fields = [
                'name'    => $name,
                'price'   => $price,
                'unit'    => $unit,
                'desc'    => $desc,
                'badge'   => $badge,
                'image'   => $image,
                'visible' => $visible,
            ];

            $index = $id !== '' ? find_item_index($data['items'], $id) : -1;
            if ($index >= 0) {
                $data['items'][$index] = array_merge($data['items'][$index], $fields);
            } else {
                $data['items'][] = array_merge(['id' => new_id()], $fields);
            }

            if (!save_data($data)) {
                flash('err', 'Не удалось сохранить data.json. Проверьте права на файл (644 или 664).');
                redirect($back);
            }
            if ($uploaded !== null && $oldImage !== '' && $oldImage !== $uploaded) {
                delete_upload($oldImage);
            }
            flash('ok', $index >= 0 ? 'Позиция обновлена.' : 'Позиция добавлена.');
            redirect('index.php?tab=products');

        case 'delete_product':
            $id = (string) ($_POST['id'] ?? '');
            $index = find_item_index($data['items'], $id);
            if ($index >= 0) {
                $image = (string) ($data['items'][$index]['image'] ?? '');
                array_splice($data['items'], $index, 1);
                if (save_data($data)) {
                    delete_upload($image);
                    flash('ok', 'Позиция удалена.');
                } else {
                    flash('err', 'Не удалось сохранить изменения.');
                }
            }
            redirect('index.php?tab=products');

        case 'toggle_product':
            $id = (string) ($_POST['id'] ?? '');
            $index = find_item_index($data['items'], $id);
            if ($index >= 0) {
                $data['items'][$index]['visible'] = !($data['items'][$index]['visible'] ?? true);
                save_data($data) ? flash('ok', 'Видимость изменена.') : flash('err', 'Не удалось сохранить изменения.');
            }
            redirect('index.php?tab=products');

        case 'move_product':
            $id = (string) ($_POST['id'] ?? '');
            $dir = ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
            $index = find_item_index($data['items'], $id);
            $target = $index + $dir;
            if ($index >= 0 && isset($data['items'][$target])) {
                $tmp = $data['items'][$index];
                $data['items'][$index] = $data['items'][$target];
                $data['items'][$target] = $tmp;
                save_data($data) ? flash('ok', 'Порядок изменён.') : flash('err', 'Не удалось сохранить изменения.');
            }
            redirect('index.php?tab=products');

        case 'save_site':
            $site = [
                'phone'     => trim((string) ($_POST['phone'] ?? '')),
                'phoneHref' => preg_replace('/[^\d+]/', '', (string) ($_POST['phone'] ?? '')),
                'email'     => trim((string) ($_POST['email'] ?? '')),
                'address'   => trim((string) ($_POST['address'] ?? '')),
                'hours'     => trim((string) ($_POST['hours'] ?? '')),
                'whatsapp'  => preg_replace('/\D/', '', (string) ($_POST['whatsapp'] ?? '')),
            ];
            if (text_len($site['phone']) > 40 || text_len($site['address']) > 200 || text_len($site['hours']) > 80) {
                flash('err', 'Слишком длинный текст в одном из полей.');
                redirect('index.php?tab=contacts');
            }
            $data['site'] = $site;
            save_data($data) ? flash('ok', 'Контакты обновлены.') : flash('err', 'Не удалось сохранить изменения.');
            redirect('index.php?tab=contacts');

        case 'change_password':
            if ($config === null) {
                redirect('index.php');
            }
            $current = (string) ($_POST['current_password'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            $repeat = (string) ($_POST['password_repeat'] ?? '');

            if (!password_verify($current, (string) ($config['password_hash'] ?? ''))) {
                flash('err', 'Текущий пароль указан неверно.');
                redirect('index.php?tab=contacts');
            }
            if (strlen($password) < 8) {
                flash('err', 'Новый пароль должен быть не короче 8 символов.');
                redirect('index.php?tab=contacts');
            }
            if ($password !== $repeat) {
                flash('err', 'Новые пароли не совпадают.');
                redirect('index.php?tab=contacts');
            }
            if (!write_config(password_hash($password, PASSWORD_DEFAULT))) {
                flash('err', 'Не удалось сохранить пароль.');
                redirect('index.php?tab=contacts');
            }
            flash('ok', 'Пароль изменён.');
            redirect('index.php?tab=contacts');
    }

    redirect('index.php');
}

/* ================= Экран входа ================= */

if (!is_logged_in()) {
    render_auth($config);
    exit;
}

/* ================= Данные для страницы ================= */

$data = load_data();
$tab = ($_GET['tab'] ?? 'products') === 'contacts' ? 'contacts' : 'products';
$flash = take_flash();

$editId = (string) ($_GET['edit'] ?? '');
$editItem = null;
if ($editId !== '') {
    foreach ($data['items'] as $item) {
        if ((string) ($item['id'] ?? '') === $editId) {
            $editItem = $item;
            break;
        }
    }
}

$defaultPassword = using_default_password();

/* ================= Разметка ================= */

render_head('Управление сайтом');
?>
<body class="admin">
<header class="topbar">
  <div class="topbar__inner">
    <div class="topbar__brand">
      <span class="dot" aria-hidden="true"></span>
      <span>Управление сайтом</span>
    </div>
    <div class="topbar__actions">
      <a class="link" href="../" target="_blank" rel="noopener">Открыть сайт ↗</a>
      <form method="post" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="logout">
        <button class="link link--button" type="submit">Выйти</button>
      </form>
    </div>
  </div>
</header>

<main class="page">
  <?php if ($defaultPassword): ?>
    <div class="alert alert--warn">
      <strong>Стандартный пароль.</strong> Смените его на вкладке «Контакты» — иначе в админку сможет войти кто угодно.
    </div>
  <?php endif; ?>

  <?php if ($flash): ?>
    <div class="alert alert--<?= $flash['type'] === 'ok' ? 'ok' : 'err' ?>"><?= h($flash['text']) ?></div>
  <?php endif; ?>

  <nav class="tabs" aria-label="Разделы админки">
    <a class="tab<?= $tab === 'products' ? ' is-active' : '' ?>" href="index.php?tab=products">Товары и услуги</a>
    <a class="tab<?= $tab === 'contacts' ? ' is-active' : '' ?>" href="index.php?tab=contacts">Контакты</a>
  </nav>

  <?php if ($tab === 'contacts'): ?>

    <section class="card">
      <h1>Контакты на сайте</h1>
      <p class="muted">Эти данные подставляются в шапку, подвал и кнопку WhatsApp на всех страницах.</p>

      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_site">

        <div class="grid2">
          <label class="field">
            <span>Телефон</span>
            <input type="text" name="phone" value="<?= h($data['site']['phone'] ?? '') ?>" placeholder="+7 (900) 123-45-67" maxlength="40">
          </label>
          <label class="field">
            <span>WhatsApp (цифры)</span>
            <input type="text" name="whatsapp" value="<?= h($data['site']['whatsapp'] ?? '') ?>" placeholder="79001234567" maxlength="15">
          </label>
          <label class="field">
            <span>E-mail</span>
            <input type="email" name="email" value="<?= h($data['site']['email'] ?? '') ?>" placeholder="zakaz@example.ru" maxlength="80">
          </label>
          <label class="field">
            <span>Часы работы</span>
            <input type="text" name="hours" value="<?= h($data['site']['hours'] ?? '') ?>" placeholder="Ежедневно с 9:00 до 20:00" maxlength="80">
          </label>
        </div>

        <label class="field">
          <span>Адрес</span>
          <input type="text" name="address" value="<?= h($data['site']['address'] ?? '') ?>" placeholder="г. Город, ул. Улица, 1, офис 2" maxlength="200">
        </label>

        <button class="btn" type="submit">Сохранить контакты</button>
      </form>
    </section>

    <section class="card">
      <h1>Смена пароля</h1>
      <p class="muted">Пароль нужен только для входа в админку. Логин не требуется.</p>

      <form method="post" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="change_password">

        <div class="grid2">
          <label class="field">
            <span>Текущий пароль</span>
            <input type="password" name="current_password" autocomplete="current-password" required>
          </label>
          <label class="field">
            <span>Новый пароль (от 8 символов)</span>
            <input type="password" name="password" autocomplete="new-password" minlength="8" required>
          </label>
          <label class="field">
            <span>Повторите новый пароль</span>
            <input type="password" name="password_repeat" autocomplete="new-password" minlength="8" required>
          </label>
        </div>

        <button class="btn" type="submit">Сменить пароль</button>
      </form>
    </section>

    <section class="card">
      <h1>Как это работает</h1>
      <ul class="help">
        <li>Изменения сохраняются сразу — обновлять сайт или что-то пересобирать не нужно.</li>
        <li>Скрытые позиции не видны на сайте, но остаются здесь.</li>
        <li>Фото можно загружать с телефона: большие снимки уменьшаются автоматически.</li>
        <li>Резервные копии <code>data.json</code> лежат в <code>admin/inc/backups</code> (последние 20).</li>
        <li>Если фото не загружается — на хостинге нужны права 755 на папку <code>uploads</code>.</li>
      </ul>
    </section>

  <?php elseif ($editItem !== null || $editId === 'new'): ?>

    <?php
      $item = $editItem ?: [
          'id' => '',
          'name' => '',
          'price' => '',
          'unit' => '₽',
          'desc' => '',
          'image' => '',
          'badge' => '',
          'visible' => true,
      ];
      $isNew = !$editItem;
      $imagePath = (string) ($item['image'] ?? '');
    ?>

    <section class="card">
      <div class="card__head">
        <h1><?= $isNew ? 'Новая позиция' : 'Редактирование' ?></h1>
        <a class="link" href="index.php?tab=products">← К списку</a>
      </div>

      <form method="post" class="form" enctype="multipart/form-data"
            action="index.php?tab=products<?= $isNew ? '&edit=new' : '&edit=' . urlencode((string) $item['id']) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_product">
        <input type="hidden" name="id" value="<?= h($item['id'] ?? '') ?>">
        <input type="hidden" name="current_image" value="<?= h($imagePath) ?>">

        <div class="grid2">
          <label class="field">
            <span>Название *</span>
            <input type="text" name="name" value="<?= h($item['name'] ?? '') ?>" required maxlength="80" placeholder="Подгонка по фигуре">
          </label>
          <label class="field">
            <span>Цена *</span>
            <input type="text" name="price" value="<?= h($item['price'] ?? '') ?>" required maxlength="30" placeholder="590">
            <em>Можно писать «от 590» или «по запросу».</em>
          </label>
          <label class="field">
            <span>Единица</span>
            <input type="text" name="unit" value="<?= h($item['unit'] ?? '') ?>" maxlength="20" placeholder="₽/м²">
          </label>
          <label class="field">
            <span>Плашка (необязательно)</span>
            <input type="text" name="badge" value="<?= h($item['badge'] ?? '') ?>" maxlength="24" placeholder="Хит, Новинка">
          </label>
        </div>

        <label class="field">
          <span>Описание</span>
          <textarea name="desc" rows="3" maxlength="400" placeholder="Коротко: для кого, из чего, чем отличается"><?= h($item['desc'] ?? '') ?></textarea>
        </label>

        <div class="field">
          <span>Фото</span>
          <div class="image-pick">
            <img class="preview" src="../<?= h($imagePath !== '' ? $imagePath : 'assets/img/placeholder-1.svg') ?>" alt="">
            <div>
              <input type="file" name="image" accept="image/jpeg,image/png,image/webp">
              <p class="muted small">JPG, PNG или WebP до 6 МБ. Если не выбирать файл — останется текущее фото.</p>
            </div>
          </div>
        </div>

        <label class="switch">
          <input type="checkbox" name="visible" value="1" <?= ($item['visible'] ?? true) ? 'checked' : '' ?>>
          <span>Показывать на сайте</span>
        </label>

        <div class="form__actions">
          <button class="btn" type="submit"><?= $isNew ? 'Добавить позицию' : 'Сохранить изменения' ?></button>
          <a class="btn btn--ghost" href="index.php?tab=products">Отмена</a>
        </div>
      </form>
    </section>

  <?php else: ?>

    <section class="card">
      <div class="card__head">
        <h1>Товары и услуги</h1>
        <a class="btn btn--sm" href="index.php?tab=products&edit=new">+ Добавить позицию</a>
      </div>

      <?php if (!$data['items']): ?>
        <p class="muted">Пока пусто. Нажмите «Добавить позицию».</p>
      <?php else: ?>
        <div class="rows">
          <?php foreach ($data['items'] as $item): ?>
            <?php $visible = ($item['visible'] ?? true) !== false; ?>
            <article class="row<?= $visible ? '' : ' row--off' ?>">
              <img class="thumb" src="../<?= h($item['image'] ?? '') ?>" alt="" loading="lazy">
              <div class="row__main">
                <div class="row__title">
                  <?= h($item['name'] ?? '') ?>
                  <?php if (!empty($item['badge'])): ?><span class="pill"><?= h($item['badge']) ?></span><?php endif; ?>
                  <?php if (!$visible): ?><span class="pill pill--off">Скрыто</span><?php endif; ?>
                </div>
                <div class="row__meta">
                  <strong><?= h($item['price'] ?? '') ?></strong> <?= h($item['unit'] ?? '') ?>
                  <?php if (!empty($item['desc'])): ?><span class="row__desc"><?= h($item['desc']) ?></span><?php endif; ?>
                </div>
              </div>
              <div class="row__actions">
                <a class="btn btn--ghost btn--xs" href="index.php?tab=products&edit=<?= urlencode((string) ($item['id'] ?? '')) ?>">Изменить</a>

                <form method="post" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="move_product">
                  <input type="hidden" name="id" value="<?= h($item['id'] ?? '') ?>">
                  <input type="hidden" name="dir" value="up">
                  <button class="icon-btn" type="submit" title="Выше" aria-label="Переместить выше">↑</button>
                </form>

                <form method="post" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="move_product">
                  <input type="hidden" name="id" value="<?= h($item['id'] ?? '') ?>">
                  <input type="hidden" name="dir" value="down">
                  <button class="icon-btn" type="submit" title="Ниже" aria-label="Переместить ниже">↓</button>
                </form>

                <form method="post" class="inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_product">
                  <input type="hidden" name="id" value="<?= h($item['id'] ?? '') ?>">
                  <button class="icon-btn" type="submit" title="<?= $visible ? 'Скрыть с сайта' : 'Показать на сайте' ?>" aria-label="<?= $visible ? 'Скрыть с сайта' : 'Показать на сайте' ?>">
                    <?= $visible ? '◉' : '○' ?>
                  </button>
                </form>

                <form method="post" class="inline" onsubmit="return confirm('Удалить «<?= h(addslashes((string) ($item['name'] ?? ''))) ?>»? Это действие нельзя отменить.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_product">
                  <input type="hidden" name="id" value="<?= h($item['id'] ?? '') ?>">
                  <button class="icon-btn icon-btn--danger" type="submit" title="Удалить" aria-label="Удалить">✕</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <p class="muted small">
      Позиций: <?= count($data['items']) ?>. Файл данных обновлён: <?= h($data['updated'] ?? '—') ?>.
    </p>

  <?php endif; ?>
</main>

<footer class="admin-footer">
  catalog-basic · <a href="../" target="_blank" rel="noopener">открыть сайт</a>
</footer>
</body>
</html>
<?php

/* ================= Функции разметки ================= */

function render_head(string $title): void
{
    ?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title><?= h($title) ?></title>
  <link rel="stylesheet" href="admin.css">
</head>
    <?php
}

function render_auth(?array $config): void
{
    $flash = take_flash();
    render_head('Вход в админку');
    ?>
<body class="admin admin--auth">
  <main class="auth">
    <div class="auth__logo"><span class="dot" aria-hidden="true"></span> Управление сайтом</div>

    <?php if ($flash): ?>
      <div class="alert alert--<?= $flash['type'] === 'ok' ? 'ok' : 'err' ?>"><?= h($flash['text']) ?></div>
    <?php endif; ?>

    <?php if ($config === null && is_localhost()): ?>
      <form class="card form" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="setup">
        <h1>Первый запуск</h1>
        <p class="muted">Придумайте пароль для входа в админку. Логин не нужен.</p>
        <label class="field">
          <span>Пароль (от 8 символов)</span>
          <input type="password" name="password" minlength="8" autocomplete="new-password" required autofocus>
        </label>
        <label class="field">
          <span>Повторите пароль</span>
          <input type="password" name="password_repeat" minlength="8" autocomplete="new-password" required>
        </label>
        <button class="btn btn--block" type="submit">Создать пароль</button>
      </form>
    <?php elseif ($config === null): ?>
      <div class="card form">
        <h1>Админка не настроена</h1>
        <p class="muted">
          Пароль задаётся один раз. Настройте его локально (на своём компьютере),
          затем загрузите файл <code>admin/inc/config.php</code> на хостинг.
        </p>
      </div>
    <?php else: ?>
      <form class="card form" method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="login">
        <h1>Вход</h1>
        <p class="muted">Введите пароль администратора.</p>
        <label class="field">
          <span>Пароль</span>
          <input type="password" name="password" autocomplete="current-password" required autofocus>
        </label>
        <button class="btn btn--block" type="submit">Войти</button>
      </form>
    <?php endif; ?>

    <p class="auth__back"><a href="../">← Вернуться на сайт</a></p>
  </main>
</body>
</html>
    <?php
}
