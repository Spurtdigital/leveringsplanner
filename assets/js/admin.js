jQuery(function ($) {
  var $closedDates = $('#klp_closed_dates_field');
  var $closedList = $('#klp_closed_dates_list');
  var $calGrid = $('#klp-cal-grid');
  var $calTitle = $('#klp-cal-title');
  var $calFeedback = $('#klp-cal-feedback');
  var $calNav = $('#klp-cal-nav');
  var $calSection = $('#klp-admin-calendar');

  var calYear = new Date().getFullYear();
  var calMonth = new Date().getMonth() + 1;
  var isYearView = true;
  var closedDatesArray = [];

  var MONTH_NAMES_SHORT = ['Jan', 'Feb', 'Mrt', 'Apr', 'Mei', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];

  // Tab switching
  $('.klp-tab-nav a').on('click', function (e) {
    e.preventDefault();
    var target = $(this).attr('href');
    $('.klp-tab-nav a').removeClass('klp-tab-active');
    $(this).addClass('klp-tab-active');
    $('.klp-tab-panel').removeClass('klp-panel-active');
    $(target).addClass('klp-panel-active');
    if (target === '#klp-tab-calendar') renderView();
  });

  function parseClosedDates() {
    var raw = $closedDates.val();
    closedDatesArray = raw ? raw.split('\n').map(function (l) { return l.trim(); }).filter(Boolean) : [];
  }

  function saveClosedDates() {
    var val = closedDatesArray.join('\n');
    $closedDates.val(val);
    renderDatesList();

    $.post(klp_admin.ajax_url, {
      action: 'klp_save_closed_dates',
      dates: val,
      nonce: klp_admin.nonce
    });

    $calFeedback.text('Opgeslagen').css('color', '#46b450');
    clearTimeout(window.klpSaveTimer);
    window.klpSaveTimer = setTimeout(function () {
      $calFeedback.text('').css('color', '');
    }, 1500);

    if ($('#klp-tab-calendar').hasClass('klp-panel-active')) renderView();
  }

  function renderDatesList() {
    $closedList.empty();
    if (!closedDatesArray.length) {
      $closedList.append('<p class="description" style="margin:4px 0;">Geen extra sluitingsdagen geselecteerd.</p>');
      return;
    }
    $.each(closedDatesArray, function (i, line) {
      var $row = $('<div class="klp-date-row">');
      var $input = $('<input type="text" class="klp-date-input" value="' + line + '" readonly>');
      var $rm = $('<button type="button" class="button klp-remove-date" title="Verwijderen">&times;</button>');
      $rm.on('click', function () {
        closedDatesArray = closedDatesArray.filter(function (l) { return l !== line; });
        saveClosedDates();
      });
      $row.append($input).append($rm);
      $closedList.append($row);
    });
  }

  function ymdToDmy(ymd) {
    var parts = ymd.split('-');
    return parts[2] + '-' + parts[1] + '-' + parts[0];
  }

  function isUserClosed(ymd) {
    var dmy = ymdToDmy(ymd);
    return closedDatesArray.indexOf(dmy) >= 0;
  }

  function toggleClosedDate(ymd) {
    var dmy = ymdToDmy(ymd);
    var idx = closedDatesArray.indexOf(dmy);
    if (idx >= 0) {
      closedDatesArray.splice(idx, 1);
      $calFeedback.text(ymd + ' verwijderd als sluitingsdag').css('color', '#666');
    } else {
      closedDatesArray.push(dmy);
      $calFeedback.text(ymd + ' gemarkeerd als sluitingsdag').css('color', '#b32d2e');
    }
    saveClosedDates();
  }

  function renderView() {
    if (isYearView) loadYear();
    else loadMonth();
  }

  // ─── YEAR VIEW ──────────────────────────────────────────────

  function loadYear() {
    parseClosedDates();
    $.post(ajaxurl, {
      action: 'klp_get_calendar',
      year: calYear,
      mode: 'year'
    }, function (r) {
      if (!r.success) return;
      $calGrid.empty();
      $calGrid.addClass('klp-cal-year');
      buildYearNav();
      $('#klp-cal-title').text(calYear);

      var $grid = $('<div class="klp-year-grid">');
      var row, q = 0;

      $.each(r.data.months, function (i, m) {
        if (q % 4 === 0) {
          row = $('<div class="klp-year-row">');
          $grid.append(row);
        }
        row.append(buildMiniMonth(m));
        q++;
      });

      $calGrid.append($grid);
    });
  }

  function buildMiniMonth(m) {
    var $block = $('<div class="klp-mini-month">');
    var firstDay = new Date(m.year || calYear, m.month - 1, 1).getDay();
    var isCurrent = m.month === new Date().getMonth() + 1 && calYear === new Date().getFullYear();

    $block.append('<div class="klp-mini-title' + (isCurrent ? ' klp-mini-current' : '') + '">' + m.month_name + '</div>');

    var $dayRow = $('<div class="klp-mini-days">');
    var days = ['M', 'D', 'W', 'D', 'V', 'Z', 'Z'];
    $.each(days, function (i, d) { $dayRow.append('<span class="klp-mini-dname">' + d + '</span>'); });
    $block.append($dayRow);

    var $grid = $('<div class="klp-mini-grid">');
    for (var i = 0; i < ((firstDay + 6) % 7); i++) {
      $grid.append('<span class="klp-mini-empty"></span>');
    }

    $.each(m.dates, function (i, d) {
      var cls = 'klp-mini-dot klp-mini-' + d.status;
      var $dot = $('<span class="' + cls + '" title="' + d.ymd + ' - ' + d.reason + '"></span>');
      $dot.on('mouseenter', function () {
        $calFeedback.text(d.ymd + ' - ' + d.reason);
      });
      $grid.on('mouseleave', function () {
        $calFeedback.text('');
      });
      $grid.append($dot);
    });

    $block.append($grid);
    $block.on('click', function () {
      calMonth = m.month;
      isYearView = false;
      loadMonth();
    });

    return $block;
  }

  // ─── MONTH VIEW ─────────────────────────────────────────────

  function loadMonth() {
    parseClosedDates();
    $.post(ajaxurl, {
      action: 'klp_get_calendar',
      year: calYear,
      month: calMonth
    }, function (r) {
      if (!r.success) return;
      $calGrid.empty();
      $calGrid.removeClass('klp-cal-year');
      buildMonthNav();
      $('#klp-cal-title').text(r.data.month_name + ' ' + r.data.year);

      var today = new Date();
      var todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');

      var dayNames = ['Ma', 'Di', 'Wo', 'Do', 'Vr', 'Za', 'Zo'];
      var $header = $('<div class="klp-cal-row klp-cal-header">');
      $header.append('<div class="klp-cal-cell klp-cal-wnum">#</div>');
      $.each(dayNames, function (i, name) {
        $header.append('<div class="klp-cal-cell klp-cal-dayname">' + name + '</div>');
      });
      $calGrid.append($header);

      var firstDay = new Date(r.data.year, r.data.month - 1, 1).getDay();
      var firstMondayOffset = (firstDay === 0) ? 6 : firstDay - 1;
      var weekNum = getWeekNumber(r.data.year, r.data.month, 1);

      var $row = $('<div class="klp-cal-row">');
      $row.append('<div class="klp-cal-cell klp-cal-wnum">' + weekNum + '</div>');
      for (var i = 0; i < firstMondayOffset; i++) {
        $row.append('<div class="klp-cal-cell klp-cal-empty"></div>');
      }

      $.each(r.data.dates, function (i, d) {
        var col = (firstMondayOffset + d.day - 1) % 7;
        if (d.day > 1 && col === 0) {
          $calGrid.append($row);
          var nextWeek = getWeekNumber(r.data.year, r.data.month, d.day);
          $row = $('<div class="klp-cal-row">');
          $row.append('<div class="klp-cal-cell klp-cal-wnum">' + nextWeek + '</div>');
        }

        var cls = 'klp-cal-cell klp-cal-' + d.status;
        if (d.ymd === todayStr) cls += ' klp-cal-today';

        var $cell = $('<div class="' + cls + '" title="' + d.ymd + ' - ' + d.reason + '">' + d.day + '</div>');
        $cell.append('<span class="klp-cal-bar klp-bar-' + d.status + '"></span>');

        $cell.on('mouseenter', function () {
          $calFeedback.text(d.ymd + ' - ' + d.reason);
        });
        $calGrid.on('mouseleave', function () {
          $calFeedback.text('');
        });

        if (d.can_toggle) {
          $cell.addClass('klp-cal-clickable');
          $cell.on('click', function () {
            toggleClosedDate(d.ymd);
          });
        }

        $row.append($cell);
      });

      var lastCol = (firstMondayOffset + r.data.dates.length) % 7;
      if (lastCol > 0) {
        for (var i = lastCol; i < 7; i++) {
          $row.append('<div class="klp-cal-cell klp-cal-empty"></div>');
        }
      }
      $calGrid.append($row);
    });
  }

  function getWeekNumber(year, month, day) {
    var d = new Date(year, month - 1, day);
    d.setHours(0, 0, 0, 0);
    d.setDate(d.getDate() + 3 - (d.getDay() + 6) % 7);
    var week1 = new Date(d.getFullYear(), 0, 4);
    return 1 + Math.round(((d - week1) / 86400000 - 3 + (week1.getDay() + 6) % 7) / 7);
  }

  // ─── NAVIGATION ─────────────────────────────────────────────

  function buildYearNav() {
    $calNav.empty();
    $calNav.append('<button type="button" class="button klp-nav-btn" id="klp-year-prev">&larr;</button>');
    $calNav.append('<button type="button" class="button klp-nav-btn" id="klp-year-today">Vandaag</button>');
    $calNav.append('<span id="klp-cal-title" style="font-weight:700;font-size:15px;">' + calYear + '</span>');
    $calNav.append('<button type="button" class="button klp-nav-btn" id="klp-year-next">&rarr;</button>');

    $('#klp-year-prev').off().on('click', function () { calYear--; loadYear(); });
    $('#klp-year-next').off().on('click', function () { calYear++; loadYear(); });
    $('#klp-year-today').off().on('click', function () {
      calYear = new Date().getFullYear();
      if (!isYearView) {
        calMonth = new Date().getMonth() + 1;
        loadMonth();
      } else {
        loadYear();
      }
    });
  }

  function buildMonthNav() {
    $calNav.empty();
    $calNav.append('<button type="button" class="button klp-nav-btn" id="klp-mon-prev">&larr;</button>');
    $calNav.append('<button type="button" class="button klp-nav-btn" id="klp-mon-today">Vandaag</button>');
    $calNav.append('<button type="button" class="button klp-nav-btn" id="klp-mon-back">Jaaroverzicht</button>');
    $calNav.append('<span id="klp-cal-title" style="font-weight:700;font-size:15px;"></span>');
    $calNav.append('<button type="button" class="button klp-nav-btn" id="klp-mon-next">&rarr;</button>');

    $('#klp-mon-prev').off().on('click', function () {
      calMonth--;
      if (calMonth < 1) { calMonth = 12; calYear--; }
      loadMonth();
    });
    $('#klp-mon-next').off().on('click', function () {
      calMonth++;
      if (calMonth > 12) { calMonth = 1; calYear++; }
      loadMonth();
    });
    $('#klp-mon-today').off().on('click', function () {
      calYear = new Date().getFullYear();
      calMonth = new Date().getMonth() + 1;
      isYearView = false;
      loadMonth();
    });
    $('#klp-mon-back').off().on('click', function () {
      isYearView = true;
      loadYear();
    });
  }

  // ─── INIT ───────────────────────────────────────────────────

  parseClosedDates();
  renderDatesList();

  // Load year view on tab show (handled by tab click)

  // Test email buttons
  $(document).on('click', '.klp-test-email', function () {
    var $btn = $(this).prop('disabled', true);
    var type = $btn.data('type');
    var $res = $('#klp-test-email-result').show().removeClass('notice-success notice-error').addClass('notice-info').html('Bezig met versturen...');
    $.post(klp_admin.ajax_url, {
      action: 'klp_test_email',
      email_type: type,
      nonce: klp_admin.test_nonce
    }, function (r) {
      $res.removeClass('notice-info').addClass(r.success ? 'notice-success' : 'notice-error').html(r.data);
    }).fail(function () {
      $res.removeClass('notice-info').addClass('notice-error').html('Fout bij versturen.');
    }).always(function () {
      $btn.prop('disabled', false);
    });
  });

  // GC test
  $('#klp_test_gc').on('click', function () {
    var $btn = $(this).prop('disabled', true);
    var $res = $('#klp_test_gc_result').text('Bezig...').css('color', '#666');
    $.post(ajaxurl, { action: 'klp_test_gc' }, function (r) {
      $res.html(r.success ? '&#10003; ' + r.data : '&#10007; ' + r.data).css('color', r.success ? 'green' : '#b32d2e');
    }).fail(function () {
      $res.html('&#10007; Verbinding mislukt').css('color', '#b32d2e');
    }).always(function () {
      $btn.prop('disabled', false);
    });
  });
});
