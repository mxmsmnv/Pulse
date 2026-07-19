/* Pulse — front-end widget runtime.
 * Hydrates each .pulse independently (multi-instance), submits via fetch,
 * renders poll results. Progressive enhancement: without JS the form posts
 * normally and the server returns an HTML results page. */
(function () {
  'use strict';

  var ERRORS = {
    closed: 'This is closed.',
    already: 'You have already responded.',
    attempts: 'No attempts remaining.',
    timeout: 'Time limit exceeded.',
    csrf: 'Security token expired, please reload.',
    spam: 'Too many requests, please wait.',
    invalid: 'Invalid submission.',
    hidden: 'Results are not available yet.',
    error: 'Something went wrong.'
  };

  function ajax(url, opts) {
    opts = opts || {};
    opts.credentials = 'same-origin';
    opts.headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest' }, opts.headers || {});
    return fetch(url, opts);
  }

  function endpoints(form) {
    var action = form.getAttribute('action') || '/pulse/submit';
    return {
      submit: action,
      state: action.replace(/submit\/?$/, 'state'),
      results: action.replace(/submit\/?$/, 'results')
    };
  }

  function setState(root, state) {
    var changed = root.getAttribute('data-pulse-state') !== state;
    root.setAttribute('data-pulse-state', state);
    if (changed && typeof window.CustomEvent === 'function') {
      root.dispatchEvent(new CustomEvent('pulse:statechange', { bubbles: true, detail: { state: state } }));
    }
  }

  function intRange(value, min, max) {
    var n = parseInt(value, 10);
    if (!isFinite(n)) n = 0;
    return Math.max(min, Math.min(max, n));
  }

  function showError(root, code) {
    var box = root.querySelector('.pulse__results');
    var msg = ERRORS[code] || ERRORS.error;
    var p = root.querySelector('.pulse__error');
    if (!p) {
      p = document.createElement('div');
      p.className = 'pulse__error';
      (root.querySelector('.pulse__form') || root).appendChild(p);
    }
    p.textContent = msg;
  }
  function clearError(root) {
    var p = root.querySelector('.pulse__error');
    if (p) p.parentNode.removeChild(p);
  }

  function renderResults(root, results) {
    var box = root.querySelector('.pulse__results');
    if (!box) return;
    var showCounts = root.getAttribute('data-pulse-counts') === '1';
    var total = intRange(results && results.total, 0, 2147483647);
    var opts = (results && results.options) || {};
    var html = '<div class="pulse__results-inner">';
    Object.keys(opts).forEach(function (id) {
      var o = opts[id];
      var pct = intRange(o.percent, 0, 100);
      var count = intRange(o.count, 0, 2147483647);
      var meta = showCounts ? (pct + '% · ' + count) : (pct + '%');
      html += '<div class="pulse__result-row">'
        + '<div class="pulse__result-head"><span>' + esc(o.label) + '</span>'
        + '<span class="pulse__result-meta">' + esc(meta) + '</span></div>'
        + '<div class="pulse__bar"><div class="pulse__bar-fill" data-w="' + pct + '"></div></div>'
        + '</div>';
    });
    html += '<div class="pulse__total">' + total + (total === 1 ? ' vote' : ' votes') + '</div></div>';
    box.innerHTML = html;
    box.hidden = false;
    // animate bars
    requestAnimationFrame(function () {
      box.querySelectorAll('.pulse__bar-fill').forEach(function (b) { b.style.width = b.getAttribute('data-w') + '%'; });
    });
    setState(root, 'results');
    var heading = box.querySelector('.pulse__results-inner');
    if (heading) heading.setAttribute('tabindex', '-1');
    addRefresh(root, box);
    maybeShare(root, box);
  }

  function addRefresh(root, box) {
    if (root.getAttribute('data-pulse-kind') !== 'poll') return;
    if (box.querySelector('.pulse__refresh')) return;
    var name = root.getAttribute('data-pulse-name');
    var form = root.querySelector('.pulse__form');
    if (!name || !form) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pulse__refresh';
    btn.textContent = 'Refresh';
    btn.addEventListener('click', function () {
      btn.disabled = true;
      ajax(endpoints(form).results + '?name=' + encodeURIComponent(name))
        .then(function (r) { return r.json(); })
        .then(function (res) {
          btn.disabled = false;
          if (res && res.ok && res.results) renderResults(root, res.results);
          else showError(root, res && res.code);
        })
        .catch(function () { btn.disabled = false; showError(root, 'error'); });
    });
    box.appendChild(btn);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function setCsrf(form, csrf) {
    if (!csrf || !csrf.name) return;
    var input = form.elements[csrf.name];
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = csrf.name;
      form.appendChild(input);
    }
    input.value = csrf.value;
  }

  function setExamContentHidden(root, form, hidden) {
    var questions = Array.prototype.slice.call(form.querySelectorAll('.pulse__question'));
    questions.forEach(function (question) {
      question.hidden = hidden || !question.classList.contains('is-active');
    });
    var nav = form.querySelector('.pulse__nav');
    var submit = form.querySelector('.pulse__submit');
    var progress = root.querySelector('.pulse__progress');
    if (nav) nav.hidden = hidden;
    if (progress) progress.hidden = hidden;
    if (submit) {
      var active = questions.findIndex(function (question) { return question.classList.contains('is-active'); });
      submit.hidden = hidden || active !== questions.length - 1;
    }
  }

  function prepareExamLeadGate(root, form, startCallback) {
    var lead = form.querySelector('.pulse__lead');
    if (!lead || !lead.querySelector('input[required]')) return null;

    var gate = document.createElement('div');
    gate.className = 'pulse__exam-gate';
    var heading = document.createElement('h3');
    heading.className = 'pulse__exam-gate-title';
    heading.textContent = 'Before you begin';
    var intro = document.createElement('p');
    intro.className = 'pulse__exam-gate-intro';
    intro.textContent = 'Enter your name and email. The exam timer starts only after you select Start assessment.';
    var start = document.createElement('button');
    start.type = 'button';
    start.className = 'pulse__start';
    start.textContent = 'Start assessment';
    form.insertBefore(gate, form.firstChild);
    gate.appendChild(heading);
    gate.appendChild(intro);
    gate.appendChild(lead);
    gate.appendChild(start);
    setExamContentHidden(root, form, true);
    setState(root, 'lead');

    start.addEventListener('click', function () {
      clearError(root);
      var fields = Array.prototype.slice.call(lead.querySelectorAll('input[required]'));
      for (var i = 0; i < fields.length; i++) {
        if (!fields[i].checkValidity()) {
          if (fields[i].reportValidity) fields[i].reportValidity();
          return;
        }
      }
      start.disabled = true;
      startCallback(gate, start);
    });
    return gate;
  }

  function activateExam(root, form, gate, secs) {
    if (gate) gate.hidden = true;
    setExamContentHidden(root, form, false);
    setState(root, 'form');
    if (secs > 0) startExamTimer(root, form, secs);
  }

  function hydrate(root) {
    var form = root.querySelector('.pulse__form');
    var name = root.getAttribute('data-pulse-name');
    var kind = root.getAttribute('data-pulse-kind');
    if (!form || !name) { setState(root, 'form'); return; }
    var ep = endpoints(form);
    var isExam = kind === 'quiz' && root.getAttribute('data-pulse-mode') === 'exam';

    if (kind === 'quiz') setupQuiz(root, form);
    setupOtherInputs(form);
    if (kind === 'quiz' && root.getAttribute('data-pulse-video') === '1') setupVideo(root, form);

    var gate = null;
    if (isExam) {
      gate = prepareExamLeadGate(root, form, function (gateElement, startButton) {
        ajax(ep.state + '?name=' + encodeURIComponent(name) + '&start=1')
          .then(function (r) { return r.json(); })
          .then(function (d) {
            startButton.disabled = false;
            if (!d || !d.ok) { showError(root, d && d.code); return; }
            setCsrf(form, d.csrf);
            if (d.view === 'exhausted') {
              disableForm(form);
              showError(root, 'attempts');
              return;
            }
            if (!d.exam_started) { showError(root, 'invalid'); return; }
            activateExam(root, form, gateElement, Math.max(0, parseInt(d.time_remaining, 10) || 0));
          })
          .catch(function () { startButton.disabled = false; showError(root, 'error'); });
      });
    }

    var stateUrl = ep.state + '?name=' + encodeURIComponent(name);
    if (isExam && !gate) stateUrl += '&start=1';
    ajax(stateUrl)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) { setState(root, 'form'); return; }
        setCsrf(form, d.csrf);
        if (kind === 'poll' && d.view === 'results' && d.results) {
          renderResults(root, d.results);
        } else if (d.view === 'closed') {
          setState(root, 'closed');
        } else if (d.view === 'exhausted') {
          setState(root, 'form');
          disableForm(form);
          showError(root, 'attempts');
        } else if (kind === 'quiz' && !isExam && d.submitted) {
          setState(root, 'form');
          disableForm(form);
          showError(root, 'already');
        } else if (isExam && !d.exam_started) {
          if (d.expired_attempt) showError(root, 'timeout');
          if (gate) setState(root, 'lead');
        } else {
          var secs = isExam ? ((d.time_remaining != null) ? d.time_remaining
            : parseInt(root.getAttribute('data-pulse-timelimit'), 10) || 0) : 0;
          if (root.__pulseLocked) {
            setState(root, 'locked');
            root.__pulsePendingTimer = secs; // exam timer starts once the video unlocks
          } else {
            if (isExam) activateExam(root, form, gate, secs);
            else setState(root, 'form');
          }
        }
      })
      .catch(function () { if (!gate) setState(root, 'form'); else showError(root, 'error'); });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      clearError(root);
      if (!validateForm(root, form, kind)) {
        showError(root, 'invalid');
        return;
      }
      var ts = form.querySelector('.pulse__timespent');
      if (ts && root.__pulseExamStart) ts.value = Math.round((Date.now() - root.__pulseExamStart) / 1000);
      var btn = form.querySelector('.pulse__submit');
      if (btn) btn.disabled = true;
      ajax(ep.submit, { method: 'POST', body: new FormData(form) })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (btn) btn.disabled = false;
          if (res && res.ok) {
            if (kind === 'quiz') renderQuizResult(root, res);
            else renderResults(root, res.results);
          } else {
            showError(root, res && res.code);
          }
        })
        .catch(function () { if (btn) btn.disabled = false; showError(root, 'error'); });
    });
  }

  function disableForm(form) {
    form.querySelectorAll('input, button, textarea, select').forEach(function (el) { el.disabled = true; });
  }

  function maybeShare(root, box) {
    if (root.getAttribute('data-pulse-share') !== '1') return;
    if (box.querySelector('.pulse__share')) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pulse__share';
    btn.textContent = 'Share';
    btn.addEventListener('click', function () {
      var url = location.href;
      if (navigator.share) { navigator.share({ url: url }).catch(function () {}); }
      else if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function () { btn.textContent = 'Link copied'; }); }
      else { window.prompt('Copy link:', url); }
    });
    box.appendChild(btn);
  }

  // ---- video gate ----
  var _scripts = {};
  function loadScript(url, cb) {
    if (_scripts[url] === 'done') { cb(); return; }
    if (_scripts[url]) { _scripts[url].push(cb); return; }
    _scripts[url] = [cb];
    var s = document.createElement('script');
    s.src = url; s.async = true;
    s.onload = function () { var q = _scripts[url]; _scripts[url] = 'done'; (q || []).forEach(function (f) { f(); }); };
    document.head.appendChild(s);
  }
  function ytReady(cb) {
    if (window.YT && window.YT.Player) { cb(); return; }
    loadScript('https://www.youtube.com/iframe_api', function () {});
    var iv = setInterval(function () { if (window.YT && window.YT.Player) { clearInterval(iv); cb(); } }, 200);
  }

  function setupVideo(root, form) {
    var box = root.querySelector('.pulse__video');
    if (!box) return;
    root.__pulseLocked = true;
    var provider = box.getAttribute('data-pulse-provider');
    var gate = box.getAttribute('data-pulse-gate') || 'ended';
    var pct = intRange(box.getAttribute('data-pulse-percent'), 0, 100) || 90;
    var done = false;

    function unlock() {
      if (done) return; done = true;
      root.__pulseLocked = false;
      setState(root, 'form');
      var b = box.querySelector('.pulse__video-unlock'); if (b) b.disabled = true;
      if (root.__pulsePendingTimer > 0) startExamTimer(root, form, root.__pulsePendingTimer);
    }

    var ub = box.querySelector('.pulse__video-unlock');
    if (ub) ub.addEventListener('click', unlock);
    if (gate === 'button') return; // button is the only unlock path

    if (provider === 'mp4') {
      var v = box.querySelector('video');
      if (!v) return;
      if (gate === 'percent') v.addEventListener('timeupdate', function () { if (v.duration && (v.currentTime / v.duration * 100) >= pct) unlock(); });
      else v.addEventListener('ended', unlock);
    } else if (provider === 'youtube') {
      ytReady(function () {
        var iframe = box.querySelector('iframe'); if (!iframe) return;
        var p = new YT.Player(iframe, { events: {
          onStateChange: function (e) { if (e.data === YT.PlayerState.ENDED) unlock(); }
        } });
        if (gate === 'percent') {
          var iv = setInterval(function () {
            try { var d = p.getDuration(), c = p.getCurrentTime(); if (d && (c / d * 100) >= pct) { clearInterval(iv); unlock(); } } catch (e) {}
          }, 1000);
        }
      });
    } else if (provider === 'vimeo') {
      loadScript('https://player.vimeo.com/api/player.js', function () {
        var iframe = box.querySelector('iframe'); if (!iframe || !window.Vimeo) return;
        var p = new Vimeo.Player(iframe);
        p.on('ended', unlock);
        if (gate === 'percent') p.on('timeupdate', function (data) { if (data.percent * 100 >= pct) unlock(); });
      });
    }
  }

  // ---- exam: countdown timer ----
  function startExamTimer(root, form, secs) {
    root.__pulseExamStart = Date.now();
    var disp = document.createElement('div');
    disp.className = 'pulse__timer';
    form.parentNode.insertBefore(disp, form);
    var remaining = secs;
    function fmt(t) { var m = Math.floor(t / 60), s = t % 60; return m + ':' + (s < 10 ? '0' : '') + s; }
    function tick() {
      disp.textContent = '⏱ ' + fmt(Math.max(0, remaining));
      if (remaining <= 0) {
        clearInterval(root.__pulseTimerIv);
        var ts = form.querySelector('.pulse__timespent');
        if (ts) ts.value = secs;
        if (form.requestSubmit) form.requestSubmit();
        else form.dispatchEvent(new Event('submit', { cancelable: true }));
        return;
      }
      remaining--;
    }
    root.__pulseTimerIv = setInterval(tick, 1000);
    tick();
  }

  // ---- quiz: pagination + progress ----
  function questionAnswered(fs) {
    var choices = fs.querySelectorAll('input[type="radio"], input[type="checkbox"]');
    if (choices.length) {
      return Array.prototype.some.call(choices, function (input) {
        if (!input.checked) return false;
        if (input.value !== '__other') return true;
        var other = fs.querySelector('.pulse__other');
        return !!(other && other.value.trim() !== '');
      });
    }
    var t = fs.querySelector('.pulse__text-input');
    return t ? t.value.trim() !== '' : true;
  }

  function choiceCount(fs) {
    return Array.prototype.filter.call(fs.querySelectorAll('input[type="radio"]:checked, input[type="checkbox"]:checked'), function (input) {
      if (input.value !== '__other') return true;
      var other = fs.querySelector('.pulse__other');
      return !!(other && other.value.trim() !== '');
    }).length;
  }

  function setupOtherInputs(form) {
    form.querySelectorAll('.pulse__question').forEach(function (fs) {
      var other = fs.querySelector('.pulse__other');
      if (!other) return;
      function sync() {
        var checked = !!fs.querySelector('input[value="__other"]:checked');
        other.disabled = !checked;
        if (!checked) other.value = '';
      }
      fs.addEventListener('change', sync);
      sync();
    });
  }

  function validateForm(root, form, kind) {
    var questions = Array.prototype.slice.call(form.querySelectorAll('.pulse__question'));
    for (var i = 0; i < questions.length; i++) {
      var fs = questions[i];
      if (fs.getAttribute('data-pulse-required') === '1' && !questionAnswered(fs)) return false;
      if (kind === 'poll' && root.getAttribute('data-pulse-multiple') === '1') {
        var count = choiceCount(fs);
        var min = intRange(root.getAttribute('data-pulse-minselect'), 0, 2147483647);
        var max = intRange(root.getAttribute('data-pulse-maxselect'), 0, 2147483647);
        if (min && count < min) return false;
        if (max && count > max) return false;
      }
    }
    return true;
  }

  function setupQuiz(root, form) {
    var questions = Array.prototype.slice.call(form.querySelectorAll('.pulse__question'));
    var paged = root.getAttribute('data-pulse-pagination') === 'one_per_page' && questions.length > 1;
    var progressEl = root.querySelector('.pulse__progress');
    var submitBtn = form.querySelector('.pulse__submit');

    function updateProgress(activeIndex) {
      if (!progressEl) return;
      var pct = Math.round(((activeIndex + 1) / questions.length) * 100);
      progressEl.hidden = false;
      progressEl.setAttribute('aria-valuenow', pct);
      var fill = progressEl.querySelector('.pulse__progress-fill');
      if (fill) fill.style.width = pct + '%';
    }

    if (!paged) { updateProgress(questions.length - 1); return; }

    root.classList.add('pulse--paged');
    var current = 0;

    var nav = document.createElement('div');
    nav.className = 'pulse__nav';
    var back = document.createElement('button');
    back.type = 'button'; back.className = 'pulse__nav-btn pulse__nav-back'; back.textContent = '←';
    var next = document.createElement('button');
    next.type = 'button'; next.className = 'pulse__nav-btn pulse__nav-next'; next.textContent = '→';
    nav.appendChild(back); nav.appendChild(next);
    if (submitBtn) form.insertBefore(nav, submitBtn); else form.appendChild(nav);

    function show(i) {
      current = i;
      questions.forEach(function (q, idx) { q.classList.toggle('is-active', idx === i); });
      back.hidden = i === 0;
      var last = i === questions.length - 1;
      next.hidden = last;
      if (submitBtn) submitBtn.hidden = !last;
      updateProgress(i);
    }
    back.addEventListener('click', function () { if (current > 0) show(current - 1); });
    next.addEventListener('click', function () {
      var fs = questions[current];
      if (fs.getAttribute('data-pulse-required') === '1' && !questionAnswered(fs)) {
        showError(root, 'invalid'); return;
      }
      clearError(root);
      if (current < questions.length - 1) show(current + 1);
    });
    show(0);
  }

  function renderQuizResult(root, res) {
    var box = root.querySelector('.pulse__results');
    if (!box) return;
    if (root.__pulseTimerIv) { clearInterval(root.__pulseTimerIv); var td = root.querySelector('.pulse__timer'); if (td) td.remove(); }

    if (res.outcome) {
      var o = res.outcome;
      var oh = '<div class="pulse__quiz-result pulse__outcome">';
      if (o.image) oh += '<img class="pulse__outcome-img" src="' + esc(o.image) + '" alt="' + esc(o.label) + '">';
      oh += '<h3 class="pulse__outcome-label">' + esc(o.label) + '</h3>';
      if (o.description) oh += '<div class="pulse__outcome-desc">' + esc(o.description) + '</div>';
      oh += '</div>';
      box.innerHTML = oh;
      box.hidden = false;
      setState(root, 'quiz_result');
      box.setAttribute('tabindex', '-1');
      box.focus && box.focus();
      maybeShare(root, box);
      return;
    }

    var pass = !!res.passed;
    var score = intRange(res.score, 0, 2147483647);
    var maxScore = intRange(res.max_score, 0, 2147483647);
    var percent = intRange(res.percent, 0, 100);
    var html = '<div class="pulse__quiz-result">';
    html += '<div class="pulse__score ' + (pass ? 'is-pass' : 'is-fail') + '">'
      + score + ' / ' + maxScore + ' (' + percent + '%)</div>';
    if (res.messages) {
      if (res.messages.title) html += '<h3 class="pulse__result-title">' + esc(res.messages.title) + '</h3>';
      if (res.messages.text) html += '<p class="pulse__result-text">' + esc(res.messages.text) + '</p>';
    }
    if (res.review && res.review.length) {
      html += '<ol class="pulse__review">';
      res.review.forEach(function (r) {
        var cls = r.is_correct ? 'is-correct' : 'is-incorrect';
        html += '<li class="pulse__review-item ' + cls + '">';
        html += '<div class="pulse__review-q">' + esc(r.question) + '</div>';
        var your = (r.your || []).filter(function (x) { return x !== '' && x != null; });
        html += '<div class="pulse__review-your">Your answer: ' + esc(your.length ? your.join(', ') : '(none)') + '</div>';
        if (r.correct) html += '<div class="pulse__review-correct">Correct: ' + esc([].concat(r.correct).join(', ')) + '</div>';
        if (r.explanation) html += '<div class="pulse__review-exp">' + esc(r.explanation) + '</div>';
        html += '</li>';
      });
      html += '</ol>';
    }
    if (res.certificate_url) {
      html += '<p class="pulse__cert"><a class="pulse__cert-link" href="' + esc(res.certificate_url)
        + '" target="_blank" rel="noopener">Download certificate</a></p>';
    }
    html += '</div>';
    box.innerHTML = html;
    box.hidden = false;
    setState(root, 'quiz_result');
    box.setAttribute('tabindex', '-1');
    box.focus && box.focus();
    maybeShare(root, box);
  }

  function init() {
    var nodes = document.querySelectorAll('.pulse');
    nodes.forEach(function (root) {
      if (root.__pulseInit) return;
      root.__pulseInit = true;
      hydrate(root);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
