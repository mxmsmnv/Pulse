/* Pulse — visual builder. Vanilla JS, no dependencies.
 * Reads window.PulseBuilder = { data, endpoints:{upload,preview}, csrf:{name,value}, formId, payloadName }.
 * Renders the editor UI, keeps a single state object, and serializes it into the
 * hidden payload field on form submit. */
(function () {
  'use strict';

  // CFG and state are populated inside init() after DOMContentLoaded so that the
  // inline <script>window.PulseBuilder=…</script> (placed in the page body by the
  // PHP builder) has already executed before we read its value.
  var CFG = {};
  var state = normalize({});

  function normalize(d) {
    d = d || {};
    d.id = d.id || 0;
    d.name = d.name || '';
    d.title = d.title || '';
    d.intro = d.intro || '';
    d.kind = d.kind === 'quiz' ? 'quiz' : 'poll';
    d.status = d.status ? 1 : 0;
    d.open_at = d.open_at || '';
    d.close_at = d.close_at || '';
    d.settings = d.settings || {};
    d.questions = Array.isArray(d.questions) ? d.questions : [];
    d.outcomes = Array.isArray(d.outcomes) ? d.outcomes : [];
    d.questions.forEach(function (q) {
      q.options = Array.isArray(q.options) ? q.options : [];
      q.options.forEach(function (o) { o.outcome_points = o.outcome_points || {}; });
    });
    if (d.kind === 'quiz' && !d.settings.mode) d.settings.mode = 'graded';
    return d;
  }

  // ---- tiny DOM helper ----
  function el(tag, props, children) {
    var n = document.createElement(tag);
    if (props) Object.keys(props).forEach(function (k) {
      if (k === 'class') n.className = props[k];
      else if (k === 'html') n.innerHTML = props[k];
      else if (k === 'text') n.textContent = props[k];
      else if (k.slice(0, 2) === 'on' && typeof props[k] === 'function') n.addEventListener(k.slice(2), props[k]);
      else if (props[k] === true) n.setAttribute(k, k);
      else if (props[k] !== false && props[k] != null) n.setAttribute(k, props[k]);
    });
    (children || []).forEach(function (c) {
      if (c == null) return;
      n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
    return n;
  }

  function field(label, control, note) {
    var kids = [el('label', { text: label }), control];
    if (note) kids.push(el('div', { class: 'pulse-note', text: note }));
    return el('div', { class: 'pulse-field' }, kids);
  }

  function input(value, oninput, attrs) {
    var a = attrs || {};
    var n = el('input', Object.assign({ type: 'text', value: value == null ? '' : value }, a));
    n.addEventListener('input', function () { oninput(n.value); });
    return n;
  }

  function numInput(value, oninput) {
    var n = el('input', { type: 'number', value: value == null ? '' : value });
    n.addEventListener('input', function () { oninput(n.value === '' ? '' : parseInt(n.value, 10)); });
    return n;
  }

  function checkbox(label, checked, onchange) {
    var c = el('input', { type: 'checkbox' });
    c.checked = !!checked;
    c.addEventListener('change', function () { onchange(c.checked); });
    return el('label', { class: 'pulse-inline' }, [c, document.createTextNode(' ' + label)]);
  }

  function select(value, options, onchange) {
    var s = el('select');
    options.forEach(function (o) {
      var opt = el('option', { value: o[0] }, [o[1]]);
      if (String(o[0]) === String(value)) opt.selected = true;
      s.appendChild(opt);
    });
    s.addEventListener('change', function () { onchange(s.value); });
    return s;
  }

  // ---- root render ----
  // root is assigned in init() after DOMContentLoaded, because builder.js is loaded
  // in <head> by PW's $config->scripts queue and the mount div doesn't exist yet.
  var root = null;
  function render() {
    if (!root) return;
    root.innerHTML = '';
    root.appendChild(el('div', { class: 'pulse-builder' }, [
      el('div', { class: 'pulse-builder__cols' }, [
        el('div', { class: 'pulse-builder__main' }, [
          baseCard(),
          settingsCard(),
          questionsCard(),
          state.kind === 'quiz' && state.settings.mode === 'personality' ? outcomesCard() : null
        ]),
        el('div', { class: 'pulse-builder__side' }, [previewCard()])
      ])
    ]));
  }

  function baseCard() {
    return card('Basics', null, [
      field('Name (shortcode slug)', input(state.name, function (v) { state.name = v; }, { placeholder: 'my-poll' }),
        'Lowercase letters, numbers, hyphens, underscores. Used in [[pulse:' + state.kind + ' name="' + (state.name || 'name') + '"]].'),
      field('Title', input(state.title, function (v) { state.title = v; })),
      field('Intro (optional)', textarea(state.intro, function (v) { state.intro = v; })),
      el('div', { class: 'pulse-row' }, [
        field('Kind', select(state.kind, [['poll', 'Poll'], ['quiz', 'Quiz']], function (v) {
          state.kind = v;
          if (v === 'quiz' && !state.settings.mode) state.settings.mode = 'graded';
          render();
        })),
        field('Status', select(state.status, [[0, 'Draft'], [1, 'Published']], function (v) { state.status = parseInt(v, 10); }))
      ]),
      el('div', { class: 'pulse-row' }, [
        field('Open at (optional)', dtInput(state.open_at, function (v) { state.open_at = v; })),
        field('Close at (optional)', dtInput(state.close_at, function (v) { state.close_at = v; }))
      ])
    ]);
  }

  function dtInput(ts, onset) {
    var n = el('input', { type: 'datetime-local' });
    if (ts) { var d = new Date(ts * 1000); n.value = toLocalInput(d); }
    n.addEventListener('input', function () {
      onset(n.value ? Math.floor(new Date(n.value).getTime() / 1000) : '');
    });
    return n;
  }
  function toLocalInput(d) {
    function p(x) { return ('' + x).padStart(2, '0'); }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + 'T' + p(d.getHours()) + ':' + p(d.getMinutes());
  }

  function textarea(value, oninput) {
    var n = el('textarea');
    n.value = value == null ? '' : value;
    n.addEventListener('input', function () { oninput(n.value); });
    return n;
  }

  // ---- settings / mode panels ----
  function settingsCard() {
    var s = state.settings;
    var body = [];
    body.push(field('Duplicate protection', select(s.dedupe || 'cookie_ip',
      [['cookie_ip', 'Cookie + IP'], ['user', 'Logged-in user'], ['soft', 'Soft (none)']],
      function (v) { s.dedupe = v; })));

    if (state.kind === 'poll') {
      body.push(checkbox('Allow multiple selections', s.multiple, function (v) { s.multiple = v; render(); }));
      if (s.multiple) {
        body.push(el('div', { class: 'pulse-row' }, [
          field('Min select', numInput(s.min_select, function (v) { s.min_select = v; })),
          field('Max select', numInput(s.max_select, function (v) { s.max_select = v; }))
        ]));
      }
      body.push(checkbox('Allow "Other" free text', s.allow_other, function (v) { s.allow_other = v; }));
      body.push(checkbox('Show vote counts (not just %)', s.show_counts, function (v) { s.show_counts = v; }));
      body.push(field('Results visibility', select(s.result_visibility || 'after_vote',
        [['after_vote', 'After voting'], ['after_close', 'After close'], ['admin_only', 'Admin only']],
        function (v) { s.result_visibility = v; })));
    } else {
      body.push(field('Mode', select(s.mode || 'graded',
        [['graded', 'Graded'], ['personality', 'Personality'], ['exam', 'Exam']],
        function (v) { s.mode = v; render(); })));

      if (s.mode === 'graded' || s.mode === 'exam') {
        body.push(field('Pass percent', numInput(s.pass_percent == null ? 60 : s.pass_percent, function (v) { s.pass_percent = v; })));
        body.push(checkbox('Show correct answers in review', s.show_correct, function (v) { s.show_correct = v; }));
        body.push(resultMessagesEditor(s));
      }
      if (s.mode === 'personality') {
        body.push(field('Result mode', select(s.result_mode || 'highest',
          [['highest', 'Highest score'], ['range', 'Score range']], function (v) { s.result_mode = v; })));
      }
      if (s.mode === 'exam') {
        body.push(el('div', { class: 'pulse-row' }, [
          field('Time limit (s, 0=off)', numInput(s.time_limit || 0, function (v) { s.time_limit = v; })),
          field('Max attempts (0=∞)', numInput(s.max_attempts || 0, function (v) { s.max_attempts = v; })),
          field('Pick random (0=all)', numInput(s.pick_random || 0, function (v) { s.pick_random = v; }))
        ]));
        body.push(checkbox('Issue certificate on pass', s.certificate, function (v) { s.certificate = v; }));
      }
      body.push(el('div', { class: 'pulse-row' }, [
        field('Pagination', select(s.pagination || 'all',
          [['all', 'All at once'], ['one_per_page', 'One per page']], function (v) { s.pagination = v; })),
        field('', el('div', {}, [checkbox('Progress bar', s.progress_bar, function (v) { s.progress_bar = v; })]))
      ]));
      body.push(checkbox('Shuffle questions', s.shuffle_questions, function (v) { s.shuffle_questions = v; }));
      body.push(checkbox('Shuffle options', s.shuffle_options, function (v) { s.shuffle_options = v; }));
      body.push(videoPanel(s));
    }
    body.push(engagementPanel(s));
    return card('Settings', null, body);
  }

  // Lead capture, share, notifications — applies to polls and quizzes.
  function engagementPanel(s) {
    var box = el('div', {});
    function has(f) { return Array.isArray(s.require_fields) && s.require_fields.indexOf(f) >= 0; }
    function toggle(f, on) {
      if (!Array.isArray(s.require_fields)) s.require_fields = [];
      var i = s.require_fields.indexOf(f);
      if (on && i < 0) s.require_fields.push(f);
      if (!on && i >= 0) s.require_fields.splice(i, 1);
    }
    function draw() {
      box.innerHTML = '';
      box.appendChild(el('div', { class: 'pulse-row' }, [
        checkbox('Require name', has('name'), function (v) { toggle('name', v); }),
        checkbox('Require email', has('email'), function (v) { toggle('email', v); draw(); })
      ]));
      box.appendChild(checkbox('Share button on result', !!s.share, function (v) { s.share = !!v; }));

      box.appendChild(checkbox('Email admin on response', !!s.notify_admin, function (v) {
        s.notify_admin = v ? (typeof s.notify_admin === 'object' ? s.notify_admin : { on: true }) : false; draw();
      }));
      if (s.notify_admin) {
        var na = typeof s.notify_admin === 'object' ? s.notify_admin : (s.notify_admin = { on: true });
        box.appendChild(field('Admin email (blank = site default)', input(na.to || '', function (v) { na.to = v; })));
        box.appendChild(field('Admin subject', input(na.subject || '', function (v) { na.subject = v; }, { placeholder: 'New response: {title}' })));
        box.appendChild(field('Admin body', textarea(na.body || '', function (v) { na.body = v; })));
      }

      box.appendChild(checkbox('Email participant their result', !!s.notify_user, function (v) {
        s.notify_user = v ? (typeof s.notify_user === 'object' ? s.notify_user : { on: true }) : false; draw();
      }));
      if (s.notify_user) {
        if (!has('email')) box.appendChild(el('p', { class: 'pulse-note' }, ['Requires the email field above.']));
        var nu = typeof s.notify_user === 'object' ? s.notify_user : (s.notify_user = { on: true });
        box.appendChild(field('User subject', input(nu.subject || '', function (v) { nu.subject = v; }, { placeholder: 'Your result: {title}' })));
        box.appendChild(field('User body', textarea(nu.body || '', function (v) { nu.body = v; })));
      }
      box.appendChild(el('p', { class: 'pulse-note' }, ['Merge tags: {title} {score} {max_score} {percent} {passed} {outcome} {date} {name}']));
    }
    draw();
    return field('Engagement', box);
  }

  function resultMessagesEditor(s) {
    s.result_messages = s.result_messages || [];
    var list = el('div', {});
    function draw() {
      list.innerHTML = '';
      s.result_messages.forEach(function (m, i) {
        list.appendChild(el('div', { class: 'pulse-option-row' }, [
          numInput(m.min, function (v) { m.min = v; }),
          numInput(m.max, function (v) { m.max = v; }),
          input(m.title, function (v) { m.title = v; }, { placeholder: 'Title' }),
          input(m.text, function (v) { m.text = v; }, { placeholder: 'Text' }),
          el('button', { type: 'button', class: 'uk-button uk-button-danger pulse-btn', onclick: function () { s.result_messages.splice(i, 1); draw(); } }, ['×'])
        ]));
      });
    }
    draw();
    return field('Result messages (by % range)', el('div', {}, [list,
      el('button', { type: 'button', class: 'uk-button uk-button-default pulse-btn', onclick: function () { s.result_messages.push({ min: 0, max: 100, title: '', text: '' }); draw(); } }, ['+ message'])
    ]));
  }

  function videoPanel(s) {
    var v = s.video || {};
    var hasVideo = !!(v.provider);
    var box = el('div', {});
    function draw() {
      box.innerHTML = '';
      if (!hasVideo) {
        box.appendChild(el('button', { type: 'button', class: 'uk-button uk-button-default pulse-btn', onclick: function () { hasVideo = true; v = s.video = { provider: 'youtube', src: '', gate: 'ended', percent: 90 }; draw(); } }, ['+ add video gate']));
        return;
      }
      box.appendChild(el('div', { class: 'pulse-row' }, [
        field('Provider', select(v.provider, [['youtube', 'YouTube'], ['vimeo', 'Vimeo'], ['mp4', 'MP4']], function (x) { v.provider = x; })),
        field('Gate', select(v.gate, [['ended', 'Ended'], ['percent', 'Percent'], ['button', 'Button']], function (x) { v.gate = x; draw(); }))
      ]));
      box.appendChild(field('Source (URL or file)', input(v.src, function (x) { v.src = x; })));
      if (v.gate === 'percent') box.appendChild(field('Percent', numInput(v.percent || 90, function (x) { v.percent = x; })));
      box.appendChild(el('button', { type: 'button', class: 'uk-button uk-button-danger pulse-btn', onclick: function () { hasVideo = false; delete s.video; draw(); } }, ['remove video']));
    }
    draw();
    return field('Video gate (optional)', box);
  }

  // ---- questions ----
  function questionsCard() {
    var list = el('div', { id: 'pulse-questions' });
    state.questions.forEach(function (q, i) { list.appendChild(questionCard(q, i)); });
    if (!state.questions.length) {
      list.appendChild(el('div', { class: 'pulse-builder__empty uk-panel' }, [
        el('i', { class: 'fa fa-question-circle' }),
        el('p', { class: 'uk-text-muted', text: state.kind === 'quiz' ? 'Start with one scored question, then add review text, hints, and answers.' : 'Start with one poll question and add the options people can choose from.' })
      ]));
    }
    enableDrag(list, state.questions, render);
    return card('Questions', null, [list,
      el('button', { type: 'button', class: 'uk-button uk-button-primary pulse-btn', onclick: function () {
        state.questions.push({ type: state.kind === 'poll' ? 'radio' : 'radio', text: '', image: '', explanation: '', hint: '', required: 1, points: 1, options: [] });
        render();
      } }, ['+ Add question'])
    ]);
  }

  function questionCard(q, idx) {
    var s = state.settings;
    var isQuiz = state.kind === 'quiz';
    var graded = isQuiz && (s.mode === 'graded' || s.mode === 'exam');

    var head = el('div', { class: 'pulse-card__head' }, [
      el('span', { class: 'pulse-card__drag', title: 'Drag to reorder', text: '⋮⋮' }),
      el('strong', { text: 'Q' + (idx + 1) }),
      el('span', { class: 'pulse-card__spacer' }),
      el('button', { type: 'button', class: 'uk-button uk-button-danger pulse-btn', onclick: function () { state.questions.splice(idx, 1); render(); } }, ['delete'])
    ]);

    var typeOpts = [['radio', 'Single choice'], ['checkbox', 'Multiple choice'], ['boolean', 'Yes/No'], ['text', 'Text']];
    var body = [
      el('div', { class: 'pulse-row' }, [
        field('Type', select(q.type, typeOpts, function (v) {
          q.type = v;
          if (v === 'boolean' && q.options.length === 0) q.options = [{ label: 'Yes', is_correct: 0, outcome_points: {} }, { label: 'No', is_correct: 0, outcome_points: {} }];
          render();
        })),
        graded ? field('Points', numInput(q.points == null ? 1 : q.points, function (v) { q.points = v; })) : null,
        field('', el('div', {}, [checkbox('Required', q.required, function (v) { q.required = v ? 1 : 0; })]))
      ]),
      field('Question text', textarea(q.text, function (v) { q.text = v; })),
      field('Question image (optional)', imageControl(q, true))
    ];
    if (isQuiz) {
      body.push(field('Explanation (shown in review)', textarea(q.explanation, function (v) { q.explanation = v; })));
    }
    body.push(field('Hint (optional)', input(q.hint, function (v) { q.hint = v; })));

    body.push(optionsEditor(q, idx, graded));

    return card(null, head, body, 'pulse-card pulse-question-card');
  }

  function optionsEditor(q, qIdx, graded) {
    var s = state.settings;
    var personality = state.kind === 'quiz' && s.mode === 'personality';
    var wrap = el('div', { class: 'pulse-options', 'data-q': qIdx });

    if (q.type === 'text') {
      // text question: list of accepted answers stored as options[].match_value
      q.options.forEach(function (o, i) {
        wrap.appendChild(el('div', { class: 'pulse-option-row' }, [
          input(o.match_value || '', function (v) { o.match_value = v; }, { placeholder: 'Accepted answer' }),
          el('button', { type: 'button', class: 'uk-button uk-button-danger pulse-btn', onclick: function () { q.options.splice(i, 1); render(); } }, ['×'])
        ]));
      });
      return field('Accepted answers', el('div', {}, [wrap,
        el('button', { type: 'button', class: 'uk-button uk-button-default pulse-btn', onclick: function () { q.options.push({ match_value: '', outcome_points: {} }); render(); } }, ['+ answer'])
      ]));
    }

    q.options.forEach(function (o, i) {
      o.outcome_points = o.outcome_points || {};
      var row = el('div', { class: 'pulse-option-row' }, [
        el('span', { class: 'pulse-option-row__drag', text: '⋮⋮' }),
        graded ? correctToggle(q, o) : null,
        input(o.label, function (v) { o.label = v; }, { placeholder: 'Option label' }),
        imageControl(o),
        el('button', { type: 'button', class: 'uk-button uk-button-danger pulse-btn', onclick: function () { q.options.splice(i, 1); render(); } }, ['×'])
      ]);
      wrap.appendChild(row);
      if (personality) wrap.appendChild(outcomePointsRow(o));
    });
    enableDrag(wrap, q.options, render, '.pulse-option-row');

    return field('Options', el('div', {}, [wrap,
      el('button', { type: 'button', class: 'uk-button uk-button-default pulse-btn', onclick: function () { q.options.push({ label: '', is_correct: 0, outcome_points: {} }); render(); } }, ['+ option'])
    ]));
  }

  function correctToggle(q, o) {
    var single = q.type === 'radio' || q.type === 'boolean';
    var c = el('input', { type: single ? 'radio' : 'checkbox' });
    c.checked = !!o.is_correct;
    c.addEventListener('change', function () {
      if (single && c.checked) q.options.forEach(function (x) { x.is_correct = 0; });
      o.is_correct = c.checked ? 1 : 0;
    });
    return el('label', { class: 'pulse-correct' }, [c, document.createTextNode(' correct')]);
  }

  function outcomePointsRow(o) {
    var box = el('div', { class: 'pulse-outcome-points' });
    state.outcomes.forEach(function (out, i) {
      var key = out.id ? String(out.id) : 'idx:' + i;
      var n = el('input', { type: 'number', value: o.outcome_points[key] || 0 });
      n.addEventListener('input', function () {
        var val = parseInt(n.value, 10) || 0;
        if (val) o.outcome_points[key] = val; else delete o.outcome_points[key];
      });
      box.appendChild(el('label', {}, [out.label || ('Outcome ' + (i + 1)), n]));
    });
    return box;
  }

  // ---- outcomes (personality) ----
  function outcomesCard() {
    var list = el('div', {});
    state.outcomes.forEach(function (o, i) {
      list.appendChild(card(null, el('div', { class: 'pulse-card__head' }, [
        el('strong', { text: 'Outcome ' + (i + 1) }),
        el('span', { class: 'pulse-card__spacer' }),
        el('button', { type: 'button', class: 'uk-button uk-button-danger pulse-btn', onclick: function () { state.outcomes.splice(i, 1); render(); } }, ['delete'])
      ]), [
        field('Label', input(o.label, function (v) { o.label = v; })),
        field('Description', textarea(o.description, function (v) { o.description = v; })),
        imageControl(o, true),
        (state.settings.result_mode === 'range') ? el('div', { class: 'pulse-row' }, [
          field('Min score', numInput(o.min_score, function (v) { o.min_score = v; })),
          field('Max score', numInput(o.max_score, function (v) { o.max_score = v; }))
        ]) : null
      ], 'pulse-card'));
    });
    return card('Outcomes', null, [list,
      el('button', { type: 'button', class: 'uk-button uk-button-primary pulse-btn', onclick: function () { state.outcomes.push({ label: '', description: '', image: '', min_score: '', max_score: '' }); render(); } }, ['+ Add outcome'])
    ]);
  }

  // ---- image control ----
  function imageControl(obj, big) {
    var box = el('span', { class: 'pulse-inline' });
    function draw(pendingMessage) {
      box.innerHTML = '';
      if (obj.image) {
        box.appendChild(el('img', { class: 'pulse-option-thumb', src: (CFG.assetsUrl || '') + encodeURIComponent(obj.image), style: big ? 'width:60px;height:60px;' : '' }));
        box.appendChild(el('button', { type: 'button', class: 'uk-button uk-button-danger pulse-btn', onclick: function () {
          obj.image = '';
          syncPayload();
          draw('Image removed. Save the item to apply this change.');
        } }, ['×']));
        if (pendingMessage) box.appendChild(el('span', { class: 'pulse-note pulse-image-status', text: pendingMessage }));
        return;
      }
      var f = el('input', { type: 'file', accept: 'image/*', style: 'width:auto;' });
      f.addEventListener('change', function () {
        if (!f.files || !f.files[0]) return;
        uploadImage(f.files[0], function (res) {
          if (res && res.file) {
            obj.image = res.file;
            syncPayload();
            draw('Image uploaded. Save the item to keep it.');
          }
        });
      });
      box.appendChild(f);
      if (pendingMessage) box.appendChild(el('span', { class: 'pulse-note pulse-image-status', text: pendingMessage }));
    }
    draw();
    return box;
  }

  function uploadImage(file, cb) {
    if (!CFG.endpoints || !CFG.endpoints.upload) return;
    var fd = new FormData();
    fd.append('image', file);
    if (CFG.csrf) fd.append(CFG.csrf.name, CFG.csrf.value);
    fetch(CFG.endpoints.upload, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(cb)
      .catch(function () { alert('Upload failed'); });
  }

  // ---- drag sort ----
  function enableDrag(container, arr, after, rowSel) {
    rowSel = rowSel || ':scope > .pulse-card, :scope > .pulse-question-card';
    var rows = container.querySelectorAll(rowSel);
    rows.forEach(function (row, i) {
      row.setAttribute('draggable', 'true');
      row.addEventListener('dragstart', function (e) { e.dataTransfer.setData('text/plain', i); row.classList.add('pulse-dragging'); });
      row.addEventListener('dragend', function () { row.classList.remove('pulse-dragging'); });
      row.addEventListener('dragover', function (e) { e.preventDefault(); });
      row.addEventListener('drop', function (e) {
        e.preventDefault();
        var from = parseInt(e.dataTransfer.getData('text/plain'), 10);
        if (isNaN(from) || from === i) return;
        var moved = arr.splice(from, 1)[0];
        arr.splice(i, 0, moved);
        after();
      });
    });
  }

  // ---- preview ----
  function previewCard() {
    var area = el('div', { class: 'pulse-builder__preview', id: 'pulse-preview', html: '<em class="pulse-note">Click "Refresh preview".</em>' });
    var btn = el('button', { type: 'button', class: 'uk-button uk-button-default pulse-btn', onclick: function () { refreshPreview(area); } }, ['Refresh preview']);
    var sc = state.name ? el('div', { class: 'pulse-note', style: 'margin-top:8px;', text: '[[pulse:' + state.kind + ' name="' + state.name + '"]]' }) : null;
    return card('Preview', null, [btn, el('div', { style: 'margin-top:10px;' }, [area]), sc]);
  }

  function refreshPreview(area) {
    if (!CFG.endpoints || !CFG.endpoints.preview) return;
    var fd = new FormData();
    fd.append('payload', JSON.stringify(serialize()));
    if (CFG.csrf) fd.append(CFG.csrf.name, CFG.csrf.value);
    area.innerHTML = '<em class="pulse-note">Loading…</em>';
    fetch(CFG.endpoints.preview, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.text(); })
      .then(function (html) { area.innerHTML = html; })
      .catch(function () { area.innerHTML = '<span class="pulse-note">Preview failed.</span>'; });
  }

  // ---- serialize + submit ----
  function serialize() {
    // strip empty quiz-only / poll-only fields lightly; server sanitizes.
    return JSON.parse(JSON.stringify(state));
  }

  function syncPayload() {
    var hidden = document.querySelector('[name="' + (CFG.payloadName || 'pulse_payload') + '"]');
    if (hidden) hidden.value = JSON.stringify(serialize());
  }

  function validationErrors() {
    var errors = [];
    var name = (state.name || '').trim();
    var title = (state.title || '').trim();
    if (!/^[a-z][a-z0-9_-]*$/.test(name)) errors.push('Name must start with a lowercase letter and contain only lowercase letters, numbers, hyphens, and underscores.');
    if (!title) errors.push('Title is required.');
    if (!Array.isArray(state.questions) || !state.questions.length) {
      errors.push(state.kind === 'quiz' ? 'Quiz must have at least one question.' : 'Poll must have a question.');
    }
    if (state.kind === 'quiz') {
      var mode = state.settings.mode || 'graded';
      if ((mode === 'graded' || mode === 'exam') && Array.isArray(state.questions)) {
        state.questions.forEach(function (q, i) {
          var opts = Array.isArray(q.options) ? q.options : [];
          if (q.type === 'text') {
            var hasMatch = opts.some(function (o) { return (o.match_value || '').trim() !== ''; });
            if (!hasMatch) errors.push('Text question ' + (i + 1) + ' needs at least one accepted answer.');
          } else {
            var hasCorrect = opts.some(function (o) { return !!o.is_correct; });
            if (!hasCorrect) errors.push('Question ' + (i + 1) + ' needs at least one correct option.');
          }
        });
      }
      if (mode === 'personality') {
        if (!Array.isArray(state.outcomes) || !state.outcomes.length) errors.push('Personality quiz must have at least one outcome.');
        var hasPoints = false;
        (state.questions || []).forEach(function (q) {
          (q.options || []).forEach(function (o) {
            Object.keys(o.outcome_points || {}).forEach(function (key) {
              if (parseInt(o.outcome_points[key], 10)) hasPoints = true;
            });
          });
        });
        if (!hasPoints) errors.push('Personality quiz needs outcome points on at least one option.');
      }
    }
    return errors;
  }

  function hookSubmit() {
    var form = CFG.formId ? document.getElementById(CFG.formId) : null;
    if (!form) form = root ? root.closest('form') : null;
    if (!form) return;
    form.addEventListener('submit', function (e) {
      var errors = validationErrors();
      if (errors.length) {
        e.preventDefault();
        alert('Please fix before saving:\n\n' + errors.join('\n'));
        return;
      }
      syncPayload();
    });
  }

  // ---- card helper ----
  function card(title, head, body, cls) {
    var kids = [];
    if (head) kids.push(head);
    else if (title) kids.push(el('div', { class: 'pulse-card__head' }, [el('h3', { text: title })]));
    (body || []).forEach(function (b) { if (b) kids.push(b); });
    var className = cls || 'pulse-card';
    if (className.indexOf('uk-card') === -1) className += ' uk-card uk-card-default uk-card-body';
    return el('div', { class: className }, kids);
  }

  function init() {
    // Read PulseBuilder config now — the inline <script>window.PulseBuilder={…};</script>
    // in the form body has already executed by the time DOMContentLoaded fires.
    CFG = window.PulseBuilder || {};
    state = normalize(CFG.data || {});
    root = document.getElementById('pulse-builder-root');
    render();
    hookSubmit();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
