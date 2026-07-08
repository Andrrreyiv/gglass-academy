/* =====================================================================
   Gglass Academy — app.js  (vanilla, без зависимостей)
   1. scroll-reveal (IntersectionObserver)
   2. маска телефона +7 XXX XXX-XX-XX
   3. отправка формы заявки -> /api/lead.php (152-ФЗ: чекбокс обязателен)
   4. FAQ-аккордеон (одна открытая деталь за раз)
   ===================================================================== */
(function () {
  "use strict";

  // Помечаем документ: только тогда CSS прячет .gg-reveal (без JS — всё видно).
  document.documentElement.classList.add("gg-js");

  var reduceMotion = window.matchMedia &&
    window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------- 1. SCROLL-REVEAL ------------------------------------ */
  function initReveal() {
    var targets = document.querySelectorAll(
      "main > section .mx-auto, main > footer .mx-auto"
    );
    if (!targets.length) return;

    if (reduceMotion || !("IntersectionObserver" in window)) {
      targets.forEach(function (el) { el.classList.add("gg-reveal", "gg-in"); });
      return;
    }

    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) {
          e.target.classList.add("gg-in");
          io.unobserve(e.target);
        }
      });
    }, { threshold: 0.12, rootMargin: "0px 0px -8% 0px" });

    targets.forEach(function (el) {
      el.classList.add("gg-reveal");
      io.observe(el);
    });
  }

  /* ---------- 2. МАСКА ТЕЛЕФОНА ----------------------------------- */
  function formatPhone(value) {
    var d = value.replace(/\D/g, "");
    if (!d) return "";
    if (d[0] === "8") d = "7" + d.slice(1);
    if (d[0] !== "7") d = "7" + d;
    d = d.slice(0, 11);
    var out = "+7";
    if (d.length > 1) out += " " + d.slice(1, 4);
    if (d.length >= 5) out += " " + d.slice(4, 7);
    if (d.length >= 8) out += "-" + d.slice(7, 9);
    if (d.length >= 10) out += "-" + d.slice(9, 11);
    return out;
  }

  function initPhoneMask(input) {
    if (!input) return;
    input.addEventListener("input", function () {
      var pos = input.selectionStart;
      var before = input.value.length;
      input.value = formatPhone(input.value);
      var after = input.value.length;
      if (pos !== null && after >= before) {
        input.setSelectionRange(pos + (after - before), pos + (after - before));
      }
    });
    input.addEventListener("focus", function () {
      if (!input.value) input.value = "+7 ";
    });
    input.addEventListener("blur", function () {
      if (input.value.replace(/\D/g, "").length <= 1) input.value = "";
    });
  }

  /* ---------- 3. ФОРМА ЗАЯВКИ ------------------------------------- */
  function initForm(form) {
    if (!form) return;
    var statusEl = form.querySelector(".gg-form__status");
    var nameEl = form.querySelector('input[name="name"]');
    var phoneEl = form.querySelector('input[name="phone"]');
    var goalEl = form.querySelector('select[name="goal"]');
    var consentEl = form.querySelector('input[name="consent"]');

    function setStatus(msg, kind) {
      if (!statusEl) return;
      statusEl.textContent = msg || "";
      statusEl.classList.remove("is-error", "is-ok");
      if (kind) statusEl.classList.add(kind);
    }

    function markInvalid(el, bad) {
      if (!el) return;
      el.classList.toggle("is-invalid", !!bad);
    }

    form.addEventListener("submit", function (ev) {
      ev.preventDefault();

      var name = (nameEl.value || "").trim();
      var phoneDigits = (phoneEl.value || "").replace(/\D/g, "");
      var goal = goalEl ? goalEl.value : "";
      var consent = consentEl ? consentEl.checked : false;

      markInvalid(nameEl, !name);
      markInvalid(phoneEl, phoneDigits.length < 11);
      markInvalid(goalEl, !goal);

      if (!name) { setStatus("Пожалуйста, укажите имя.", "is-error"); nameEl.focus(); return; }
      if (phoneDigits.length < 11) { setStatus("Введите телефон полностью: +7 и 10 цифр.", "is-error"); phoneEl.focus(); return; }
      if (!goal) { setStatus("Выберите цель обучения.", "is-error"); goalEl.focus(); return; }
      if (!consent) { setStatus("Нужно согласие на обработку персональных данных.", "is-error"); consentEl.focus(); return; }

      setStatus("Отправляем…", null);
      form.classList.add("is-sending");

      var payload = new FormData();
      payload.append("name", name);
      payload.append("phone", "+" + phoneDigits);
      payload.append("goal", goal);
      payload.append("consent", "1");
      payload.append("page", location.href);

      fetch("api/lead.php", { method: "POST", body: payload })
        .then(function (r) { return r.ok ? r.json().catch(function () { return { ok: true }; }) : Promise.reject(r); })
        .then(function () {
          form.classList.remove("is-sending");
          form.classList.add("is-done");
          if (window.ym) { try { window.ym(109600783, "reachGoal", "lead"); } catch (e) {} }
        })
        .catch(function () {
          form.classList.remove("is-sending");
          setStatus("Не удалось отправить. Позвоните нам: +7 962 943-33-11", "is-error");
        });
    });
  }

  /* ---------- 4. FAQ: одна открытая деталь ------------------------ */
  function initFaq() {
    var items = document.querySelectorAll("#faq details");
    items.forEach(function (d) {
      d.addEventListener("toggle", function () {
        if (!d.open) return;
        items.forEach(function (o) { if (o !== d) o.open = false; });
      });
    });
  }

  /* ---------- init ----------------------------------------------- */
  function boot() {
    initReveal();
    initPhoneMask(document.querySelector('#lead-form input[name="phone"]'));
    initForm(document.getElementById("lead-form"));
    initFaq();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
