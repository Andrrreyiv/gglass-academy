/**
 * Gglass Academy — приёмник заявок для БЕСПЛАТНОГО превью (GitHub Pages).
 * Временное решение, пока сайт не переехал на РФ-хостинг с PHP (там работает api/lead.php).
 *

 Куда идут заявки: только клиенту gglass-detailing@yandex.ru
 *
 * Деплой: script.google.com → новый проект → вставить этот код →
 *   Развернуть → Веб-приложение → Выполнять от имени: Я →
 *   Доступ: Все → Развернуть → скопировать URL вида .../exec →
 *   прислать этот URL, я впишу его в форму сайта.
 * При изменениях кода: ОБЯЗАТЕЛЬНО «Управление развёртываниями» → новая версия.
 */

var LEAD_TO_CLIENT = 'gglass-detailing@yandex.ru';
// Общий секрет с api/lead.php (env LEAD_RELAY_TOKEN). Пусто = проверка выключена.
// Задать одинаковое значение здесь и в LEAD_RELAY_TOKEN на сервере — тогда
// публичный /exec молча отбрасывает чужие POST без верного token (антиспам).
var LEAD_RELAY_TOKEN = '';

function doPost(e) {
  var p = (e && e.parameter) ? e.parameter : {};
  var name  = p.name  || '-';
  var phone = p.phone || '-';
  var goal  = p.goal  || '-';
  var page  = p.page  || '-';

  // антиспам-honeypot (в форме есть скрытое поле company)
  if (p.company) {
    return ok_();
  }

  // проверка общего секрета: если задан LEAD_RELAY_TOKEN — чужой POST молча отбрасываем
  if (LEAD_RELAY_TOKEN && p.token !== LEAD_RELAY_TOKEN) {
    return ok_();
  }

  var now = new Date();
  var msk = new Date(now.getTime() + 3 * 60 * 60000);
  var dateStr = Utilities.formatDate(msk, 'UTC', 'dd.MM.yyyy HH:mm') + ' МСК';

  // тема — RFC 2047 base64 (кириллица в теме иначе ломается)
  var subjectRaw = 'Новая заявка с сайта Gglass';
  var subject = '=?UTF-8?B?' +
    Utilities.base64Encode(subjectRaw, Utilities.Charset.UTF_8) + '?=';

  var html =
    '<div style="font-family:Arial,sans-serif;font-size:15px;line-height:1.6;color:#111">' +
      '<h2 style="margin:0 0 12px">' + ent('Новая заявка на обучение') + '</h2>' +
      '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse">' +
        row_('Имя', name) +
        row_('Телефон', phone) +
        row_('Цель', goal) +
        row_('Время', dateStr) +
        row_('Страница', page) +
      '</table>' +
      '<p style="margin-top:14px;color:#555">' +
        ent('Согласие на обработку персональных данных (152-ФЗ): да') +
      '</p>' +
    '</div>';

  var recipients = LEAD_TO_CLIENT;
  GmailApp.sendEmail(recipients, subject, '', {
    htmlBody: html,
    name: 'Gglass Academy',       // буквальный текст, не unicode-escape
    replyTo: LEAD_TO_CLIENT
  });

  return ok_();
}

function doGet() {
  return HtmlService.createHtmlOutput('Gglass lead endpoint is running.');
}

// Кириллица в HTML-теле → числовые entity, иначе кракозябры
function ent(str) {
  str = String(str);
  var out = '';
  for (var i = 0; i < str.length; i++) {
    var c = str.charCodeAt(i);
    out += c > 127 ? '&#' + c + ';' : str.charAt(i);
  }
  return out;
}

function row_(label, value) {
  return '<tr>' +
    '<td style="padding:4px 14px 4px 0;color:#777;white-space:nowrap">' + ent(label) + '</td>' +
    '<td style="padding:4px 0;font-weight:bold">' + ent(value) + '</td>' +
  '</tr>';
}

function ok_() {
  return ContentService
    .createTextOutput(JSON.stringify({ ok: true }))
    .setMimeType(ContentService.MimeType.JSON);
}
