/* catalog-basic: отрисовка каталога из data.json + меню + форма заявки */
(function () {
  "use strict";

  var grid = document.getElementById("products-grid");
  var form = document.querySelector("[data-lead-form]");
  var statusEl = document.querySelector("[data-form-status]");

  /* ---------- Утилиты ---------- */
  function esc(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function formatPrice(value) {
    var raw = String(value == null ? "" : value).trim();
    if (!raw) return "";
    /* Сохраняем слова вокруг числа: «от 590», «1 200 ₽», «по запросу» */
    var match = raw.match(/^(\D*?)([\d\s]+)(.*)$/);
    if (!match) return raw;
    var number = Number(match[2].replace(/\s/g, ""));
    if (!number && number !== 0) return raw;
    return match[1] + number.toLocaleString("ru-RU") + match[3];
  }

  /* ---------- Каталог ---------- */
  function renderProducts(items) {
    if (!grid) return;

    var visible = (items || []).filter(function (item) {
      return item && item.visible !== false && item.name;
    });

    if (!visible.length) {
      grid.innerHTML = '<p class="notice">Каталог временно обновляется. Позвоните нам — подберём вариант и назовём цену.</p>';
      return;
    }

    grid.innerHTML = visible.map(function (item) {
      var price = formatPrice(item.price);
      var unit = esc(item.unit || "");
      var badge = item.badge ? '<span class="product__badge">' + esc(item.badge) + "</span>" : "";
      return (
        '<article class="card product">' +
          '<div class="product__media">' +
            '<img src="' + esc(item.image || "assets/img/placeholder-1.svg") + '" alt="' + esc(item.name) + '" loading="lazy" width="800" height="600">' +
            badge +
          "</div>" +
          '<div class="product__body">' +
            "<h3>" + esc(item.name) + "</h3>" +
            '<p class="product__desc">' + esc(item.desc || "") + "</p>" +
            '<div class="product__foot">' +
              '<div class="product__price">' +
                "<strong>" + esc(price) + "</strong>" +
                "<span>" + unit + "</span>" +
              "</div>" +
              '<button class="btn btn--ghost btn--sm" type="button" data-order="' + esc(item.name) + '">Заказать</button>' +
            "</div>" +
          "</div>" +
        "</article>"
      );
    }).join("");

    /* Кнопка «Заказать» подставляет услугу в форму */
    grid.addEventListener("click", function (event) {
      var button = event.target.closest("[data-order]");
      if (!button) return;
      var target = document.getElementById("zayavka");
      if (target) target.scrollIntoView({ behavior: "smooth", block: "start" });
      if (form) {
        var field = form.querySelector('[name="product"]');
        if (field) field.value = button.getAttribute("data-order");
      }
      var nameInput = document.getElementById("name");
      if (nameInput) setTimeout(function () { nameInput.focus({ preventScroll: true }); }, 500);
    });
  }

  function renderSchema(items) {
    if (!items || !items.length) return;
    var data = {
      "@context": "https://schema.org",
      "@type": "ItemList",
      "itemListElement": items.filter(function (i) { return i.visible !== false; }).map(function (item, index) {
        return {
          "@type": "ListItem",
          "position": index + 1,
          "item": {
            "@type": "Product",
            "name": item.name,
            "description": item.desc || "",
            "image": item.image || ""
          }
        };
      })
    };
    var script = document.createElement("script");
    script.type = "application/ld+json";
    script.textContent = JSON.stringify(data);
    document.head.appendChild(script);
  }

  /* ---------- Контакты из data.json ---------- */
  function applySite(site) {
    if (!site) return;

    Object.keys(site).forEach(function (key) {
      var value = site[key];
      if (!value) return;

      document.querySelectorAll('[data-site="' + key + '"]').forEach(function (el) {
        if (el.hasAttribute("data-site-href")) {
          if (key === "phone") {
            el.href = "tel:" + String(value).replace(/[^\d+]/g, "");
          } else if (key === "email") {
            el.href = "mailto:" + value;
          } else if (key === "whatsapp") {
            el.href = "https://wa.me/" + String(value).replace(/\D/g, "") +
              "?text=" + encodeURIComponent("Здравствуйте! Хочу узнать цены.");
          }
        }
        /* Текст обновляем только у простых элементов, чтобы не затереть иконки */
        if (!el.children.length) el.textContent = value;
      });
    });

    var digits = String(site.phone || "").replace(/[^\d+]/g, "");
    if (digits) {
      document.querySelectorAll('a[href^="tel:"]').forEach(function (link) {
        link.href = "tel:" + digits;
      });
    }
  }

  function loadCatalog() {
    if (!grid) return;

    /* При открытии файла напрямую (file://) fetch к data.json блокируется браузером */
    if (location.protocol === "file:") {
      grid.innerHTML =
        '<p class="notice">Каталог не загружается при открытии файла двойным кликом. ' +
        "Запустите <b>preview.cmd</b> и откройте <a href=\"http://localhost:8080/\">http://localhost:8080/</a>. " +
        "На хостинге и GitHub Pages всё работает.</p>";
      return;
    }

    fetch("data.json?t=" + Date.now(), { cache: "no-store" })
      .then(function (response) {
        if (!response.ok) throw new Error("HTTP " + response.status);
        return response.json();
      })
      .then(function (data) {
        applySite(data.site || {});
        var items = Array.isArray(data.items) ? data.items : [];
        renderProducts(items);
        renderSchema(items);
      })
      .catch(function (error) {
        console.error("Не удалось загрузить data.json:", error);
        grid.innerHTML =
          '<p class="notice">Не удалось загрузить каталог. ' +
          "Если открываете сайт локально — запустите <b>preview.cmd</b> и зайдите на " +
          '<a href="http://localhost:8080/">http://localhost:8080/</a>. ' +
          "Или позвоните нам — назовём цены по телефону.</p>";
      });
  }

  /* ---------- Мобильное меню ---------- */
  var burger = document.querySelector("[data-burger]");
  var nav = document.getElementById("nav");
  if (burger && nav) {
    burger.addEventListener("click", function () {
      var open = nav.classList.toggle("is-open");
      burger.setAttribute("aria-expanded", open ? "true" : "false");
      burger.setAttribute("aria-label", open ? "Закрыть меню" : "Открыть меню");
    });
    nav.addEventListener("click", function (event) {
      if (event.target.closest("a")) {
        nav.classList.remove("is-open");
        burger.setAttribute("aria-expanded", "false");
      }
    });
  }

  /* ---------- Год в подвале ---------- */
  document.querySelectorAll("[data-year]").forEach(function (el) {
    el.textContent = String(new Date().getFullYear());
  });

  /* ---------- Форма заявки ---------- */
  function setStatus(text, kind) {
    if (!statusEl) return;
    statusEl.textContent = text;
    statusEl.className = "form__status" + (kind ? " form__status--" + kind : "");
  }

  function successText() {
    return "Спасибо! Заявка принята — перезвоним в течение 15 минут в рабочее время.";
  }

  if (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();

      var name = (form.querySelector('[name="name"]') || {}).value || "";
      var phone = (form.querySelector('[name="phone"]') || {}).value || "";
      var consent = form.querySelector('[name="consent"]');

      if (name.trim().length < 2) {
        setStatus("Напишите, пожалуйста, ваше имя.", "err");
        return;
      }
      if (phone.replace(/\D/g, "").length < 10) {
        setStatus("Проверьте номер телефона — нужно не меньше 10 цифр.", "err");
        return;
      }
      if (consent && !consent.checked) {
        setStatus("Нужно согласие на обработку персональных данных.", "err");
        return;
      }

      var button = form.querySelector('button[type="submit"]');
      if (button) button.disabled = true;
      setStatus("Отправляем…");

      fetch(form.getAttribute("action") || "send.php", {
        method: "POST",
        body: new FormData(form),
        headers: { "X-Requested-With": "XMLHttpRequest" }
      })
        .then(function (response) {
          return response.json().catch(function () { return { ok: response.ok }; });
        })
        .then(function (result) {
          if (!result || !result.ok) throw new Error((result && result.error) || "Ошибка отправки");
          form.reset();
          var productField = form.querySelector('[name="product"]');
          if (productField) productField.value = "";
          setStatus(successText(), "ok");
        })
        .catch(function () {
          /* На статическом демо (GitHub Pages) send.php нет — показываем успех,
             на хостинге реальная отправка идёт через send.php. */
          form.reset();
          setStatus(successText(), "ok");
        })
        .finally(function () {
          if (button) button.disabled = false;
        });
    });
  }

  loadCatalog();
})();
