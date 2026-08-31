/* ============================================================
   ANICALLS — Booking Calendar System
   Backend-driven scheduling modal — PHP + MySQL + PHPMailer
   API endpoint: /ANICALLS-WEBSITE/api/modal-booking.php
   ============================================================ */

(function () {
  'use strict';

  /* ── State ── */
  var state = {
    step: 1,
    selectedDate: null,
    selectedTime: null,
    currentMonth: new Date(),
    bookingType: 'Virtual Meeting'
  };

  /* ── Time slots (all times in prospect's local display; server books UTC) ── */
  var TIME_SLOTS = [
    { label: '09:00 AM', value: '09:00' },
    { label: '10:00 AM', value: '10:00' },
    { label: '11:00 AM', value: '11:00' },
    { label: '01:00 PM', value: '13:00' },
    { label: '02:00 PM', value: '14:00' },
    { label: '03:00 PM', value: '15:00' },
    { label: '04:00 PM', value: '16:00' },
    { label: '05:00 PM', value: '17:00' }
  ];

  var TOPIC_OPTIONS = [
    'AI Workforce — Replace headcount with AI agents',
    'GCC-as-a-Service™ — Build an AI-native GCC',
    'Agent OS™ — Enterprise AI platform',
    'Industry-specific AI solution',
    'Virtual Meeting (role-specific)',
    'ROI calculation and business case',
    'Investor / Board enquiry',
    'Vendor / procurement onboarding',
    'Other'
  ];

  /* ── Modal HTML template ── */
  function createModalHTML() {
    return '<div class="bk-overlay" id="bkOverlay" role="dialog" aria-modal="true" aria-label="Talk to an Expert">' +
      '<div class="bk-modal">' +

        /* Header */
        '<div class="bk-header">' +
          '<div class="bk-header__brand">' +
            '<div class="bk-header__icon">' +
              '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>' +
            '</div>' +
            '<div>' +
              '<div class="bk-header__title">Book a Virtual meeting</div>' +
              '<div class="bk-header__sub">45-minute session · 24-hour response guaranteed</div>' +
            '</div>' +
          '</div>' +
          '<button class="bk-close" id="bkClose" aria-label="Close booking modal">' +
            '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 3l10 10M13 3L3 13"/></svg>' +
          '</button>' +
        '</div>' +

        /* Progress */
        '<div class="bk-progress">' +
          '<div class="bk-step is-active" id="bkStep1">' +
            '<div class="bk-step__num">1</div>' +
            '<div class="bk-step__label">Choose Date</div>' +
          '</div>' +
          '<div class="bk-step-divider"></div>' +
          '<div class="bk-step" id="bkStep2">' +
            '<div class="bk-step__num">2</div>' +
            '<div class="bk-step__label">Select Time</div>' +
          '</div>' +
          '<div class="bk-step-divider"></div>' +
          '<div class="bk-step" id="bkStep3">' +
            '<div class="bk-step__num">3</div>' +
            '<div class="bk-step__label">Your Details</div>' +
          '</div>' +
        '</div>' +

        /* Scrollable body */
        '<div class="bk-body">' +

          /* Panel 1: Calendar */
          '<div class="bk-panel is-active" id="bkPanel1">' +
            '<div class="bk-cal">' +
              '<div class="bk-cal__nav">' +
                '<button class="bk-cal__arrow" id="bkCalPrev" aria-label="Previous month">' +
                  '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 12L6 8l4-4"/></svg>' +
                '</button>' +
                '<div class="bk-cal__month" id="bkCalMonth"></div>' +
                '<button class="bk-cal__arrow" id="bkCalNext" aria-label="Next month">' +
                  '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 4l4 4-4 4"/></svg>' +
                '</button>' +
              '</div>' +
              '<div class="bk-cal__grid" id="bkCalGrid"></div>' +
            '</div>' +
          '</div>' +

          /* Panel 2: Time slots */
          '<div class="bk-panel" id="bkPanel2">' +
            '<div class="bk-summary" id="bkSummary1">' +
              '<div class="bk-summary__item">' +
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>' +
                '<span class="bk-summary__val" id="bkSumDate">—</span>' +
              '</div>' +
            '</div>' +
            '<p class="bk-slots__heading">Select an available time slot (your local timezone)</p>' +
            '<div class="bk-slots__grid" id="bkSlotsGrid"></div>' +
          '</div>' +

          /* Panel 3: Contact form */
          '<div class="bk-panel" id="bkPanel3">' +
            '<div class="bk-summary" id="bkSummary2">' +
              '<div class="bk-summary__item">' +
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>' +
                '<span class="bk-summary__val" id="bkSumDate2">—</span>' +
              '</div>' +
              '<div class="bk-summary__item">' +
                '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>' +
                '<span class="bk-summary__val" id="bkSumTime">—</span>' +
              '</div>' +
            '</div>' +
            '<form class="bk-form" id="bkForm" novalidate>' +
              '<div class="bk-form__row">' +
                '<div class="bk-form__field">' +
                  '<label class="bk-form__label" for="bkFirstName">First name *</label>' +
                  '<input type="text" class="bk-form__input" id="bkFirstName" name="first_name" placeholder="Jane" required />' +
                '</div>' +
                '<div class="bk-form__field">' +
                  '<label class="bk-form__label" for="bkLastName">Last name *</label>' +
                  '<input type="text" class="bk-form__input" id="bkLastName" name="last_name" placeholder="Smith" required />' +
                '</div>' +
              '</div>' +
              '<div class="bk-form__row">' +
                '<div class="bk-form__field">' +
                  '<label class="bk-form__label" for="bkEmail">Work email *</label>' +
                  '<input type="email" class="bk-form__input" id="bkEmail" name="email" placeholder="jane@company.com" required />' +
                '</div>' +
                '<div class="bk-form__field">' +
                  '<label class="bk-form__label" for="bkPhone">Phone number</label>' +
                  '<input type="tel" class="bk-form__input" id="bkPhone" name="phone" placeholder="+1 (555) 000-0000" />' +
                '</div>' +
              '</div>' +
              '<div class="bk-form__row">' +
                '<div class="bk-form__field">' +
                  '<label class="bk-form__label" for="bkCompany">Company *</label>' +
                  '<input type="text" class="bk-form__input" id="bkCompany" name="company" placeholder="Your organisation" required />' +
                '</div>' +
                '<div class="bk-form__field">' +
                  '<label class="bk-form__label" for="bkTitle">Job title *</label>' +
                  '<input type="text" class="bk-form__input" id="bkTitle" name="job_title" placeholder="e.g. Chief Financial Officer" required />' +
                '</div>' +
              '</div>' +
              '<div class="bk-form__field">' +
                '<label class="bk-form__label" for="bkTopic">What would you like to discuss? *</label>' +
                '<select class="bk-form__input bk-form__select" id="bkTopic" name="topic" required>' +
                  '<option value="">Select a topic</option>' +
                  TOPIC_OPTIONS.map(function(t) { return '<option>' + t + '</option>'; }).join('') +
                '</select>' +
              '</div>' +
              '<div class="bk-form__field">' +
                '<label class="bk-form__label" for="bkMessage">Tell us about your situation (optional)</label>' +
                '<textarea class="bk-form__input bk-form__textarea" id="bkMessage" name="message" placeholder="e.g. We have 300 agents in our contact centre and are exploring AI automation options..."></textarea>' +
              '</div>' +
              '<div class="bk-error" id="bkError">Please fill in all required fields before submitting.</div>' +
            '</form>' +
          '</div>' +

          /* Panel 4: Confirmation */
          '<div class="bk-panel" id="bkPanel4">' +
            '<div class="bk-confirm">' +
              '<div class="bk-confirm__icon">' +
                '<svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>' +
              '</div>' +
              '<div class="bk-confirm__title">Briefing Request Received!</div>' +
              '<p class="bk-confirm__body">An Anicalls enterprise AI specialist will confirm your briefing within <strong>24 hours</strong>. Check your inbox — including spam.</p>' +
              '<div class="bk-confirm__details">' +
                '<div class="bk-confirm__detail-row">' +
                  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>' +
                  '<span>Requested date: <strong id="bkConfDate">—</strong></span>' +
                '</div>' +
                '<div class="bk-confirm__detail-row">' +
                  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>' +
                  '<span>Requested time: <strong id="bkConfTime">—</strong></span>' +
                '</div>' +
                '<div class="bk-confirm__detail-row">' +
                  '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>' +
                  '<span>Confirmation sent to: <strong id="bkConfEmail">—</strong></span>' +
                '</div>' +
              '</div>' +
              '<button class="bk-btn bk-btn--primary" id="bkConfClose">Close &amp; Return to Site</button>' +
              '<button class="bk-btn bk-btn--ghost bk-conf-signout" id="bkConfLogout">' +
                '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/></svg>' +
                'Sign Out' +
              '</button>' +
            '</div>' +
          '</div>' +

        '</div>' + /* /bk-body */

        /* Footer */
        '<div class="bk-footer" id="bkFooter">' +
          '<div class="bk-footer__left">' +
            '<span id="bkFootNote">Select a date to continue</span>' +
          '</div>' +
          '<div class="bk-footer__actions">' +
            '<button class="bk-btn bk-btn--ghost" id="bkBack" style="display:none">← Back</button>' +
            '<button class="bk-btn bk-btn--primary" id="bkNext" disabled>Continue →</button>' +
          '</div>' +
        '</div>' +

      '</div>' + /* /bk-modal */
    '</div>'; /* /bk-overlay */
  }

  /* ── Calendar renderer ── */
  function renderCalendar() {
    var d     = state.currentMonth;
    var year  = d.getFullYear();
    var month = d.getMonth();
    var today = new Date();
    today.setHours(0,0,0,0);

    var MONTHS = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
    var DAYS   = ['Su','Mo','Tu','We','Th','Fr','Sa'];

    document.getElementById('bkCalMonth').textContent = MONTHS[month] + ' ' + year;

    var firstDay  = new Date(year, month, 1).getDay();
    var daysInMon = new Date(year, month + 1, 0).getDate();

    /* Disable prev if we're already at/before today's month */
    var prevBtn = document.getElementById('bkCalPrev');
    var nowMonth = new Date(); nowMonth.setDate(1); nowMonth.setHours(0,0,0,0);
    var curFirst = new Date(year, month, 1);
    prevBtn.disabled = curFirst <= nowMonth;

    var grid = document.getElementById('bkCalGrid');
    grid.innerHTML = '';

    /* Day-of-week headers */
    DAYS.forEach(function(day) {
      var el = document.createElement('div');
      el.className = 'bk-cal__dow';
      el.textContent = day;
      grid.appendChild(el);
    });

    /* Empty cells before first day */
    for (var i = 0; i < firstDay; i++) {
      var empty = document.createElement('button');
      empty.className = 'bk-cal__day is-empty';
      empty.setAttribute('aria-hidden', 'true');
      grid.appendChild(empty);
    }

    /* Day cells */
    for (var day = 1; day <= daysInMon; day++) {
      var date = new Date(year, month, day);
      date.setHours(0,0,0,0);
      var btn  = document.createElement('button');
      btn.textContent = day;
      btn.className   = 'bk-cal__day';
      btn.setAttribute('data-date', date.toISOString());
      btn.setAttribute('aria-label', MONTHS[month] + ' ' + day + ', ' + year);

      var isWeekend = date.getDay() === 0 || date.getDay() === 6;
      var isPast    = date < today;
      var isTooFar  = date > new Date(today.getTime() + 90 * 86400000);

      if (isWeekend || isPast || isTooFar) {
        btn.classList.add('is-disabled');
        btn.disabled = true;
      } else {
        if (date.toDateString() === today.toDateString()) {
          btn.classList.add('is-today');
        }
        if (state.selectedDate && date.toDateString() === state.selectedDate.toDateString()) {
          btn.classList.add('is-selected');
        }
        btn.addEventListener('click', function(e) {
          state.selectedDate = new Date(e.currentTarget.getAttribute('data-date'));
          renderCalendar();
          updateFooter();
        });
      }

      grid.appendChild(btn);
    }
  }

  /* ── Time slots renderer ── */
  function renderSlots() {
    var grid = document.getElementById('bkSlotsGrid');
    grid.innerHTML = '<p style="color:#94a3b8;font-size:14px;text-align:center">Loading available times…</p>';

    var dateStr = formatDate(state.selectedDate);
    var tzName = '';
    try { tzName = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch(e) {}
    var tzAbbr = tzName ? '(' + tzName + ')' : '(local time)';

    fetch('api/booked-slots.php?date=' + encodeURIComponent(dateStr))
      .then(function(res) { return res.json(); })
      .then(function(data) {
        var booked = (data && Array.isArray(data.booked)) ? data.booked : [];
        grid.innerHTML = '';
        TIME_SLOTS.forEach(function(slot) {
          var isBooked = booked.indexOf(slot.value) !== -1;
          var btn = document.createElement('button');
          btn.className = 'bk-slot' +
            (state.selectedTime === slot.value ? ' is-selected' : '') +
            (isBooked ? ' is-booked' : '');
          btn.setAttribute('data-time', slot.value);
          btn.disabled = isBooked;
          btn.innerHTML = '<span class="bk-slot__time">' + slot.label + '</span>' +
                          '<span class="bk-slot__tz">' + (isBooked ? 'Booked' : tzAbbr) + '</span>';
          if (!isBooked) {
            btn.addEventListener('click', function() {
              state.selectedTime = slot.value;
              renderSlots();
              updateFooter();
            });
          }
          grid.appendChild(btn);
        });
      })
      .catch(function() {
        /* Fallback: show all slots if fetch fails */
        grid.innerHTML = '';
        TIME_SLOTS.forEach(function(slot) {
          var btn = document.createElement('button');
          btn.className = 'bk-slot' + (state.selectedTime === slot.value ? ' is-selected' : '');
          btn.setAttribute('data-time', slot.value);
          btn.innerHTML = '<span class="bk-slot__time">' + slot.label + '</span>' +
                          '<span class="bk-slot__tz">' + tzAbbr + '</span>';
          btn.addEventListener('click', function() {
            state.selectedTime = slot.value;
            renderSlots();
            updateFooter();
          });
          grid.appendChild(btn);
        });
      });
  }

  /* ── Footer / step state ── */
  function updateFooter() {
    var backBtn  = document.getElementById('bkBack');
    var nextBtn  = document.getElementById('bkNext');
    var footNote = document.getElementById('bkFootNote');

    if (state.step === 1) {
      backBtn.style.display  = 'none';
      nextBtn.textContent    = 'Continue →';
      nextBtn.disabled       = !state.selectedDate;
      footNote.textContent   = state.selectedDate ? formatDate(state.selectedDate) + ' selected' : 'Select a date to continue';
    } else if (state.step === 2) {
      backBtn.style.display  = '';
      nextBtn.textContent    = 'Continue →';
      nextBtn.disabled       = !state.selectedTime;
      footNote.textContent   = state.selectedTime ? state.selectedTime + ' selected' : 'Select a time to continue';
    } else if (state.step === 3) {
      backBtn.style.display  = '';
      nextBtn.textContent    = 'Confirm Booking';
      nextBtn.disabled       = false;
      footNote.textContent   = 'All fields marked * are required';
    }
  }

  /* ── Go to a step ── */
  function goTo(step) {
    state.step = step;

    [1,2,3,4].forEach(function(i) {
      var panel = document.getElementById('bkPanel' + i);
      if (panel) panel.classList.toggle('is-active', i === step);
    });

    [1,2,3].forEach(function(i) {
      var stepEl = document.getElementById('bkStep' + i);
      if (stepEl) {
        stepEl.classList.toggle('is-active', i === step);
        stepEl.classList.toggle('is-done', i < step);
      }
    });

    var footer = document.getElementById('bkFooter');
    if (step === 4 && footer) footer.style.display = 'none';
    if (step < 4 && footer) footer.style.display   = '';

    if (step === 2) {
      var sumDate = document.getElementById('bkSumDate');
      if (sumDate) sumDate.textContent = formatDate(state.selectedDate);
      renderSlots();
    }

    if (step === 3) {
      var sumDate2 = document.getElementById('bkSumDate2');
      var sumTime  = document.getElementById('bkSumTime');
      if (sumDate2) sumDate2.textContent = formatDate(state.selectedDate);
      if (sumTime)  sumTime.textContent  = state.selectedTime;
    }

    updateFooter();
  }

  /* ── Format date helper ── */
  function formatDate(d) {
    if (!d) return '—';
    var MONTHS = ['Jan','Feb','Mar','Apr','May','Jun',
                  'Jul','Aug','Sep','Oct','Nov','Dec'];
    var DAYS   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    return DAYS[d.getDay()] + ', ' + MONTHS[d.getMonth()] + ' ' + d.getDate() + ', ' + d.getFullYear();
  }

  /* ── Validate form ── */
  function validateForm() {
    var required = ['bkFirstName','bkLastName','bkEmail','bkCompany','bkTitle','bkTopic'];
    var valid = true;
    required.forEach(function(id) {
      var el = document.getElementById(id);
      if (el && !el.value.trim()) { valid = false; el.style.borderColor = '#fca5a5'; }
      else if (el) { el.style.borderColor = ''; }
    });
    var errEl = document.getElementById('bkError');
    if (errEl) errEl.classList.toggle('is-visible', !valid);
    return valid;
  }

  /* ── Submit via PHP backend ── */
  function submitBooking() {
    if (!validateForm()) return;

    var nextBtn = document.getElementById('bkNext');
    var errEl   = document.getElementById('bkError');

    if (nextBtn) { nextBtn.classList.add('is-loading'); nextBtn.disabled = true; }
    if (errEl)   { errEl.classList.remove('is-visible'); }

    var firstName = document.getElementById('bkFirstName').value.trim();
    var lastName  = document.getElementById('bkLastName').value.trim();
    var email     = document.getElementById('bkEmail').value.trim();
    var phone     = document.getElementById('bkPhone').value.trim();
    var company   = document.getElementById('bkCompany').value.trim();
    var title     = document.getElementById('bkTitle').value.trim();
    var topic     = document.getElementById('bkTopic').value;
    var message   = document.getElementById('bkMessage').value.trim();

    var payload = {
      first_name:   firstName,
      last_name:    lastName,
      email:        email,
      phone:        phone || '',
      company:      company,
      job_title:    title,
      topic:        topic,
      message:      message || '',
      booking_date: formatDate(state.selectedDate),
      booking_time: state.selectedTime,
      booking_type: state.bookingType
    };

    fetch('api/modal-booking.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload)
    })
    .then(function(res) {
      return res.json().then(function(json) {
        return { status: res.status, body: json };
      });
    })
    .then(function(result) {
      console.log('[AnicallsBooking] API response:', result);
      if (nextBtn) { nextBtn.classList.remove('is-loading'); nextBtn.disabled = false; }
      if (result.body && result.body.success === true) {
        showConfirmation(email, firstName);
      } else {
        var msg = (result.body && result.body.message) ? result.body.message : 'Submission failed. Please try again.';
        if (errEl) { errEl.textContent = msg; errEl.classList.add('is-visible'); }
      }
    })
    .catch(function(err) {
      console.error('[AnicallsBooking] Fetch error:', err);
      if (nextBtn) { nextBtn.classList.remove('is-loading'); nextBtn.disabled = false; }
      var msg = (window.location.protocol === 'file:')
        ? 'This page is open as a local file — booking requires the site to be served over http, e.g. http://localhost/anicalls-workforce-v2/. Open it through your web server, not by double-clicking the HTML file.'
        : 'Could not reach the server (' + (err && err.message ? err.message : 'connection failed') + '). Confirm this page\'s address bar starts with http://localhost/ and that WAMP is running, then try again.';
      if (errEl) { errEl.textContent = msg; errEl.classList.add('is-visible'); }
    });
  }

  /* ── Show confirmation ── */
  function showConfirmation(email, name) {
    document.getElementById('bkConfDate').textContent  = formatDate(state.selectedDate);
    document.getElementById('bkConfTime').textContent  = state.selectedTime;
    document.getElementById('bkConfEmail').textContent = email;
    goTo(4);
  }

  /* ── Open / Close modal ── */
  function openModal(bookingType) {
    state.bookingType = bookingType || 'Executive Briefing';
    state.step          = 1;
    state.selectedDate  = null;
    state.selectedTime  = null;
    state.currentMonth  = new Date();
    state.bookedSlots   = [];

    var overlay = document.getElementById('bkOverlay');
    if (!overlay) return;
    overlay.classList.add('is-open');
    document.body.style.overflow = 'hidden';
    renderCalendar();
    updateFooter();
    goTo(1);
  }

  function closeModal() {
    var overlay = document.getElementById('bkOverlay');
    if (overlay) overlay.classList.remove('is-open');
    document.body.style.overflow = '';
  }

  /* ── Inject modal into DOM ── */
  function init() {
    /* Inject modal HTML */
    var div = document.createElement('div');
    div.innerHTML = createModalHTML();
    document.body.appendChild(div.firstChild);

    /* Close handlers */
    document.getElementById('bkClose').addEventListener('click', closeModal);
    document.getElementById('bkOverlay').addEventListener('click', function(e) {
      if (e.target === e.currentTarget) closeModal();
    });
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') closeModal();
    });

    /* Confirmation close */
    document.getElementById('bkConfClose').addEventListener('click', closeModal);

    /* Sign Out from confirmation panel */
    document.getElementById('bkConfLogout').addEventListener('click', function () {
      var btn  = document.getElementById('bkConfLogout');
      var base = (window.location.pathname.match(/^(\/anicalls-website)\b/i) || ['', ''])[1];
      if (btn) { btn.disabled = true; btn.style.opacity = '0.55'; }
      fetch(base + 'user/logout.php', {
        method:  'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
        .then(function () { closeModal(); })
        .catch(function () { closeModal(); });
    });

    /* Calendar navigation */
    document.getElementById('bkCalPrev').addEventListener('click', function() {
      state.currentMonth = new Date(
        state.currentMonth.getFullYear(),
        state.currentMonth.getMonth() - 1,
        1
      );
      renderCalendar();
    });
    document.getElementById('bkCalNext').addEventListener('click', function() {
      state.currentMonth = new Date(
        state.currentMonth.getFullYear(),
        state.currentMonth.getMonth() + 1,
        1
      );
      renderCalendar();
    });

    /* Footer navigation */
    document.getElementById('bkBack').addEventListener('click', function() {
      goTo(state.step - 1);
    });

    document.getElementById('bkNext').addEventListener('click', function() {
      if (state.step === 3) {
        submitBooking();
      } else {
        goTo(state.step + 1);
      }
    });

    /* Wire ALL existing CTA buttons to open the modal */
    wireCTAButtons();

    /* Initial render */
    renderCalendar();
    updateFooter();
  }

  /* ── Wire CTA buttons site-wide ── */
  function wireCTAButtons() {
    /* Target all primary CTA buttons that point to contact.html.
       Several selectors can match the same element (e.g. a nav CTA is both
       "a[href*=contact].btn" and "[data-booking]") — collect matches into a
       Set first so every element gets exactly one click listener, never one
       per matching selector. */
    var selectors = [
      'a.btn--primary[href*="contact"]',
      'a[href*="contact"].btn',
      '.navbar__cta-btn',
      '.mobile-cta a',
      '[data-booking]'
    ];

    var targets = new Set();
    selectors.forEach(function(sel) {
      document.querySelectorAll(sel).forEach(function(el) { targets.add(el); });
    });

    targets.forEach(function(el) {
      el.addEventListener('click', function (e) {
        e.preventDefault();
        var type = el.getAttribute('data-booking') || 'Executive Briefing';
        fetch('user/check-login.php')
          .then(function (res) { return res.json(); })
          .then(function (data) {
            if (data.loggedIn) {
              openModal(type);
            } else {
              openLoginModal(type);
            }
          })
          .catch(function () {
            openLoginModal(type);
          });
      });
    });
  }

  /* ── Public API ── */
  window.AnicallsBooking = {
    open:  openModal,
    close: closeModal
  };

  /* ── Initialise ── */
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})();
