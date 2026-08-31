(function () {
  'use strict';

  /*
   * API base-path detection.
   *
   * This site's pages, api/, and user/ folders are all siblings at the
   * project root, so every fetch() below uses a *relative* path
   * (apiBase + 'user/...') — that resolves correctly whether the project
   * is served from the domain root or from a subfolder (e.g. WAMP's
   * /anicalls-workforce-v2/), with zero manual configuration.
   */
  var apiBase = (function () {
    var m = window.location.pathname.match(/^(\/anicalls-website)\b/i);
    return m ? m[1] + '/' : '';
  }());

  /*
   * Turns a swallowed fetch()/JSON error into an actionable message instead
   * of a generic "Network error" — logs the real error to the console and
   * special-cases the #1 cause of this in practice: the page being opened
   * as a local file instead of served over http(s).
   */
  function describeRequestFailure(err, context) {
    console.error('[Anicalls Auth] ' + context + ' failed:', err);
    if (window.location.protocol === 'file:') {
      return 'This page is open as a local file (' + window.location.protocol + '//...) — sign-in requires the site to be served over http, e.g. http://localhost/anicalls-workforce-v2/. Open it through your web server, not by double-clicking the HTML file.';
    }
    var detail = (err && err.message) ? err.message : 'connection failed';
    return 'Could not reach the server (' + detail + '). Confirm this page’s address bar starts with http://localhost/ and that WAMP is running, then try again.';
  }

  /* Booking type to restore after the login flow completes */
  var pendingBookingType = 'Executive Briefing';

  var PANELS = {
    login:    { title: 'Welcome Back',    sub: 'Sign in to book your Executive Briefing'       },
    register: { title: 'Create Account',  sub: 'Create your free Anicalls account'             },
    forgot:   { title: 'Reset Password',  sub: 'Enter your email — we\'ll send a reset link'  },
    success:  { title: '',                sub: ''                                               }
  };

  /* ── Build the complete modal DOM (once) ─────────────────────────────── */
  function createModal() {
    if (document.getElementById('loginOverlay')) return;

    var userSvg =
      '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
        '<path d="M12 12c2.7 0 4.8-2.1 4.8-4.8S14.7 2.4 12 2.4 7.2 4.5 7.2 7.2 9.3 12 12 12z" fill="#fff"/>' +
        '<path d="M12 14.4c-3.2 0-9.6 1.6-9.6 4.8v2.4h19.2v-2.4c0-3.2-6.4-4.8-9.6-4.8z" fill="#fff"/>' +
      '</svg>';

    var closeSvg =
      '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">' +
        '<path d="M12 4L4 12M4 4l8 8" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"/>' +
      '</svg>';

    var checkSvg =
      '<svg width="28" height="28" viewBox="0 0 32 32" fill="none" aria-hidden="true">' +
        '<path d="M6 16l7 7 13-13" stroke="#d10014" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>' +
      '</svg>';

    /* Reusable field builder */
    function f(label, id, type, name, placeholder, ac) {
      return (
        '<div class="login-modal__field">' +
          '<label class="login-modal__label" for="' + id + '">' + label + '</label>' +
          '<input type="' + type + '" id="' + id + '" name="' + name + '"' +
            ' class="login-modal__input" placeholder="' + placeholder + '"' +
            ' autocomplete="' + ac + '" required>' +
        '</div>'
      );
    }

    function errBox(id) {
      return '<div id="' + id + '" class="login-modal__error" hidden></div>';
    }

    function submitBtn(id, label) {
      return (
        '<button type="submit" class="login-modal__btn" id="' + id + '">' +
          '<span class="login-modal__btn-text">' + label + '</span>' +
        '</button>'
      );
    }

    /* Panel-switching buttons — look like links, behave like buttons */
    function navRow() {
      var items = Array.prototype.slice.call(arguments);
      return (
        '<div class="login-modal__links">' +
          items.map(function (pair) {
            return '<button type="button" class="lm-link" data-goto="' + pair[0] + '">' + pair[1] + '</button>';
          }).join('') +
        '</div>'
      );
    }

    var html =
      /* ── Overlay ── */
      '<div class="login-overlay" id="loginOverlay" role="dialog" aria-modal="true" aria-label="Anicalls Authentication">' +
        '<div class="login-modal">' +

          /* Shared header — title + subtitle update per panel */
          '<div class="login-modal__header">' +
            '<div class="login-modal__brand">' +
              '<div class="login-modal__icon">' + userSvg + '</div>' +
              '<div>' +
                '<div class="login-modal__title" id="lmTitle">Welcome Back</div>' +
                '<div class="login-modal__sub"   id="lmSub">Sign in to book your Executive Briefing</div>' +
              '</div>' +
            '</div>' +
            '<button class="login-close" id="loginClose" aria-label="Close">' + closeSvg + '</button>' +
          '</div>' +

          /* ── Panel: Login ── */
          '<div class="lm-panel is-active" id="lmPanelLogin">' +
            '<div class="login-modal__body">' +
              /* Green notice shown when arriving from email verification or password reset */
              '<div id="loginNotice" class="lm-notice" hidden></div>' +
              '<form id="loginForm" novalidate>' +
                f('Email Address', 'loginEmail',    'email',    'email',    'you@company.com',     'email') +
                f('Password',      'loginPassword', 'password', 'password', 'Enter your password', 'current-password') +
                errBox('loginError') +
                submitBtn('loginSubmit', 'Sign In') +
              '</form>' +
              navRow(['forgot', 'Forgot Password?'], ['register', 'Create Account']) +
            '</div>' +
          '</div>' +

          /* ── Panel: Register ── */
          '<div class="lm-panel" id="lmPanelRegister">' +
            '<div class="login-modal__body">' +
              '<form id="registerForm" novalidate>' +
                f('Full Name',     'regName',     'text',     'name',     'Your full name',    'name') +
                f('Email Address', 'regEmail',    'email',    'email',    'you@company.com',   'email') +
                f('Password',      'regPassword', 'password', 'password', 'Min. 8 characters', 'new-password') +
                errBox('registerError') +
                submitBtn('registerSubmit', 'Create Account') +
              '</form>' +
              navRow(['login', 'Already have an account?']) +
            '</div>' +
          '</div>' +

          /* ── Panel: Forgot Password ── */
          '<div class="lm-panel" id="lmPanelForgot">' +
            '<div class="login-modal__body">' +
              '<form id="forgotForm" novalidate>' +
                f('Email Address', 'forgotEmail', 'email', 'email', 'you@company.com', 'email') +
                errBox('forgotError') +
                submitBtn('forgotSubmit', 'Send Reset Link') +
              '</form>' +
              navRow(['login', 'Back to Sign In']) +
            '</div>' +
          '</div>' +

          /* ── Panel: Success / confirmation ── */
          '<div class="lm-panel" id="lmPanelSuccess">' +
            '<div class="login-modal__body">' +
              '<div class="lm-success">' +
                '<div class="lm-success__icon">' + checkSvg + '</div>' +
                '<p class="lm-success__msg" id="lmSuccessMsg"></p>' +
                '<button type="button" class="login-modal__btn lm-success__back" id="lmSuccessBack">' +
                  'Back to Sign In' +
                '</button>' +
              '</div>' +
            '</div>' +
          '</div>' +

        '</div>' +   /* .login-modal */
      '</div>';      /* .login-overlay */

    document.body.insertAdjacentHTML('beforeend', html);
    bindEvents();
  }

  /* ── Activate a named panel ───────────────────────────────────────────── */
  function showPanel(name, titleOverride, subOverride) {
    var cfg = PANELS[name] || {};
    var titleEl = document.getElementById('lmTitle');
    var subEl   = document.getElementById('lmSub');
    if (titleEl) titleEl.textContent = (titleOverride !== undefined) ? titleOverride : (cfg.title || '');
    if (subEl)   subEl.textContent   = (subOverride  !== undefined) ? subOverride  : (cfg.sub   || '');

    var panels = document.querySelectorAll('.lm-panel');
    for (var i = 0; i < panels.length; i++) panels[i].classList.remove('is-active');

    var panelEl = document.getElementById('lmPanel' + name.charAt(0).toUpperCase() + name.slice(1));
    if (panelEl) panelEl.classList.add('is-active');

    /* Auto-focus the first input of the new panel */
    var focusMap = { login: 'loginEmail', register: 'regName', forgot: 'forgotEmail' };
    if (focusMap[name]) {
      setTimeout(function () {
        var el = document.getElementById(focusMap[name]);
        if (el) el.focus();
      }, 40);
    }
  }

  /* ── Show success panel ───────────────────────────────────────────────── */
  /*
   * title     — text shown in the modal header
   * message   — body message
   * onAfter   — optional callback; when provided the "Back to Sign In" button
   *             is hidden and the callback fires after a 2-second pause.
   *             Used for login success so the modal auto-closes and the
   *             booking calendar opens without a second click from the user.
   */
  function showSuccess(title, message, onAfter) {
    var msgEl   = document.getElementById('lmSuccessMsg');
    var backBtn = document.getElementById('lmSuccessBack');
    if (msgEl)   msgEl.textContent      = message;
    if (backBtn) backBtn.style.display  = onAfter ? 'none' : '';

    showPanel('success', title, '');

    if (onAfter) {
      setTimeout(function () {
        if (backBtn) backBtn.style.display = '';
        onAfter();
      }, 2000);
    }
  }

  /* ── Wire all events once ────────────────────────────────────────────── */
  function bindEvents() {
    var overlay = document.getElementById('loginOverlay');

    /* Close on X button or backdrop click */
    document.getElementById('loginClose').addEventListener('click', window.closeLoginModal);
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) window.closeLoginModal();
    });

    /* Panel-switching via data-goto attribute */
    overlay.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-goto]');
      if (!btn) return;
      clearErrors();
      resetForms();
      hideNotice();
      showPanel(btn.getAttribute('data-goto'));
    });

    /* Success panel "Back to Sign In" */
    document.getElementById('lmSuccessBack').addEventListener('click', function () {
      clearErrors();
      hideNotice();
      showPanel('login');
    });

    /* Form submissions */
    document.getElementById('loginForm').addEventListener('submit',   submitLogin);
    document.getElementById('registerForm').addEventListener('submit', submitRegister);
    document.getElementById('forgotForm').addEventListener('submit',   submitForgot);
  }

  /* ── Helpers ──────────────────────────────────────────────────────────── */
  function clearErrors() {
    ['loginError', 'registerError', 'forgotError'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) { el.hidden = true; el.textContent = ''; }
    });
  }

  function resetForms() {
    ['loginForm', 'registerForm', 'forgotForm'].forEach(function (id) {
      var f = document.getElementById(id);
      if (f) f.reset();
    });
  }

  function hideNotice() {
    var n = document.getElementById('loginNotice');
    if (n) { n.hidden = true; n.textContent = ''; }
  }

  function showError(id, msg) {
    var el = document.getElementById(id);
    if (!el) return;
    el.textContent = msg;
    el.hidden = false;
  }

  function setLoading(btnId, on) {
    var btn = document.getElementById(btnId);
    if (!btn) return;
    btn.disabled = on;
    btn.classList.toggle('is-loading', on);
  }

  /* ── Login ────────────────────────────────────────────────────────────── */
  function submitLogin(e) {
    e.preventDefault();
    clearErrors();
    setLoading('loginSubmit', true);

    fetch(apiBase + 'user/login-api.php', {
      method: 'POST',
      body:   new FormData(document.getElementById('loginForm'))
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          showSuccess(
            'Login Successful',
            'Opening your booking calendar…',
            function () {
              window.closeLoginModal();
              /* Small pause so the login modal finishes its close animation */
              setTimeout(function () {
                if (window.AnicallsBooking && typeof window.AnicallsBooking.open === 'function') {
                  window.AnicallsBooking.open(pendingBookingType);
                }
              }, 300);
            }
          );
        } else if (data.message && /verif/i.test(data.message)) {
          /*
           * Backend rejected login because email_verified_at is null.
           * Show an informational panel instead of a red error so the user
           * knows exactly what to do next — check inbox → click link →
           * land on home.html?verified=1 → login modal auto-reopens →
           * sign in → Executive Briefing opens automatically.
           */
          showSuccess(
            'Verify Your Email',
            'Your account is not yet verified. Please check your inbox for the verification email and click the link inside. Once verified, come back here and sign in — your Executive Briefing calendar will open automatically.'
          );
        } else {
          showError('loginError', data.message || 'Login failed. Please try again.');
        }
      })
      .catch(function (err) {
        showError('loginError', describeRequestFailure(err, 'Login'));
      })
      .finally(function () { setLoading('loginSubmit', false); });
  }

  /* ── Register ─────────────────────────────────────────────────────────── */
  /*
   * register.php detects the X-Requested-With header and returns JSON
   * instead of rendering its HTML page — no page navigation, no download.
   */
  function submitRegister(e) {
    e.preventDefault();
    clearErrors();
    setLoading('registerSubmit', true);

    fetch(apiBase + 'user/register.php', {
      method:  'POST',
      body:    new FormData(document.getElementById('registerForm')),
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          showSuccess(
            'Check Your Email',
            data.message || 'Registration successful. Please check your email to verify your account.'
          );
        } else {
          showError('registerError', data.message || 'Registration failed. Please try again.');
        }
      })
      .catch(function (err) {
        showError('registerError', describeRequestFailure(err, 'Registration'));
      })
      .finally(function () { setLoading('registerSubmit', false); });
  }

  /* ── Forgot Password ─────────────────────────────────────────────────── */
  /*
   * forgot-password.php also detects X-Requested-With and returns JSON.
   * The reset link in the email now points to reset-password.php (correct name).
   */
  function submitForgot(e) {
    e.preventDefault();
    clearErrors();
    setLoading('forgotSubmit', true);

    fetch(apiBase + 'user/forgot-password.php', {
      method:  'POST',
      body:    new FormData(document.getElementById('forgotForm')),
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.success) {
          showSuccess(
            'Email Sent',
            data.message || 'If an account exists with this email, a reset link has been sent.'
          );
        } else {
          showError('forgotError', data.message || 'Something went wrong. Please try again.');
        }
      })
      .catch(function (err) {
        showError('forgotError', describeRequestFailure(err, 'Password reset request'));
      })
      .finally(function () { setLoading('forgotSubmit', false); });
  }

  /* ── Public API ──────────────────────────────────────────────────────── */
  window.openLoginModal = function (bookingType) {
    pendingBookingType = bookingType || 'Executive Briefing';
    createModal();
    clearErrors();
    resetForms();
    hideNotice();
    showPanel('login');
    document.getElementById('loginOverlay').classList.add('show');
  };

  window.closeLoginModal = function () {
    var overlay = document.getElementById('loginOverlay');
    if (overlay) overlay.classList.remove('show');
  };

  /* ── Auto-open login from email redirect params ──────────────────────── */
  /*
   * verify-email.php  → redirects to home.html?verified=1
   * reset-password.php → redirects to home.html?passwordReset=1
   *
   * When detected, the login modal opens automatically with a green notice
   * confirming what just happened so the user understands the context.
   */
  (function detectRedirectParams() {
    try {
      var params   = new URLSearchParams(window.location.search);
      var noticeMsg = null;

      if (params.get('verified') === '1') {
        noticeMsg = 'Email verified successfully. Please sign in to continue.';
      } else if (params.get('passwordReset') === '1') {
        noticeMsg = 'Password updated. Please sign in with your new password.';
      }

      if (!noticeMsg) return;

      /* Clean the query string from the URL bar without reloading */
      if (window.history && window.history.replaceState) {
        history.replaceState(null, '', window.location.pathname);
      }

      /* Wait briefly for the page (and booking.js) to finish initialising */
      setTimeout(function () {
        window.openLoginModal(pendingBookingType);
        var notice = document.getElementById('loginNotice');
        if (notice) {
          notice.textContent = noticeMsg;
          notice.hidden      = false;
        }
      }, 250);

    } catch (e) {
      /* URLSearchParams not supported — silent no-op for very old browsers */
    }
  }());

}());
