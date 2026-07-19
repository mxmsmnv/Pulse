<?php namespace ProcessWire;

require_once(__DIR__ . '/src/Pulses.php');
require_once(__DIR__ . '/src/PulseSubmissions.php');

/**
 * Process Pulse
 *
 * Engagement module for ProcessWire: polls and quizzes embedded via shortcodes.
 * This class is the admin process, installer, config holder and endpoint router.
 *
 * @property string $componentsPath
 * @property string $endpointBase
 * @property string $mail_module
 * @property string $hashSalt
 * @property int $rateLimit
 * @property int $rateWindow
 * @property int $dataRetention
 * @property bool $debugMode
 */
class ProcessPulse extends Process implements ConfigurableModule {

    public static function getModuleInfo() {
        return [
            'title' => 'Pulse Admin',
            'version' => 104,
            'summary' => 'Polls and quizzes embedded via shortcodes, with live results.',
            'author' => 'Maxim Semenov',
            'href'     => 'https://smnv.org',
            'icon' => 'bar-chart',
            'autoload' => true,
            'singular' => true,
            'permission' => 'pulse',
            'permissions' => [
                'pulse' => 'List and view Pulse items',
                'pulse-edit' => 'Add/edit/delete Pulse items',
            ],
            'requires' => ['Pulse'],
            'installs' => 'TextformatterPulse',
        ];
    }

    protected static $defaultConfig = [
        'componentsPath' => 'components/',
        'endpointBase' => 'pulse',
        'mail_module' => '',
        'hashSalt' => '',
        'rateLimit' => 10,
        'rateWindow' => 60,
        'dataRetention' => 0,
        'debugMode' => false,
    ];

    /** @var Pulses|null */
    protected $pulses = null;

    public function __construct() {
        foreach(self::$defaultConfig as $key => $value) {
            if(!isset($this->$key)) $this->$key = $value;
        }
        parent::__construct();
    }

    public function init() {
        parent::init();
        $this->pulses = $this->wire(new Pulses());
        $this->registerEndpoints();
        // Auto-retention: hook LazyCron if dataRetention is configured.
        if((int) $this->dataRetention > 0) {
            $this->wire()->addHook('LazyCron::everyDay', $this, 'runRetention');
        }
    }

    /**
     * Repository accessor.
     *
     * @return Pulses
     */
    public function pulses() {
        if($this->pulses === null) $this->pulses = $this->wire(new Pulses());
        return $this->pulses;
    }

    /* ---------------------------------------------------------------------
     * Endpoints (stubs — implemented in a later stage)
     * ------------------------------------------------------------------ */

    protected function registerEndpoints() {
        $base = '/' . $this->safeEndpointBase($this->endpointBase ?: 'pulse') . '/';
        $this->wire()->addHook($base . 'state', $this, 'handleState');
        $this->wire()->addHook($base . 'submit', $this, 'handleSubmit');
        $this->wire()->addHook($base . 'results', $this, 'handleResults');
        $this->wire()->addHook($base . 'certificate/{token}', $this, 'handleCertificate');
    }

    protected function safeEndpointBase($value) {
        $base = trim((string) $value, "/ \t\n\r\0\x0B");
        $base = preg_replace('~[^A-Za-z0-9/_-]+~', '', $base);
        $base = preg_replace('~/+~', '/', $base);
        $base = trim($base, '/');
        return $base !== '' ? $base : 'pulse';
    }

    /** @var PulseSubmissions|null */
    protected $subs = null;

    public function subs() {
        if($this->subs === null) $this->subs = $this->wire(new PulseSubmissions());
        return $this->subs;
    }

    /**
     * LazyCron handler: auto-anonymize submissions older than dataRetention days.
     * Fires approximately once per day when dataRetention > 0.
     */
    public function runRetention(HookEvent $event) {
        $days = (int) $this->dataRetention;
        if($days <= 0) return;
        $cutoff = time() - $days * 86400;
        $n = 0;
        foreach($this->pulses()->getAll() as $item) {
            $n += $this->subs()->purgeOld($item, $cutoff, 'anonymize');
        }
        if($n > 0) {
            $this->wire('log')->save('pulse-debug', "Auto-retention: anonymized {$n} submission(s) older than {$days} days");
        }
    }

    protected function json($data) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * GET /pulse/state?name=… — per-visitor hydration for cache-safe widgets.
     */
    public function handleState(HookEvent $event) {
        $name = $this->requestItemName($this->input->get('name'));
        $item = $name ? $this->pulses()->get($name) : null;
        if(!$item || !$item->status) { $event->return = $this->json(['ok' => false, 'code' => 'not_found']); return; }

        $csrf = $this->session->CSRF;
        $ctx = $this->subs()->buildContext(false);
        $submitted = $this->subs()->hasSubmitted($item, $ctx);
        $open = $item->isOpen();

        $data = [
            'ok' => true,
            'name' => $item->name,
            'kind' => $item->kind,
            'open' => $open,
            'submitted' => $submitted,
            'csrf' => ['name' => $csrf->getTokenName(), 'value' => $csrf->getTokenValue()],
            'closes_in' => $item->close_at ? max(0, (int) $item->close_at - time()) : null,
            'results' => null,
            'view' => $open ? 'form' : 'closed',
        ];

        if($item->isPoll()) {
            $visible = $this->subs()->resultsVisible($item, $submitted);
            if($visible) {
                $data['results'] = $this->subs()->getPollResults($item);
                if($submitted || !$open) $data['view'] = 'results';
            }
        }

        if($item->isQuiz() && $item->getMode() === 'exam' && $open) {
            $max = isset($item->settings['max_attempts']) ? max(0, (int) $item->settings['max_attempts']) : 0;
            $used = $this->subs()->countAttempts($item, $ctx);
            $data['attempts_left'] = $max > 0 ? max(0, $max - $used) : null;
            $data['exam_started'] = false;

            $timeLimit = isset($item->settings['time_limit']) ? max(0, (int) $item->settings['time_limit']) : 0;
            $data['time_limit'] = $timeLimit;
            $startRequested = (string) $this->input->get('start') === '1';
            if(!$max || $used < $max) {
                $key = 'examstart_' . $item->name;
                $start = (int) $this->session->getFor('Pulse', $key);
                $elapsed = $start ? max(0, time() - $start) : 0;

                // An abandoned timed attempt is finalized when the visitor next
                // hydrates the widget. This prevents a zero-second, still-editable
                // form and releases the session for a permitted next attempt.
                if($timeLimit > 0 && $start && $elapsed >= $timeLimit) {
                    $timeoutCtx = $ctx;
                    $timeoutCtx['time_spent'] = $elapsed;
                    $timeout = $this->subs()->recordExamTimeout($item, $timeoutCtx);
                    $this->session->setFor('Pulse', $key, null);
                    $start = 0;
                    $data['expired_attempt'] = ($timeout['code'] ?? '') === 'timeout';
                    if($data['expired_attempt']) {
                        $used++;
                        $data['attempts_left'] = $max > 0 ? max(0, $max - $used) : null;
                    }
                }

                if($max > 0 && $used >= $max) {
                    $data['view'] = 'exhausted';
                } elseif($startRequested) {
                    if(!$start) {
                        $start = time();
                        if($timeLimit > 0) $this->session->setFor('Pulse', $key, $start);
                    }
                    $data['exam_started'] = true;
                    $data['time_remaining'] = $timeLimit > 0
                        ? max(0, $timeLimit - (time() - $start))
                        : 0;
                } elseif($start) {
                    $data['exam_started'] = true;
                    $data['time_remaining'] = max(0, $timeLimit - $elapsed);
                } else {
                    $data['time_remaining'] = null;
                }
            }
            if($max > 0 && $used >= $max) $data['view'] = 'exhausted';
        }

        $event->return = $this->json($data);
    }

    /**
     * POST /pulse/submit — accept a response. JSON for XHR, HTML when a
     * non-cached custom form posts a valid CSRF token without JavaScript.
     */
    public function handleSubmit(HookEvent $event) {
        $ajax = $this->wire('config')->ajax;

        // Every write requires a valid token. Cached widget shells receive it
        // from /state during JS hydration before their form can be submitted.
        if(!$this->session->CSRF->hasValidToken()) {
            $event->return = $this->fail($ajax, 'csrf'); return;
        }
        // honeypot
        if((string) $this->input->post('website') !== '') {
            $event->return = $this->fail($ajax, 'spam'); return;
        }
        // rate limit
        $rateContext = $this->subs()->buildContext(false);
        if(!$this->subs()->checkRateLimit($rateContext)) {
            $event->return = $this->fail($ajax, 'spam'); return;
        }

        $name = $this->requestItemName($this->input->post('name'));
        $item = $name ? $this->pulses()->get($name) : null;
        if(!$item || !$item->status) {
            $event->return = $this->fail($ajax, 'invalid'); return;
        }

        // Read answers directly from $_POST: PW's $input->post('answers') passes the array
        // through WireInputData::cleanArray() which, at the default wireInputArrayDepth=1,
        // silently drops any value that is itself an array. Checkbox questions post their
        // option ids as answers[qid][] (depth-2 arrays) and would be dropped, causing
        // "Invalid submission." for any quiz that includes a checkbox question.
        $rawAnswers = isset($_POST['answers']) && is_array($_POST['answers']) ? $_POST['answers'] : [];
        $answers = [];
        foreach($rawAnswers as $qidKey => $val) {
            $qid = (int) $qidKey;
            if($qid > 0) $answers[$qid] = $val; // values validated downstream in recordPoll/recordQuiz
        }

        $ctx = $this->subs()->buildContext(true);
        $ctx['lead_name'] = $this->input->post('lead_name');
        $ctx['lead_email'] = $this->input->post('lead_email');
        $ctx['other'] = isset($_POST['other']) && is_array($_POST['other']) ? $_POST['other'] : [];

        if($item->isQuiz() && $item->getMode() === 'exam') {
            // pick_random integrity: a randomised exam must submit exactly the
            // server-issued signed question set.
            $qids = $this->input->post('qids');
            $qsig = $this->input->post('qsig');
            $pick = isset($item->settings['pick_random']) ? max(0, (int) $item->settings['pick_random']) : 0;
            $needsSignedSet = $pick > 0 && $pick < $item->getQuestions()->count();
            if($needsSignedSet && ($qids === null || $qids === '')) {
                $event->return = $this->fail($ajax, 'invalid'); return;
            }
            if($qids !== null && $qids !== '') {
                $valid = $this->subs()->verifyQuestionIds($qids, (string) $qsig, (int) $item->id);
                if(!$valid) { $event->return = $this->fail($ajax, 'invalid'); return; }
                $ctx['question_ids'] = $valid;
            }
            // server-authoritative elapsed time
            $timeLimit = isset($item->settings['time_limit']) ? max(0, (int) $item->settings['time_limit']) : 0;
            $start = (int) $this->session->getFor('Pulse', 'examstart_' . $item->name);
            if($start) {
                $ctx['time_spent'] = max(0, time() - $start);
                if($timeLimit > 0 && $ctx['time_spent'] > $timeLimit) {
                    $result = $this->subs()->recordExamTimeout($item, $ctx);
                    $this->session->setFor('Pulse', 'examstart_' . $item->name, null);
                    $event->return = $ajax ? $this->json($result) : $this->fail(false, $result['code'] ?? 'timeout');
                    return;
                }
            } elseif($timeLimit > 0) {
                $event->return = $this->fail($ajax, 'invalid'); return;
            } else {
                $ctx['time_spent'] = max(0, (int) $this->input->post('time_spent'));
            }
        }

        $result = $item->isQuiz()
            ? $this->subs()->recordQuiz($item, $answers, $ctx)
            : $this->subs()->recordPoll($item, $answers, $ctx);
        if(!empty($result['ok']) && $item->isQuiz() && $item->getMode() === 'exam') {
            $this->session->setFor('Pulse', 'examstart_' . $item->name, null);
        }

        if($ajax) { $event->return = $this->json($result); return; }

        // Non-cached custom forms may submit without JS and receive an HTML page.
        require_once(__DIR__ . '/src/PulseRenderer.php');
        $renderer = $this->wire(new PulseRenderer($item));
        $title = "<h1>" . $this->wire('sanitizer')->entities($item->title) . "</h1>";
        if(!empty($result['ok'])) {
            if($item->isQuiz()) {
                $quizBody = (isset($result['mode']) && $result['mode'] === 'personality')
                    ? $renderer->renderOutcome($result)
                    : $renderer->renderQuizResult($result);
            } else {
                $quizBody = $renderer->renderResults($result['results']);
            }
            $body = $title . $quizBody;
        } else {
            $body = $title . "<p>" . $this->errorText($result['code'] ?? 'error') . "</p>";
        }
        $event->return = $this->htmlPage($body, $item->kind);
    }

    /**
     * GET /pulse/results?name=… — current poll results (respects visibility).
     */
    public function handleResults(HookEvent $event) {
        $name = $this->requestItemName($this->input->get('name'));
        $item = $name ? $this->pulses()->get($name) : null;
        if(!$item || !$item->status || !$item->isPoll()) { $event->return = $this->json(['ok' => false, 'code' => 'not_found']); return; }

        $ctx = $this->subs()->buildContext(false);
        $submitted = $this->subs()->hasSubmitted($item, $ctx);
        if(!$this->subs()->resultsVisible($item, $submitted)) {
            $event->return = $this->json(['ok' => false, 'code' => 'hidden']); return;
        }
        $event->return = $this->json(['ok' => true, 'results' => $this->subs()->getPollResults($item)]);
    }

    protected function requestItemName($value) {
        $name = strtolower(trim((string) $value));
        return preg_match('/^[a-z][a-z0-9_-]*$/', $name) ? $name : '';
    }

    /**
     * GET /pulse/certificate/<token> — HTML certificate for a passed exam.
     */
    public function handleCertificate(HookEvent $event) {
        $token = $event->arguments('token');
        if(!$token) {
            $segs = $this->input->urlSegments;
            $token = $segs ? end($segs) : '';
        }
        $token = preg_replace('/[^0-9a-f-]/', '', (string) $token);

        $subId = $this->subs()->verifyCertificateToken($token);
        $row = $subId ? $this->subs()->getSubmissionRow($subId) : null;
        if(!$row || empty($row['passed'])) {
            http_response_code(404);
            $event->return = $this->htmlPage('<p>' . $this->_('Certificate not found.') . '</p>');
            return;
        }

        $item = $this->pulses()->getById($row['pulse_id']);
        if(!$item || empty($item->settings['certificate'])) {
            http_response_code(404);
            $event->return = $this->htmlPage('<p>' . $this->_('Certificate not found.') . '</p>');
            return;
        }

        $event->return = $this->renderCertificate($item, $row);
    }

    protected function renderCertificate($item, array $row) {
        $san = $this->wire('sanitizer');
        $title = $san->entities($item->title);
        $name = !empty($row['lead_name']) ? $san->entities($row['lead_name']) : $this->_('Participant');
        $score = (int) $row['score']; $max = (int) $row['max_score'];
        $percent = $max > 0 ? (int) round($score / $max * 100) : 0;
        $date = date('Y-m-d', (int) $row['created']);
        $cssUrl = $san->entities1($this->wire('config')->urls->siteModules . 'Pulse/assets/pulse.css');

        $body = "<div class=\"pulse-certificate\">"
            . "<h1>" . $this->_('Certificate of Completion') . "</h1>"
            . "<p class=\"pulse-cert-name\">{$name}</p>"
            . "<p>" . sprintf($this->_('has successfully completed %s'), "<strong>{$title}</strong>") . "</p>"
            . "<p class=\"pulse-cert-score\">" . sprintf($this->_('Score: %1$d / %2$d (%3$d%%)'), $score, $max, $percent) . "</p>"
            . "<p class=\"pulse-cert-date\">{$date}</p>"
            . "</div>";

        return "<!doctype html><html><head><meta charset=\"utf-8\">"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
            . "<title>" . $this->_('Certificate') . " — {$title}</title>"
            . "<link rel=\"stylesheet\" href=\"{$cssUrl}\">"
            . "<style>.pulse-certificate{max-width:640px;margin:6vh auto;padding:48px;border:6px double #2d6cdf;"
            . "border-radius:12px;text-align:center;font-family:Georgia,serif}"
            . ".pulse-cert-name{font-size:1.8em;font-weight:700;margin:.6em 0}"
            . ".pulse-cert-score{font-size:1.2em;color:#137333}@media print{.pulse-cert-noprint{display:none}}</style>"
            . "</head><body>{$body}"
            . "<p class=\"pulse-cert-noprint\" style=\"text-align:center\"><button onclick=\"window.print()\">"
            . $this->_('Print') . "</button></p></body></html>";
    }

    protected function fail($ajax, $code) {
        if($ajax) return $this->json(['ok' => false, 'code' => $code]);
        return $this->htmlPage('<p>' . $this->errorText($code) . '</p>');
    }

    protected function errorText($code) {
        $map = [
            'closed' => $this->_('This is closed.'),
            'already' => $this->_('You have already responded.'),
            'attempts' => $this->_('No attempts remaining.'),
            'csrf' => $this->_('Security token expired, please reload.'),
            'timeout' => $this->_('Time limit exceeded.'),
            'spam' => $this->_('Too many requests, please wait.'),
            'invalid' => $this->_('Invalid submission.'),
            'error' => $this->_('Something went wrong.'),
        ];
        return isset($map[$code]) ? $map[$code] : $map['error'];
    }

    protected function htmlPage($body, $kind = 'poll') {
        $cssUrl = $this->wire('config')->urls($this) . 'assets/pulse.css';
        $css = $this->wire('sanitizer')->entities1($cssUrl);
        $kind = $kind === 'quiz' ? 'quiz' : 'poll';
        return "<!doctype html><html><head><meta charset=\"utf-8\">"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
            . "<link rel=\"stylesheet\" href=\"{$css}\"></head><body>"
            . "<div class=\"pulse pulse--{$kind}\">{$body}</div>"
            . "<p><a href=\"javascript:history.back()\">&larr; " . $this->_('Back') . "</a></p>"
            . "</body></html>";
    }


    /* ---------------------------------------------------------------------
     * Install / uninstall
     * ------------------------------------------------------------------ */

    public function ___install() {
        $pulses = $this->wire(new Pulses());
        $pulses->install();

        // Generate a per-install salt for vote/IP hashing.
        if(empty($this->hashSalt)) {
            $salt = bin2hex(random_bytes(16));
            $data = $this->wire('modules')->getModuleConfigData($this);
            $data['hashSalt'] = $salt;
            $this->wire('modules')->saveModuleConfigData($this, $data);
            $this->hashSalt = $salt;
        }

        // Create the admin page Setup → Pulse.
        $parent = $this->wire('pages')->get('name=setup');
        $page = $this->wire('pages')->get('parent=' . $parent->id . ', name=pulse');
        if(!$page->id) {
            $page = $this->wire(new Page());
            $page->template = 'admin';
            $page->parent = $parent;
            $page->name = 'pulse';
            $page->title = 'Pulse';
            $page->process = $this;
            $page->save();
            $this->message($this->_('Created admin page at Setup → Pulse'));
        }
    }

    public function ___uninstall() {
        $pulses = $this->wire(new Pulses());
        $pulses->uninstall();

        $parent = $this->wire('pages')->get('name=setup');
        $page = $this->wire('pages')->get('parent=' . $parent->id . ', name=pulse');
        if($page->id) {
            $page->delete();
            $this->message($this->_('Removed admin page from Setup → Pulse'));
        }
    }

    /* ---------------------------------------------------------------------
     * Admin screens
     * ------------------------------------------------------------------ */

    public function ___execute() {
        $this->enqueueAdminAssets();
        $this->headline('Pulse');
        $this->browserTitle('Pulse');

        $items = $this->pulses()->getAll(true);

        $base = $this->wire('sanitizer')->entities1($this->wire('page')->url);
        $polls = $items->find('kind=poll');
        $quizzes = $items->find('kind=quiz');
        $published = $items->find('status=1')->count();
        $drafts = $items->find('status=0')->count();
        $canEdit = $this->wire('user')->hasPermission('pulse-edit');

        $out = "<div class='pulse-admin pulse-admin--dashboard uk-container uk-container-expand'>";
        $out .= "<div class='pulse-admin__hero uk-flex uk-flex-between uk-flex-top uk-flex-wrap uk-grid-small uk-margin-medium-bottom' uk-grid>"
            . "<div class='uk-width-expand'><div class='pulse-admin__eyebrow uk-text-uppercase'>" . $this->_('Engagement') . "</div>"
            . "<h2 class='uk-h2 uk-margin-remove'>" . $this->_('Polls and quizzes') . "</h2>"
            . "<p class='uk-text-muted uk-margin-small-top'>" . $this->_('Create shortcodes, collect responses, and review results from one focused workspace.') . "</p></div>";
        if($this->wire('user')->hasPermission('pulse-edit')) {
            $out .= "<div class='pulse-admin__actions uk-width-auto@s uk-flex uk-flex-wrap uk-flex-right uk-grid-small'>"
                . $this->adminButton($base . 'edit/?kind=poll', $this->_('Add poll'), 'plus', 'primary')
                . $this->adminButton($base . 'edit/?kind=quiz', $this->_('Add quiz'), 'plus')
                . "</div>";
        }
        $out .= "</div>";

        $out .= "<div class='pulse-admin__metrics uk-child-width-1-5@l uk-child-width-1-3@m uk-child-width-1-2@s uk-grid-small uk-margin-medium-bottom' uk-grid>"
            . $this->metricCard($this->_('Total'), $items->count())
            . $this->metricCard($this->_('Polls'), $polls->count())
            . $this->metricCard($this->_('Quizzes'), $quizzes->count())
            . $this->metricCard($this->_('Published'), $published)
            . $this->metricCard($this->_('Drafts'), $drafts)
            . "</div>";

        $out .= "<div class='pulse-admin__grid uk-child-width-1-1 uk-grid-small' uk-grid>";
        $out .= $this->renderList($this->_('Polls'), $polls, 'poll');
        $out .= $this->renderList($this->_('Quizzes'), $quizzes, 'quiz');
        $out .= "</div>";

        if($canEdit) {
            $out .= "<div class='pulse-admin__action-row uk-margin-top'>"
                . $this->adminButton($base . 'import/', $this->_('Import JSON'), 'upload')
                . $this->adminPostButton($base . 'demo/', $this->_('Install demo'), 'magic', 'install_demo')
                . $this->adminButton($this->moduleConfigUrl(), $this->_('Settings'), 'cog')
                . "</div>";
        }

        $out .= "</div>";
        return $out;
    }

    protected function renderList($heading, WireArray $items, $kind) {
        $sanitizer = $this->wire('sanitizer');
        $base = $sanitizer->entities1($this->wire('page')->url);
        $canEdit = $this->wire('user')->hasPermission('pulse-edit');
        $items->sort('-modified');

        $out = "<div><section class='pulse-admin__panel pulse-admin__panel--{$kind} uk-card uk-card-default'>"
            . "<div class='uk-card-header'>"
            . "<h3 class='uk-card-title uk-margin-remove'>" . $sanitizer->entities($heading) . " <span class='uk-badge pulse-admin__count'>" . (int) $items->count() . "</span></h3>"
            . "</div>"
            . "<div class='uk-card-body'>";

        if(!$items->count()) {
            $icon = $kind === 'poll' ? 'bar-chart' : 'check-square-o';
            $copy = $kind === 'poll'
                ? $this->_('Polls collect a single question or a small set of options, then show results by percentage, count, or only after closing.')
                : $this->_('Quizzes support graded scoring, personality outcomes, exam timing, attempts, progress, review messages, and certificates.');
            $token = $kind === 'poll' ? '[[pulse:poll name="favorite-language"]]' : '[[pulse:quiz name="general-knowledge"]]';
            $out .= "<div class='pulse-admin__empty uk-panel'><i class='fa fa-{$icon}'></i><p class='uk-text-muted'>" . $sanitizer->entities($copy) . "</p>"
                . "<dl class='pulse-admin__details uk-description-list uk-description-list-divider'>"
                . "<dt>" . $this->_('Embed token') . "</dt><dd><code class='pulse-admin__shortcode'>" . $sanitizer->entities($token) . "</code></dd>"
                . "<dt>" . $this->_('Best for') . "</dt><dd>" . ($kind === 'poll' ? $this->_('Feedback, voting, preference checks') : $this->_('Assessments, lead magnets, training checks')) . "</dd>"
                . "</dl></div>";
            $out .= "</div></section></div>";
            return $out;
        }

        /** @var MarkupAdminDataTable $table */
        $table = $this->wire('modules')->get('MarkupAdminDataTable');
        $table->setEncodeEntities(false);
        $cols = [$this->_('Name'), $this->_('Title')];
        if($kind === 'quiz') $cols[] = $this->_('Mode');
        $cols = array_merge($cols, [$this->_('Status'), $this->_('Shortcode'), $this->_('Updated'), $this->_('Actions')]);
        $table->headerRow($cols);
        $token = $canEdit ? $this->session->CSRF->renderInput() : '';

        foreach($items as $item) {
            /** @var PulseItem $item */
            $status = $item->status
                ? "<span class='uk-label'>" . $this->_('Published') . "</span>"
                : "<span class='uk-label uk-label-warning'>" . $this->_('Draft') . "</span>";
            $shortcode = "<code class='pulse-admin__shortcode'>" . $sanitizer->entities($item->getShortcode()) . "</code>";
            $analyticsTitle = $sanitizer->entities1($this->_('Analytics'));
            $actions = "<span class='pulse-admin__row-actions'><a href='{$base}stats/?id={$item->id}' title='{$analyticsTitle}'><i class='fa fa-bar-chart'></i></a>";
            if($canEdit) {
                $confirm = json_encode(sprintf($this->_('Delete "%s"?'), $item->title), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                $exportCsvTitle = $sanitizer->entities1($this->_('Export CSV'));
                $exportJsonTitle = $sanitizer->entities1($this->_('Export JSON'));
                $cloneTitle = $sanitizer->entities1($this->_('Clone'));
                $deleteTitle = $sanitizer->entities1($this->_('Delete'));
                $actions .= "<a href='{$base}export/?id={$item->id}&format=csv' title='{$exportCsvTitle}'><i class='fa fa-table'></i></a>"
                    . "<a href='{$base}export/?id={$item->id}&format=json' title='{$exportJsonTitle}'><i class='fa fa-download'></i></a>"
                    . "<form method='post' action='{$base}clone/?id={$item->id}' style='display:inline'>{$token}"
                    . "<button type='submit' name='clone' value='1' class='ui-button-link' title='{$cloneTitle}'><i class='fa fa-copy'></i></button></form>"
                    . "<form method='post' action='{$base}delete/?id={$item->id}' style='display:inline' onclick=\"return confirm({$confirm})\">{$token}"
                    . "<button type='submit' name='delete' value='1' class='ui-button-link' title='{$deleteTitle}'><i class='fa fa-trash'></i></button></form>";
            }
            $actions .= "</span>";

            $itemUrl = $canEdit ? "{$base}edit/?id={$item->id}" : "{$base}stats/?id={$item->id}";
            $row = ["<a href='{$itemUrl}'><strong>" . $sanitizer->entities($item->name) . "</strong></a>",
                $sanitizer->entities($item->title)];
            if($kind === 'quiz') $row[] = $sanitizer->entities($item->getMode());
            $row = array_merge($row, [$status, $shortcode, $this->humanDate($item->modified), $actions]);
            $table->row($row);
        }

        $out .= "<div class='pulse-admin__table'>" . $table->render() . "</div>";
        $out .= "</div></section></div>";
        return $out;
    }

    protected function enqueueAdminAssets() {
        $url = $this->wire('config')->urls($this) . 'assets/';
        $this->wire('config')->styles->add($url . 'admin.css');
    }

    protected function moduleConfigUrl() {
        return $this->wire('config')->urls->admin . 'module/edit?name=ProcessPulse';
    }

    protected function adminButton($href, $label, $icon = '', $style = '') {
        $san = $this->wire('sanitizer');
        $cls = 'uk-button pulse-admin-btn ' . ($style === 'primary' ? 'uk-button-primary' : 'uk-button-default');
        $iconHtml = $icon !== '' ? "<i class='fa fa-" . $san->name($icon) . "'></i> " : '';
        return "<a class='{$cls}' href='" . $san->entities1($href) . "'>{$iconHtml}" . $san->entities($label) . "</a>";
    }

    protected function adminPostButton($action, $label, $icon, $name, $style = '') {
        $san = $this->wire('sanitizer');
        $cls = 'uk-button pulse-admin-btn ' . ($style === 'primary' ? 'uk-button-primary' : 'uk-button-default');
        $iconHtml = $icon !== '' ? "<i class='fa fa-" . $san->name($icon) . "'></i> " : '';
        return "<form method='post' action='" . $san->entities1($action) . "' class='pulse-admin__inline-form'>"
            . $this->session->CSRF->renderInput()
            . "<button type='submit' class='{$cls}' name='" . $san->name($name) . "' value='1'>{$iconHtml}" . $san->entities($label) . "</button></form>";
    }

    protected function metricCard($label, $value, $note = '') {
        $san = $this->wire('sanitizer');
        $out = "<div><div class='pulse-admin__metric uk-card uk-card-default uk-card-small uk-card-body'>"
            . "<span class='pulse-admin__metric-label uk-text-meta uk-text-uppercase'>" . $san->entities($label) . "</span>"
            . "<span class='pulse-admin__metric-value'>" . $san->entities((string) $value) . "</span>";
        if($note !== '') $out .= "<span class='pulse-admin__metric-note uk-text-small uk-text-muted'>" . $san->entities($note) . "</span>";
        return $out . "</div></div>";
    }

    protected function humanDate($timestamp) {
        $timestamp = (int) $timestamp;
        if($timestamp <= 0) return '';
        $day = date('Y-m-d', $timestamp);
        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $time = date('H:i', $timestamp);
        if($day === $today) return sprintf($this->_('Today, %s'), $time);
        if($day === $yesterday) return sprintf($this->_('Yesterday, %s'), $time);
        if(date('Y', $timestamp) === date('Y')) return date('M j, H:i', $timestamp);
        return date('M j, Y H:i', $timestamp);
    }

    protected function renderBuilderHeader(PulseItem $item, $id) {
        $san = $this->wire('sanitizer');
        $base = $san->entities1($this->wire('page')->url);
        $kind = $item->isQuiz() ? $this->_('Quiz') : $this->_('Poll');
        $title = $id ? sprintf($this->_('Edit %s'), $kind) : sprintf($this->_('New %s'), $kind);
        $label = $id ? $item->title : $this->_('Draft setup');
        $status = $item->status
            ? "<span class='uk-label'>" . $this->_('Published') . "</span>"
            : "<span class='uk-label uk-label-warning'>" . $this->_('Draft') . "</span>";
        $shortcode = $item->name !== ''
            ? "<code class='pulse-admin__shortcode'>" . $san->entities($item->getShortcode()) . "</code>"
            : "<span class='uk-text-muted'>" . $this->_('Name the item to generate its embed token.') . "</span>";

        $actions = $id ? $this->adminButton($base . "stats/?id={$id}", $this->_('Analytics'), 'bar-chart') : '';
        $actions .= $this->adminButton('../', $this->_('Back'), 'arrow-left')
            . "<button type='submit' name='submit_save' value='1' class='uk-button uk-button-primary pulse-admin-btn'><i class='fa fa-save'></i> " . $this->_('Save') . "</button>";

        return "<div class='pulse-admin__hero pulse-admin__hero--builder uk-flex uk-flex-between uk-flex-top uk-flex-wrap uk-grid-small uk-margin-medium-bottom' uk-grid>"
            . "<div class='uk-width-expand'><div class='pulse-admin__eyebrow uk-text-uppercase'>" . $san->entities($kind) . " builder</div>"
            . "<h2 class='uk-h2 uk-margin-remove'>" . $san->entities($title) . "</h2>"
            . "<div class='pulse-admin__meta uk-margin-small-top'>{$status}<span>" . $san->entities($label) . "</span>{$shortcode}</div></div>"
            . "<div class='pulse-admin__actions uk-width-auto@s uk-flex uk-flex-wrap uk-flex-right uk-grid-small'>{$actions}</div>"
            . "</div>";
    }

    protected function percentBar($percent) {
        $percent = max(0, min(100, (int) $percent));
        return "<div class='pulse-admin__percent'><div class='pulse-admin__percent-bar'><span style='width:{$percent}%'></span></div><strong>{$percent}%</strong></div>";
    }

    /* ---------------------------------------------------------------------
     * Builder (edit) screen
     * ------------------------------------------------------------------ */

    public function ___executeEdit() {
        if(!$this->wire('user')->hasPermission('pulse-edit')) throw new WirePermissionException();
        $this->enqueueAdminAssets();
        $this->breadcrumb('../', 'Pulse');
        $sanitizer = $this->wire('sanitizer');
        $base = $this->wire('page')->url;

        $id = (int) $this->input->get('id');
        if($id) {
            $item = $this->pulses()->getById($id);
            if(!$item) throw new WireException('Item not found');
            $this->headline('');
        } else {
            $item = $this->wire(new PulseItem());
            $item->kind = $this->input->get('kind') === 'quiz' ? 'quiz' : 'poll';
            $this->headline('');
        }

        // Handle save
        if($this->input->post('submit_save')) {
            if(!$this->wire('user')->hasPermission('pulse-edit')) throw new WirePermissionException();
            $this->session->CSRF->validate(); // throws on failure
            $savedId = $this->processSave($id);
            if($savedId) {
                $this->message($this->_('Saved'));
                $this->session->redirect("{$base}edit/?id={$savedId}");
            }
        }

        // Build minimal form (CSRF + hidden payload + mount + submit)
        /** @var InputfieldForm $form */
        $form = $this->wire('modules')->get('InputfieldForm');
        $form->attr('id', 'pulse-edit-form');
        $form->attr('method', 'post');

        $h = $this->wire('modules')->get('InputfieldHidden');
        $h->attr('name', 'pulse_payload');
        $h->attr('id', 'pulse_payload');
        $form->add($h);

        $mount = $this->wire('modules')->get('InputfieldMarkup');
        $mount->skipLabel = Inputfield::skipLabelHeader;
        $mount->value = $this->builderBootstrap($item)
            . "<div class='pulse-admin pulse-admin--builder uk-container uk-container-expand'>"
            . $this->renderBuilderHeader($item, $id)
            . "<div id='pulse-builder-root'></div></div>";
        $form->add($mount);

        return $form->render();
    }

    /**
     * Enqueue builder assets and return an inline bootstrap <script> for the item.
     *
     * @param PulseItem $item
     * @return string
     */
    protected function builderBootstrap(PulseItem $item) {
        $config = $this->wire('config');
        $url = $config->urls($this) . 'assets/';
        $config->styles->add($url . 'pulse.css');
        $config->styles->add($url . 'builder.css');
        $config->scripts->add($url . 'builder.js');

        $base = $this->wire('page')->url;
        $csrf = $this->session->CSRF;
        $data = [
            'data' => $this->itemToPayload($item),
            'endpoints' => [
                'upload' => $base . 'upload/',
                'preview' => $base . 'preview/',
            ],
            'csrf' => ['name' => $csrf->getTokenName(), 'value' => $csrf->getTokenValue()],
            'assetsUrl' => $config->urls->assets . 'Pulse/',
            'formId' => 'pulse-edit-form',
            'payloadName' => 'pulse_payload',
        ];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return "<script>window.PulseBuilder=$json;</script>";
    }

    /**
     * Build a Pulse item from the posted JSON payload and save it.
     *
     * @param int $existingId
     * @return int|false
     */
    protected function processSave($existingId) {
        $payload = json_decode((string) $this->input->post('pulse_payload'), true);
        if(!is_array($payload)) {
            $this->error($this->_('Invalid form data'));
            return false;
        }

        $item = $this->payloadToItem($payload, $existingId);
        $personality = $item->isQuiz() && $item->getMode() === 'personality';

        $savedId = $this->pulses()->save($item);
        if(!$savedId) return false;

        // Personality: outcome_points used temp (1-based) keys; remap to real outcome ids.
        // If remap fails, roll back new items so the DB is not left with corrupt temp keys.
        if($personality && !$this->remapOutcomePoints($savedId)) {
            if(!$existingId) $this->pulses()->delete($savedId);
            $this->error($this->_('Could not finalize outcome points. Please try again.'));
            return false;
        }
        return $savedId;
    }

    /**
     * Convert a stored Pulse item into the builder payload array.
     *
     * @param PulseItem $item
     * @return array
     */
    protected function itemToPayload(PulseItem $item) {
        $outcomes = [];
        foreach($item->getOutcomes() as $o) {
            $outcomes[] = [
                'id' => (int) $o->id,
                'label' => $o->label,
                'description' => $o->description,
                'image' => $o->image,
                'min_score' => $o->min_score,
                'max_score' => $o->max_score,
            ];
        }

        $questions = [];
        foreach($item->getQuestions() as $q) {
            $options = [];
            foreach($q->getOptions() as $o) {
                if($o->archived) continue;
                // present outcome_points keyed by real id (builder reads numeric keys as ids)
                $points = [];
                foreach($o->outcome_points as $oid => $pts) $points[(string) $oid] = (int) $pts;
                $options[] = [
                    'id' => (int) $o->id,
                    'label' => $o->label,
                    'image' => $o->image,
                    'is_correct' => (int) $o->is_correct,
                    'match_value' => $o->match_value,
                    'outcome_points' => $points,
                ];
            }
            $questions[] = [
                'id' => (int) $q->id,
                'type' => $q->type,
                'text' => $q->text,
                'explanation' => $q->explanation,
                'hint' => $q->hint,
                'required' => (int) $q->required,
                'points' => (int) $q->points,
                'options' => $options,
            ];
        }

        return [
            'id' => (int) $item->id,
            'name' => $item->name,
            'title' => $item->title,
            'intro' => $item->intro,
            'kind' => $item->kind,
            'status' => (int) $item->status,
            'open_at' => $item->open_at,
            'close_at' => $item->close_at,
            'settings' => $item->settings ?: new \stdClass(),
            'questions' => $questions,
            'outcomes' => $outcomes,
        ];
    }

    /**
     * Build a Pulse item object from a builder payload (no DB write).
     *
     * @param array $p
     * @param int $existingId
     * @return PulseItem
     */
    public function payloadToItem(array $p, $existingId = 0) {
        $item = $this->wire(new PulseItem());
        if($existingId) $item->id = (int) $existingId;
        elseif(!empty($p['id'])) $item->id = (int) $p['id'];

        $item->name = isset($p['name']) ? $p['name'] : '';
        $item->title = isset($p['title']) ? $p['title'] : '';
        $item->intro = isset($p['intro']) ? $p['intro'] : '';
        $item->kind = (isset($p['kind']) && $p['kind'] === 'quiz') ? 'quiz' : 'poll';
        $item->status = !empty($p['status']) ? 1 : 0;
        $openAt = !empty($p['open_at']) ? max(0, (int) $p['open_at']) : 0;
        $closeAt = !empty($p['close_at']) ? max(0, (int) $p['close_at']) : 0;
        if($openAt && $closeAt && $closeAt <= $openAt) $closeAt = 0;
        $item->open_at = $openAt ?: null;
        $item->close_at = $closeAt ?: null;
        $item->settings = $this->normalizeSettings(isset($p['settings']) && is_array($p['settings']) ? $p['settings'] : []);

        $personality = $item->isQuiz() && $item->getMode() === 'personality';

        $outcomesIn = isset($p['outcomes']) && is_array($p['outcomes']) ? array_values($p['outcomes']) : [];
        $outcomes = [];
        foreach($outcomesIn as $o) {
            $minScore = (isset($o['min_score']) && $o['min_score'] !== '') ? max(0, (int) $o['min_score']) : null;
            $maxScore = (isset($o['max_score']) && $o['max_score'] !== '') ? max(0, (int) $o['max_score']) : null;
            if($minScore !== null && $maxScore !== null && $maxScore < $minScore) {
                $tmp = $minScore;
                $minScore = $maxScore;
                $maxScore = $tmp;
            }
            $outcomes[] = [
                'id' => !empty($o['id']) ? (int) $o['id'] : 0,
                'label' => isset($o['label']) ? $o['label'] : '',
                'description' => isset($o['description']) ? $o['description'] : '',
                'image' => isset($o['image']) ? $this->assetFileName($o['image']) : null,
                'min_score' => $minScore,
                'max_score' => $maxScore,
            ];
        }
        $item->setOutcomes($outcomes);

        $questions = [];
        foreach((isset($p['questions']) && is_array($p['questions']) ? $p['questions'] : []) as $q) {
            $type = isset($q['type']) ? (string) $q['type'] : 'radio';
            if(!in_array($type, ['radio', 'checkbox', 'text', 'boolean'], true)) $type = 'radio';
            $options = [];
            foreach((isset($q['options']) && is_array($q['options']) ? $q['options'] : []) as $o) {
                $row = [
                    'id' => !empty($o['id']) ? (int) $o['id'] : 0,
                    'label' => isset($o['label']) ? $o['label'] : '',
                    'image' => isset($o['image']) ? $this->assetFileName($o['image']) : null,
                    'is_correct' => !empty($o['is_correct']) ? 1 : 0,
                    'match_value' => (isset($o['match_value']) && $o['match_value'] !== '') ? $o['match_value'] : null,
                ];
                if($personality && isset($o['outcome_points']) && is_array($o['outcome_points'])) {
                    $row['outcome_points'] = $this->normalizeOutcomePoints($o['outcome_points'], $outcomesIn);
                }
                $options[] = $row;
            }
            $questions[] = [
                'id' => !empty($q['id']) ? (int) $q['id'] : 0,
                'type' => $type,
                'text' => isset($q['text']) ? $q['text'] : '',
                'image' => isset($q['image']) ? $this->assetFileName($q['image']) : null,
                'explanation' => isset($q['explanation']) ? $q['explanation'] : '',
                'hint' => isset($q['hint']) ? $q['hint'] : '',
                'required' => !empty($q['required']) ? 1 : 0,
                'points' => isset($q['points']) && $q['points'] !== '' ? max(0, (int) $q['points']) : 1,
                'options' => $options,
            ];
        }
        $item->setQuestions($questions);

        return $item;
    }

    protected function normalizeSettings(array $settings) {
        $out = $settings;

        foreach(['multiple', 'allow_other', 'show_counts', 'share', 'shuffle_questions', 'shuffle_options', 'progress_bar', 'show_correct', 'certificate'] as $key) {
            if(array_key_exists($key, $out)) $out[$key] = !empty($out[$key]) ? 1 : 0;
        }

        foreach(['min_select', 'max_select', 'pick_random', 'time_limit', 'max_attempts'] as $key) {
            if(array_key_exists($key, $out)) $out[$key] = max(0, (int) $out[$key]);
        }
        if(array_key_exists('pass_percent', $out)) $out['pass_percent'] = max(0, min(100, (int) $out['pass_percent']));

        $choices = [
            'dedupe' => ['cookie_ip', 'user', 'soft'],
            'result_visibility' => ['after_vote', 'after_close', 'admin_only'],
            'mode' => ['graded', 'personality', 'exam'],
            'pagination' => ['all', 'one_per_page'],
            'result_mode' => ['highest', 'range'],
        ];
        foreach($choices as $key => $allowed) {
            if(array_key_exists($key, $out) && !in_array($out[$key], $allowed, true)) {
                unset($out[$key]);
            }
        }

        if(isset($out['require_fields']) && is_array($out['require_fields'])) {
            $fields = [];
            foreach($out['require_fields'] as $field) {
                if(in_array($field, ['name', 'email'], true) && !in_array($field, $fields, true)) $fields[] = $field;
            }
            $out['require_fields'] = $fields;
        } elseif(array_key_exists('require_fields', $out)) {
            $out['require_fields'] = [];
        }

        if(isset($out['result_messages']) && is_array($out['result_messages'])) {
            $messages = [];
            foreach($out['result_messages'] as $msg) {
                if(!is_array($msg)) continue;
                $messages[] = [
                    'min' => isset($msg['min']) ? max(0, min(100, (int) $msg['min'])) : 0,
                    'max' => isset($msg['max']) ? max(0, min(100, (int) $msg['max'])) : 100,
                    'title' => isset($msg['title']) ? (string) $msg['title'] : '',
                    'text' => isset($msg['text']) ? (string) $msg['text'] : '',
                ];
            }
            $out['result_messages'] = $messages;
        } elseif(array_key_exists('result_messages', $out)) {
            $out['result_messages'] = [];
        }

        foreach(['notify_admin', 'notify_user'] as $key) {
            if(isset($out[$key]) && is_array($out[$key])) {
                $cfg = ['on' => !empty($out[$key]['on']) ? 1 : 1];
                foreach(['to', 'subject', 'body'] as $field) {
                    if(isset($out[$key][$field])) $cfg[$field] = (string) $out[$key][$field];
                }
                $out[$key] = $cfg;
            } elseif(array_key_exists($key, $out)) {
                $out[$key] = !empty($out[$key]) ? 1 : 0;
            }
        }

        if(isset($out['video']) && is_array($out['video'])) {
            $provider = isset($out['video']['provider']) ? (string) $out['video']['provider'] : '';
            $gate = isset($out['video']['gate']) ? (string) $out['video']['gate'] : 'ended';
            if(!in_array($provider, ['youtube', 'vimeo', 'mp4'], true)) {
                unset($out['video']);
            } else {
                $src = isset($out['video']['src']) ? (string) $out['video']['src'] : '';
                if($provider === 'mp4') $src = $this->mp4Source($src);
                $out['video'] = [
                    'provider' => $provider,
                    'src' => $src,
                    'gate' => in_array($gate, ['ended', 'percent', 'button'], true) ? $gate : 'ended',
                    'percent' => isset($out['video']['percent']) ? max(0, min(100, (int) $out['video']['percent'])) : 90,
                ];
            }
        } elseif(array_key_exists('video', $out)) {
            unset($out['video']);
        }

        return $out;
    }

    protected function assetFileName($file) {
        $file = basename((string) $file);
        return preg_match('/^[A-Za-z0-9._-]+\\.(?:jpe?g|png|gif|webp)$/i', $file) ? $file : null;
    }

    protected function mp4Source($src) {
        $src = trim((string) $src);
        if(preg_match('~^https://[^\\s?#]+\\.mp4(?:[?#][^\\s]*)?$~i', $src)) return $src;
        if(preg_match('~^[a-z][a-z0-9+.-]*://~i', $src)) return '';
        $file = basename($src);
        return preg_match('/^[A-Za-z0-9._-]+\\.mp4$/i', $file) ? $file : '';
    }

    /**
     * Map builder outcome-point keys to temporary 1-based indexes so a freshly
     * created personality quiz can be saved before outcome ids exist. The keys
     * are 'idx:N' for new outcomes or a numeric outcome id for existing ones.
     */
    protected function normalizeOutcomePoints(array $points, array $outcomesIn) {
        $out = [];
        foreach($points as $key => $pts) {
            $pts = (int) $pts;
            if($pts === 0) continue;
            $idx = null;
            if(strpos((string) $key, 'idx:') === 0) {
                $idxRaw = substr((string) $key, 4);
                if(ctype_digit($idxRaw)) {
                    $idx = (int) $idxRaw;
                    if(!isset($outcomesIn[$idx])) $idx = null;
                }
            } else {
                // existing outcome id — find its position in the incoming list
                foreach($outcomesIn as $i => $o) {
                    if(!empty($o['id']) && (int) $o['id'] === (int) $key) { $idx = $i; break; }
                }
            }
            if($idx !== null) $out[$idx + 1] = $pts; // temp 1-based key
        }
        return $out;
    }

    /**
     * After a personality save, replace temp outcome-point keys with real ids.
     *
     * Returns true on success, false if the item could not be loaded or re-saved.
     * Callers should treat false as a critical error and roll back if possible.
     *
     * @param int $id
     * @return bool
     */
    protected function remapOutcomePoints($id) {
        $item = $this->pulses()->getById($id);
        if(!$item) return false;
        $outcomes = array_values($item->getOutcomes()->getArray());
        if(!$outcomes) return false;

        foreach($item->getQuestions() as $q) {
            foreach($q->getOptions() as $o) {
                $new = [];
                foreach($o->outcome_points as $temp => $pts) {
                    $i = (int) $temp - 1;
                    if(isset($outcomes[$i])) $new[(int) $outcomes[$i]->id] = (int) $pts;
                }
                $o->outcome_points = $new;
            }
        }
        return $this->pulses()->save($item) !== false;
    }

    /**
     * Create a new item from an exported payload, renaming on name conflict.
     *
     * @param array $data
     * @param string|null $newName
     * @return int|false new item id
     */
    public function importData(array $data, $newName = null) {
        $base = $newName !== null && $newName !== '' ? $newName : (isset($data['name']) ? $data['name'] : 'pulse');
        $data['name'] = $this->pulses()->uniqueName($base);
        unset($data['id']);
        $item = $this->payloadToItem($data, 0);
        $id = $this->pulses()->save($item);
        if(!$id) return false;
        if($item->isQuiz() && $item->getMode() === 'personality') {
            if(!$this->remapOutcomePoints($id)) {
                $this->pulses()->delete($id);
                $this->error($this->_('Could not finalize outcome points during import. Please try again.'));
                return false;
            }
        }
        return $id;
    }

    /**
     * Duplicate an item under a new (auto-deduplicated) name.
     *
     * @param int $id
     * @param string|null $newName
     * @return int|false
     */
    public function cloneItem($id, $newName = null) {
        $data = $this->pulses()->exportData($id);
        if(!$data) return false;
        return $this->importData($data, $newName !== null ? $newName : (isset($data['name']) ? $data['name'] : 'pulse'));
    }

    protected function installDemoItems() {
        $this->removeDemoItems();
        $this->installDemoAssets();

        $files = [
            'poll.json' => 'demo-planet-mission-vote',
            'graded-quiz.json' => 'demo-car-logo-quiz',
            'hollywood-quiz.json' => 'demo-hollywood-movie-quiz',
            'personality.json' => 'demo-spacecraft-personality',
            'exam.json' => 'demo-launch-operations-exam',
        ];
        $created = 0;
        foreach($files as $file => $name) {
            $path = __DIR__ . '/examples/' . $file;
            if(!is_file($path)) continue;
            $data = json_decode((string) file_get_contents($path), true);
            if(!is_array($data)) continue;
            if($this->importData($data, $name)) $created++;
        }
        $page = $created ? $this->installDemoPage() : null;
        return ['created' => $created, 'page' => $page];
    }

    protected function installDemoAssets() {
        $source = __DIR__ . '/examples/assets/';
        if(!is_dir($source)) return;

        $target = $this->assetsPath();
        foreach(glob($source . 'demo-*.*') as $file) {
            if(!is_file($file)) continue;
            $name = basename($file);
            if(!$this->assetFileName($name)) continue;
            @copy($file, $target . $name);
        }
    }

    protected function removeDemoItems() {
        $bases = [
            'demo-favorite-language',
            'demo-general-knowledge',
            'demo-which-animal',
            'demo-safety-exam',
            'demo-planet-mission-vote',
            'demo-car-logo-quiz',
            'demo-hollywood-movie-quiz',
            'demo-spacecraft-personality',
            'demo-launch-operations-exam',
        ];
        foreach($this->pulses()->getAll(true) as $item) {
            foreach($bases as $base) {
                if($item->name === $base || strpos($item->name, $base . '-') === 0) {
                    $this->pulses()->delete($item);
                    break;
                }
            }
        }
    }

    protected function installDemoPage() {
        $field = $this->installDemoField();
        $template = $field ? $this->installDemoTemplate($field) : null;
        if(!$template) return null;

        $this->installDemoTemplateFile();

        $pages = $this->wire('pages');
        $parent = $pages->get('/');
        $root = $pages->get('/pulse-demo/');
        if(!$root->id) {
            $root = $this->wire(new Page());
            $root->template = $template;
            $root->parent = $parent;
            $root->name = 'pulse-demo';
        } else {
            $root->of(false);
            if($root->template->name !== $template->name) $root->template = $template;
        }

        $root->title = 'Pulse demo';
        $root->pulse = $this->demoIndexContent();
        $root->save();

        foreach($this->demoPages() as $demo) {
            $page = $pages->get("parent={$root->id}, name={$demo['name']}, include=all");
            if(!$page->id) {
                $page = $this->wire(new Page());
                $page->template = $template;
                $page->parent = $root;
                $page->name = $demo['name'];
            } else {
                $page->of(false);
                if($page->template->name !== $template->name) $page->template = $template;
            }
            $page->title = $demo['title'];
            $page->pulse = $demo['content'];
            $page->save();
        }

        $root->of(false);
        $root->pulse = $this->demoIndexContent($root);
        $root->save('pulse');
        return $root;
    }

    protected function installDemoField() {
        $fields = $this->wire('fields');
        $modules = $this->wire('modules');
        $field = $fields->get('pulse');
        if(!$field->id) {
            $field = $this->wire(new Field());
            $field->name = 'pulse';
            $field->label = 'Pulse';
            $field->type = $modules->get('FieldtypeTextarea');
            $field->inputfieldClass = 'InputfieldTextarea';
        }

        $formatters = (array) $field->textformatters;
        if(!in_array('TextformatterPulse', $formatters, true)) {
            $formatters[] = 'TextformatterPulse';
            $field->textformatters = array_values(array_filter($formatters));
        }
        $field->description = 'Demo field with Pulse embed tokens.';
        $field->save();
        return $field;
    }

    protected function installDemoTemplate(Field $field) {
        $templates = $this->wire('templates');
        $fields = $this->wire('fields');
        $template = $templates->get('pulse-demo');
        if(!$template->id) {
            $template = $this->wire(new Template());
            $template->name = 'pulse-demo';
            $template->label = 'Pulse demo';
            $template->filename = 'pulse-demo.php';
            $fg = $this->wire(new Fieldgroup());
            $fg->name = 'pulse-demo';
            $fg->add($fields->get('title'));
            $fg->add($field);
            $fg->save();
            $template->fieldgroup = $fg;
            $template->save();
        }

        if(!$template->fieldgroup->has('title')) $template->fields->add($fields->get('title'));
        if(!$template->fieldgroup->has($field->name)) $template->fields->add($field);
        $template->fields->save();
        $template->save();
        return $template;
    }

    protected function installDemoTemplateFile() {
        $path = $this->wire('config')->paths->templates . 'pulse-demo.php';
        if(is_file($path)) {
            $current = (string) file_get_contents($path);
            $isGenerated = strpos($current, 'Generated by Pulse module demo installer') !== false
                || (strpos($current, '<!doctype html>') !== false && strpos($current, '$page->pulse') !== false);
            if(!$isGenerated) return;
        }
        if(!is_writable(dirname($path))) return;

        $php = <<<'PHP'
<?php namespace ProcessWire;

/**
 * Generated by Pulse module demo installer.
 *
 * This template uses ProcessWire markup regions to keep the site shell from
 * /site/templates/_main.php while replacing demo-specific regions.
 */

?>
<p id="topnav" pw-remove></p>

<h1 id="headline" pw-replace><?= $sanitizer->entities($page->title) ?></h1>

<div id="html-head" pw-append>
    <style>
        body {
            background: #f3f5f8;
            color: #16181d;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        #html-body {
            max-width: 1120px;
            margin-inline: auto;
            padding: clamp(28px, 5vw, 56px) clamp(18px, 10%, 120px) clamp(48px, 7vw, 88px);
        }

        #headline {
            max-width: 960px;
            margin: 0 0 16px;
            font-size: clamp(34px, 5vw, 58px);
            line-height: 1;
            letter-spacing: 0;
        }

        #headline a {
            color: inherit;
            text-decoration: none;
        }

        #content {
            max-width: 960px;
            color: #252a32;
        }

        #content .pulse {
            width: 100%;
            max-width: none;
            margin: 22px 0 0;
            padding: clamp(18px, 3vw, 30px);
            border: 1px solid rgba(20, 24, 31, .1);
            border-radius: 8px;
            box-shadow: 0 16px 44px rgba(16, 24, 40, .11);
        }

        #content > p:first-child {
            max-width: 720px;
            margin-top: 0;
            color: #5e6877;
            font-size: 18px;
            line-height: 1.5;
        }

        .pulse-demo-shell {
            display: grid;
            gap: 22px;
        }

        .pulse-demo-kicker {
            display: inline-flex;
            width: fit-content;
            align-items: center;
            min-height: 28px;
            padding: 0 10px;
            border: 1px solid rgba(20, 24, 31, .12);
            border-radius: 999px;
            background: #fff;
            color: #2563eb;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .pulse-demo-panel {
            padding: clamp(18px, 3vw, 28px);
            border: 1px solid rgba(20, 24, 31, .1);
            border-radius: 8px;
            background: rgba(255, 255, 255, .86);
            box-shadow: 0 14px 38px rgba(16, 24, 40, .08);
        }

        .pulse-demo-lede {
            max-width: 720px;
            margin: 10px 0 0;
            color: #5e6877;
            font-size: 18px;
            line-height: 1.55;
        }

        .pulse-demo-back {
            margin: 0 0 16px;
        }

        .pulse-demo-back a {
            color: #2563eb;
            font-weight: 700;
            text-decoration: none;
        }

        .pulse-demo-back a:hover {
            text-decoration: underline;
        }

        .pulse-demo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 14px;
            margin: 24px 0 0;
            padding: 0;
            list-style: none;
        }

        .pulse-demo-grid a {
            display: block;
            min-height: 154px;
            padding: 20px;
            border: 1px solid rgba(20, 24, 31, .09);
            border-radius: 8px;
            background: #fff;
            box-shadow: 0 12px 28px rgba(16, 24, 40, .07);
            color: #16181d;
            text-decoration: none;
        }

        .pulse-demo-grid a:hover {
            border-color: rgba(45, 108, 223, .35);
            transform: translateY(-1px);
        }

        .pulse-demo-grid strong {
            display: block;
            font-size: 19px;
            line-height: 1.2;
        }

        .pulse-demo-grid span {
            display: block;
            margin-top: 10px;
            color: #667281;
            line-height: 1.4;
        }

        .pulse-demo-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 18px 0 0;
            padding: 0;
            list-style: none;
        }

        .pulse-demo-meta li {
            padding: 6px 9px;
            border: 1px solid rgba(20, 24, 31, .1);
            border-radius: 999px;
            background: #fff;
            color: #4e5968;
            font-size: 13px;
            line-height: 1;
        }

        .pulse-demo-widget {
            margin-top: 20px;
        }

        .pulse__question {
            background: #fff;
            border-radius: 8px;
        }

        .pulse__option {
            background: #fff;
            border-radius: 8px;
        }

        .pulse__option--image {
            background: #f9fafb;
        }

        .pulse__option-img {
            max-height: 150px;
            object-fit: contain;
            background: #fff;
        }

        @media (max-width: 640px) {
            #content .pulse {
                padding: 16px;
                border-radius: 8px;
            }

            .pulse-demo-panel {
                padding: 16px;
            }
        }
    </style>
</div>

<div id="content" pw-replace>
    <?= $page->pulse ?>
</div>
PHP;
        file_put_contents($path, $php);
    }

    protected function demoPages() {
        return [
            [
                'name' => 'planet-mission-vote',
                'title' => 'Planet mission vote',
                'summary' => 'Multi-select poll with limits, Other option, counts, and share.',
                'tags' => ['Poll', 'Multiple choice', 'Other option', 'Live results'],
                'content' => $this->demoDetailContent(
                    'Poll',
                    'A public vote for choosing future space mission destinations.',
                    ['Multi-select limits', 'Other free text', 'Visible counts', 'Share button'],
                    '[[pulse:poll name="demo-planet-mission-vote"]]'
                ),
            ],
            [
                'name' => 'car-logo-quiz',
                'title' => 'Car logo recognition quiz',
                'summary' => 'Graded quiz with real logo images and mixed question types.',
                'tags' => ['Graded quiz', 'Images', 'Mixed questions', 'Result messages'],
                'content' => $this->demoDetailContent(
                    'Graded quiz',
                    'A brand-recognition quiz with real logo assets and several answer formats.',
                    ['Image options', 'Radio and checkbox questions', 'Boolean check', 'Text matching'],
                    '[[pulse:quiz name="demo-car-logo-quiz"]]'
                ),
            ],
            [
                'name' => 'hollywood-movie-quiz',
                'title' => 'Hollywood movie quiz',
                'summary' => 'One visual clue per slide with score-based titles at the end.',
                'tags' => ['Graded quiz', 'Question images', 'One slide per question', 'Ranked result'],
                'content' => $this->demoDetailContent(
                    'Graded quiz',
                    'A cinematic quiz that shows one image clue per slide and assigns a movie-fan rank after submission.',
                    ['Prompt images', 'One question per page', 'Progress bar', 'Score titles'],
                    '[[pulse:quiz name="demo-hollywood-movie-quiz"]]'
                ),
            ],
            [
                'name' => 'spacecraft-personality',
                'title' => 'Spacecraft personality quiz',
                'summary' => 'Personality quiz that maps answers to outcomes.',
                'tags' => ['Personality', 'Outcomes', 'Scoring map'],
                'content' => $this->demoDetailContent(
                    'Personality',
                    'A playful quiz that routes answers into outcome profiles.',
                    ['Outcome points', 'Highest-score result', 'Progress bar', 'Share button'],
                    '[[pulse:quiz name="demo-spacecraft-personality"]]'
                ),
            ],
            [
                'name' => 'launch-operations-exam',
                'title' => 'Launch operations certification',
                'summary' => 'Timed exam with attempts, pass score, certificate, and lead capture.',
                'tags' => ['Exam', 'Timer', 'Certificate', 'Lead capture'],
                'content' => $this->demoDetailContent(
                    'Exam',
                    'A certification-style flow for serious assessments.',
                    ['One question per page', 'Time limit', 'Pass threshold', 'Certificate link'],
                    '[[pulse:quiz name="demo-launch-operations-exam"]]'
                ),
            ],
        ];
    }

    protected function demoIndexContent(?Page $root = null) {
        $out = '<div class="pulse-demo-shell"><section class="pulse-demo-panel"><span class="pulse-demo-kicker">Live demo</span>'
            . '<p class="pulse-demo-lede">Each example is a real ProcessWire page using the <code>pulse</code> field and Pulse embed tokens. Open any demo to test the public widget flow.</p>';
        $out .= '<ul class="pulse-demo-grid">';
        foreach($this->demoPages() as $demo) {
            $href = $root && $root->id ? $root->url . $demo['name'] . '/' : './' . $demo['name'] . '/';
            $out .= '<li><a href="' . $this->wire('sanitizer')->entities1($href) . '"><strong>'
                . $this->wire('sanitizer')->entities($demo['title']) . '</strong><span>'
                . $this->wire('sanitizer')->entities($demo['summary']) . '</span></a></li>';
        }
        $out .= '</ul></section></div>';
        return $out;
    }

    protected function demoDetailContent($type, $description, array $features, $token) {
        $san = $this->wire('sanitizer');
        $out = '<div class="pulse-demo-shell">';
        $out .= '<p class="pulse-demo-back"><a href="../">All demos</a></p>';
        $out .= '<section class="pulse-demo-panel"><span class="pulse-demo-kicker">' . $san->entities($type) . '</span>';
        $out .= '<p class="pulse-demo-lede">' . $san->entities($description) . '</p>';
        $out .= '<ul class="pulse-demo-meta">';
        foreach($features as $feature) $out .= '<li>' . $san->entities($feature) . '</li>';
        $out .= '</ul></section>';
        $out .= '<div class="pulse-demo-widget">' . $token . '</div>';
        $out .= '</div>';
        return $out;
    }

    public function ___executeDelete() {
        if(!$this->wire('user')->hasPermission('pulse-edit')) throw new WirePermissionException();
        if(!$this->input->post('delete') || !$this->session->CSRF->hasValidToken()) {
            $this->error($this->_('Invalid request'));
            $this->session->redirect('../');
            return '';
        }
        $id = (int) $this->input->get('id');
        $item = $id ? $this->pulses()->getById($id) : null;
        if($item) {
            $this->pulses()->delete($id);
            $this->message(sprintf($this->_('Deleted "%s"'), $item->title));
        } else {
            $this->error($this->_('Item not found'));
        }
        $this->session->redirect('../');
        return '';
    }

    public function ___executeDemo() {
        if(!$this->wire('user')->hasPermission('pulse-edit')) throw new WirePermissionException();
        if(!$this->input->post('install_demo') || !$this->session->CSRF->hasValidToken()) {
            $this->error($this->_('Invalid request'));
            $this->session->redirect('../');
            return '';
        }

        $demo = $this->installDemoItems();
        $created = isset($demo['created']) ? (int) $demo['created'] : 0;
        if($created) {
            $page = isset($demo['page']) && $demo['page'] instanceof Page ? $demo['page'] : null;
            $message = sprintf($this->_('Demo installed: %d items'), $created);
            if($page && $page->id) $message .= ' — ' . sprintf($this->_('Demo page: %s'), $page->url);
            $this->message($message);
        } else {
            $this->error($this->_('Demo could not be installed'));
        }
        $this->session->redirect('../');
        return '';
    }

    /* ---------------------------------------------------------------------
     * Analytics, export/import, retention
     * ------------------------------------------------------------------ */

    public function ___executeStats() {
        $this->enqueueAdminAssets();
        $this->breadcrumb('../', 'Pulse');
        $id = (int) $this->input->get('id');
        $item = $id ? $this->pulses()->getById($id) : null;
        if(!$item) { $this->error($this->_('Item not found')); $this->session->redirect('../'); return ''; }
        $this->headline(sprintf($this->_('Analytics: %s'), $item->title));

        $san = $this->wire('sanitizer');
        $base = $this->wire('sanitizer')->entities1($this->wire('page')->url);
        $status = $item->status
            ? "<span class='uk-label'>" . $this->_('Published') . "</span>"
            : "<span class='uk-label uk-label-warning'>" . $this->_('Draft') . "</span>";
        $mode = $item->isQuiz()
            ? "<span class='uk-text-muted'>" . sprintf($this->_('Mode: %s'), $san->entities($item->getMode())) . "</span>"
            : "<span class='uk-text-muted'>" . $this->_('Poll results') . "</span>";
        $out = "<div class='pulse-admin pulse-admin--stats uk-container uk-container-expand'>";
        $out .= "<div class='pulse-admin__hero uk-flex uk-flex-between uk-flex-top uk-flex-wrap uk-grid-small uk-margin-medium-bottom' uk-grid>"
            . "<div class='uk-width-expand'><div class='pulse-admin__eyebrow uk-text-uppercase'>" . $san->entities($item->kind) . "</div>"
            . "<h2 class='uk-h2 uk-margin-remove'>" . $san->entities($item->title) . "</h2>"
            . "<div class='pulse-admin__meta uk-margin-small-top'>{$status}{$mode}<code class='pulse-admin__shortcode'>" . $san->entities($item->getShortcode()) . "</code></div></div>"
            . "<div class='pulse-admin__actions uk-width-auto@s uk-flex uk-flex-wrap uk-flex-right uk-grid-small'>"
            . $this->adminButton($base . "edit/?id={$id}", $this->_('Edit'), 'pencil')
            . $this->adminButton('../', $this->_('Back'), 'arrow-left')
            . "</div></div>";

        if($item->isPoll()) {
            $stats = $this->subs()->getPollStats($item);
            $out .= "<div class='pulse-admin__stats-list uk-child-width-1-4@m uk-child-width-1-2@s uk-grid-small uk-margin-medium-bottom' uk-grid>"
                . $this->metricCard($this->_('Total votes'), (int) $stats['total'])
                . $this->metricCard($this->_('Options'), count($stats['options']))
                . "</div>";
            $table = $this->wire('modules')->get('MarkupAdminDataTable');
            $table->setEncodeEntities(false);
            $table->headerRow([$this->_('Option'), $this->_('Count'), '%']);
            foreach($stats['options'] as $o) {
                $table->row([$san->entities($o['label']), (int) $o['count'], $this->percentBar($o['percent'])]);
            }
            $out .= "<section class='pulse-admin__panel pulse-admin__panel--results uk-card uk-card-default'><div class='uk-card-header'><h3 class='uk-card-title uk-margin-remove'>" . $this->_('Results') . "</h3></div>"
                . "<div class='uk-card-body pulse-admin__table'>" . $table->render() . "</div></section>";
        } else {
            $stats = $this->subs()->getQuizStats($item);
            $out .= "<div class='pulse-admin__stats-list uk-child-width-1-4@m uk-child-width-1-2@s uk-grid-small uk-margin-medium-bottom' uk-grid>";
            $out .= $this->metricCard($this->_('Completed'), (int) $stats['completed'], sprintf($this->_('%d started'), (int) $stats['started']));
            $out .= $this->metricCard($this->_('Drop-off'), (int) $stats['drop_off'] . '%');
            if($stats['mode'] !== 'personality') {
                $out .= $this->metricCard($this->_('Average score'), (int) $stats['avg_percent'] . '%');
                $out .= $this->metricCard($this->_('Pass rate'), (int) $stats['pass_rate'] . '%');
            }
            if($stats['mode'] === 'exam' && $stats['avg_time'] !== null) {
                $out .= $this->metricCard($this->_('Average time'), (int) $stats['avg_time'] . 's');
            }
            $out .= "</div>";

            if($stats['mode'] === 'personality') {
                $table = $this->wire('modules')->get('MarkupAdminDataTable');
                $table->setEncodeEntities(false);
                $table->headerRow([$this->_('Outcome'), $this->_('Count')]);
                foreach($stats['outcomes'] as $o) $table->row([$san->entities($o['label']), (int) $o['count']]);
                $out .= "<section class='pulse-admin__panel pulse-admin__panel--outcomes uk-card uk-card-default'><div class='uk-card-header'><h3 class='uk-card-title uk-margin-remove'>" . $this->_('Outcomes') . "</h3></div>"
                    . "<div class='uk-card-body pulse-admin__table'>" . $table->render() . "</div></section>";
            } else {
                $table = $this->wire('modules')->get('MarkupAdminDataTable');
                $table->setEncodeEntities(false);
                $table->headerRow([$this->_('Question'), $this->_('Answered'), $this->_('% correct')]);
                foreach($stats['questions'] as $q) {
                    $table->row([$san->entities($q['text']), (int) $q['answered'], $this->percentBar($q['percent_correct'])]);
                }
                $out .= "<section class='pulse-admin__panel pulse-admin__panel--questions uk-card uk-card-default'><div class='uk-card-header'><h3 class='uk-card-title uk-margin-remove'>" . $this->_('Questions') . "</h3></div>"
                    . "<div class='uk-card-body pulse-admin__table'>" . $table->render() . "</div></section>";
            }
        }

        if($this->wire('user')->hasPermission('pulse-edit')) {
            $out .= "<div class='pulse-admin__action-row uk-margin-top'>"
                . $this->adminButton($base . "export/?id={$id}&format=csv", $this->_('Export answers (CSV)'), 'table')
                . $this->adminButton($base . "export/?id={$id}&format=json", $this->_('Export definition (JSON)'), 'download')
                . "</div>";
            $out .= $this->renderPurgeForm($item);
        }
        return $out . "</div>";
    }

    protected function renderPurgeForm($item) {
        $base = $this->wire('sanitizer')->entities1($this->wire('page')->url);
        $token = $this->session->CSRF->renderInput();
        $confirm = json_encode($this->_('Apply retention to old submissions?'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return "<section class='pulse-admin__panel uk-card uk-card-default uk-margin-top'><div class='uk-card-header'><h3 class='uk-card-title uk-margin-remove'>" . $this->_('Data retention (GDPR)') . "</h3></div>"
            . "<div class='uk-card-body'><form method='post' action='{$base}purge/?id={$item->id}' class='pulse-admin__retention' "
            . "onsubmit=\"return confirm({$confirm})\">"
            . "<label class='pulse-admin__retention-field'><span class='pulse-admin__field-label'>" . $this->_('Older than (days)') . "</span><input class='pulse-admin__control' type='number' name='days' value='365' min='1'></label>"
            . "<div class='pulse-admin__retention-field pulse-admin__retention-mode'><span class='pulse-admin__field-label'>" . $this->_('Mode') . "</span><div class='pulse-admin__radio-row'>"
            . "<label class='pulse-admin__radio'><input type='radio' name='mode' value='anonymize' checked><span>" . $this->_('Anonymize') . "</span></label>"
            . "<label class='pulse-admin__radio'><input type='radio' name='mode' value='delete'><span>" . $this->_('Delete') . "</span></label>"
            . "</div></div>"
            . $token
            . "<button type='submit' class='uk-button uk-button-danger pulse-admin-btn'>" . $this->_('Run now') . "</button></form></div></section>";
    }

    public function ___executePurge() {
        if(!$this->wire('user')->hasPermission('pulse-edit')) throw new WirePermissionException();
        $id = (int) $this->input->get('id');
        $item = $id ? $this->pulses()->getById($id) : null;
        if($item && $this->session->CSRF->hasValidToken()) {
            $days = max(1, (int) $this->input->post('days'));
            $mode = $this->input->post('mode') === 'delete' ? 'delete' : 'anonymize';
            $n = $this->subs()->purgeOld($item, time() - $days * 86400, $mode);
            $this->message(sprintf($this->_('%1$d submissions %2$s'), $n, $mode === 'delete' ? $this->_('deleted') : $this->_('anonymized')));
        } else {
            $this->error($this->_('Could not run retention'));
        }
        $this->session->redirect("../stats/?id={$id}");
        return '';
    }

    public function ___executeExport() {
        if(!$this->wire('user')->hasPermission('pulse-edit')) throw new WirePermissionException();
        $id = (int) $this->input->get('id');
        $item = $id ? $this->pulses()->getById($id) : null;
        if(!$item) { $this->error($this->_('Item not found')); $this->session->redirect('../'); return ''; }
        $format = $this->input->get('format') === 'csv' ? 'csv' : 'json';

        if($format === 'csv') {
            $data = $this->subs()->exportCsv($item);
            $filename = 'pulse-' . $item->name . '-answers.csv';
            $type = 'text/csv';
        } else {
            $data = $this->pulses()->exportJson($id);
            $filename = 'pulse-' . $item->name . '.json';
            $type = 'application/json';
        }

        header('Content-Type: ' . $type . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        echo $data;
        exit;
    }

    public function ___executeClone() {
        if(!$this->wire('user')->hasPermission('pulse-edit')) throw new WirePermissionException();
        if(!$this->input->post('clone') || !$this->session->CSRF->hasValidToken()) {
            $this->error($this->_('Invalid request'));
            $this->session->redirect('../');
            return '';
        }
        $id = (int) $this->input->get('id');
        $newId = $this->cloneItem($id);
        if($newId) {
            $this->message($this->_('Cloned'));
            $this->session->redirect("../edit/?id={$newId}");
        } else {
            $this->error($this->_('Clone failed'));
            $this->session->redirect('../');
        }
        return '';
    }

    public function ___executeImport() {
        if(!$this->wire('user')->hasPermission('pulse-edit')) throw new WirePermissionException();
        $this->enqueueAdminAssets();
        $this->breadcrumb('../', 'Pulse');
        $this->headline($this->_('Import JSON'));
        $base = $this->wire('sanitizer')->entities1($this->wire('page')->url);

        if($this->input->post('json') !== null && $this->session->CSRF->hasValidToken()) {
            $data = json_decode((string) $this->input->post('json'), true);
            if(is_array($data) && (!empty($data['name']) || !empty($data['questions']))) {
                $newId = $this->importData($data);
                if($newId) {
                    $this->message($this->_('Imported'));
                    $this->session->redirect("../edit/?id={$newId}");
                    return '';
                }
            }
            $this->error($this->_('Invalid JSON'));
        }

        $token = $this->session->CSRF->renderInput();
        return "<div class='pulse-admin pulse-admin--import uk-container uk-container-expand'><div class='pulse-admin__hero uk-flex uk-flex-between uk-flex-top uk-flex-wrap uk-grid-small uk-margin-medium-bottom' uk-grid><div class='uk-width-expand'>"
            . "<div class='pulse-admin__eyebrow uk-text-uppercase'>" . $this->_('Import') . "</div>"
            . "<h2 class='uk-h2 uk-margin-remove'>" . $this->_('JSON definition') . "</h2>"
            . "<p class='uk-text-muted uk-margin-small-top'>" . $this->_('Paste an exported Pulse definition to create a new poll or quiz.') . "</p>"
            . "</div><div class='pulse-admin__actions uk-width-auto@s uk-flex uk-flex-wrap uk-flex-right uk-grid-small'>" . $this->adminButton('../', $this->_('Back'), 'arrow-left') . "</div></div>"
            . "<section class='pulse-admin__panel uk-card uk-card-default'><div class='uk-card-body'>"
            . "<form method='post' action='{$base}import/' class='pulse-admin__form'>"
            . "<textarea class='uk-textarea' name='json' rows='16'></textarea>"
            . $token
            . "<p><button type='submit' class='uk-button uk-button-primary pulse-admin-btn'><i class='fa fa-upload'></i> " . $this->_('Import') . "</button></p></form>"
            . "</div></section></div>";
    }

    /* ---------------------------------------------------------------------
     * AJAX endpoints used by the builder
     * ------------------------------------------------------------------ */

    public function ___executeUpload() {
        if(!$this->wire('user')->hasPermission('pulse-edit')) throw new WirePermissionException();
        header('Content-Type: application/json; charset=utf-8');
        if(!$this->session->CSRF->hasValidToken()) {
            return json_encode(['ok' => false, 'error' => 'csrf']);
        }

        $dir = $this->assetsPath();
        $upload = $this->wire(new WireUpload('image'));
        $upload->setMaxFiles(1);
        $upload->setOverwrite(false);
        $upload->setDestinationPath($dir);
        $upload->setValidExtensions(['jpg', 'jpeg', 'png', 'gif', 'webp']);

        $files = $upload->execute();
        if(!count($files)) {
            return json_encode(['ok' => false, 'error' => 'upload', 'messages' => $upload->getErrors()]);
        }
        $file = $files[0];
        if(!$this->isValidUploadedImage($dir . $file)) {
            @unlink($dir . $file);
            return json_encode(['ok' => false, 'error' => 'invalid_image']);
        }
        return json_encode([
            'ok' => true,
            'file' => $file,
            'url' => $this->wire('config')->urls->assets . 'Pulse/' . rawurlencode($file),
        ]);
    }

    public function ___executePreview() {
        if(!$this->wire('user')->hasPermission('pulse-edit')) throw new WirePermissionException();
        if(!$this->session->CSRF->hasValidToken()) return '<em>Security token expired.</em>';
        $payload = json_decode((string) $this->input->post('payload'), true);
        if(!is_array($payload)) return '<em>No data.</em>';

        $item = $this->payloadToItem($payload, 0);
        $item->id = 0; // ensure transient; getQuestions/getOutcomes use in-memory set data
        $item->intro = $item->intro === '' ? '' : $this->wire('sanitizer')->purify($item->intro);

        require_once(__DIR__ . '/src/PulseRenderer.php');
        try {
            $renderer = $this->wire(new PulseRenderer($item));
            return $renderer->render(['preview' => true]);
        } catch(\Throwable $e) {
            return '<em>Preview error: ' . $this->wire('sanitizer')->entities($e->getMessage()) . '</em>';
        }
    }

    /**
     * Ensure and return the upload directory for option/outcome images.
     *
     * @return string
     */
    protected function assetsPath() {
        $dir = $this->wire('config')->paths->assets . 'Pulse/';
        if(!is_dir($dir)) $this->wire('files')->mkdir($dir, true);
        return $dir;
    }

    /**
     * Verify uploaded media is a real raster image, not just a matching extension.
     *
     * @param string $path
     * @return bool
     */
    protected function isValidUploadedImage($path) {
        if(!is_file($path)) return false;
        $info = @getimagesize($path);
        if(!is_array($info) || empty($info['mime'])) return false;
        return in_array($info['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true);
    }

    /* ---------------------------------------------------------------------
     * Module configuration
     * ------------------------------------------------------------------ */

    /* ---------------------------------------------------------------------
     * Module upgrade
     * ------------------------------------------------------------------ */

    public function ___upgrade($fromVersion, $toVersion) {
        // Apply idempotently because older releases used dotted version strings.
        $ts = Pulses::TABLE_SUBMISSIONS;
        try {
            $this->wire('database')->exec("ALTER TABLE `$ts` ADD INDEX `pulse_created` (`pulse_id`,`created`)");
        } catch(\Exception $e) {
            // Fresh installs and already upgraded sites already have the index.
        }
        // Preserve removed questions/outcomes as archived historical rows.
        foreach([Pulses::TABLE_QUESTIONS, Pulses::TABLE_OUTCOMES] as $table) {
            try {
                $this->wire('database')->exec("ALTER TABLE `$table` ADD COLUMN `archived` TINYINT UNSIGNED NOT NULL DEFAULT 0");
            } catch(\Exception $e) {
                // Existing installations may already have completed this upgrade.
            }
        }
        try {
            $this->wire('database')->exec("ALTER TABLE `" . Pulses::TABLE_QUESTIONS . "` ADD COLUMN `image` VARCHAR(255) NULL AFTER `text`");
        } catch(\Exception $e) {
            // Existing installations may already have completed this upgrade.
        }
    }

    /* ---------------------------------------------------------------------
     * Module configuration
     * ------------------------------------------------------------------ */

    public static function getModuleConfigInputfields(array $data) {
        $inputfields = new InputfieldWrapper();
        $modules = wire('modules');
        $data = array_merge(self::$defaultConfig, $data);

        $f = $modules->get('InputfieldText');
        $f->attr('name', 'componentsPath');
        $f->label = 'Components path';
        $f->description = 'Path to custom render templates, relative to /site/templates/';
        $f->notes = 'Templates: <componentsPath>/pulse/<name>.php';
        $f->value = $data['componentsPath'];
        $f->columnWidth = 50;
        $inputfields->add($f);

        $f = $modules->get('InputfieldText');
        $f->attr('name', 'endpointBase');
        $f->label = 'Endpoint base';
        $f->description = 'URL prefix for AJAX endpoints';
        $f->notes = 'Default: pulse → /pulse/state, /pulse/submit, ...';
        $f->value = $data['endpointBase'];
        $f->columnWidth = 50;
        $inputfields->add($f);

        $fs = $modules->get('InputfieldFieldset');
        $fs->label = __('Notifications');
        $fs->description = __('Email delivery settings for poll and quiz notifications.');

        $f = $modules->get('InputfieldRadios');
        $f->attr('name', 'mail_module');
        $f->label = __('Mailer');
        $f->description = __('Select which mailer to use for sending notification emails.');
        $f->notes = __('For more sending options, you can install WireMail modules.');
        $f->addOption('', __('Default (site WireMail setting)'));
        foreach(wire('modules')->find('className^=WireMail') as $m) {
            $name = $m->className();
            if($name === 'WireMail') continue;
            $f->addOption($name, $name);
        }
        $f->value = isset($data['mail_module']) ? $data['mail_module'] : '';
        $fs->add($f);
        $inputfields->add($fs);

        $f = $modules->get('InputfieldMarkup');
        $f->label = 'Embed tokens';
        $f->value = '<p>Use <code>[[pulse:poll name="my-poll"]]</code> or <code>[[pulse:quiz name="my-quiz"]]</code>. Older <code>((poll:name))</code> tokens still render for compatibility.</p>';
        $f->columnWidth = 50;
        $inputfields->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'rateLimit');
        $f->label = 'Rate limit';
        $f->description = 'Max submit requests per IP per window (0 = off)';
        $f->value = (int) $data['rateLimit'];
        $f->columnWidth = 25;
        $inputfields->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'rateWindow');
        $f->label = 'Rate window (s)';
        $f->value = (int) $data['rateWindow'];
        $f->columnWidth = 25;
        $inputfields->add($f);

        $f = $modules->get('InputfieldInteger');
        $f->attr('name', 'dataRetention');
        $f->label = 'Data retention (days)';
        $f->description = 'Auto-purge submissions older than this (0 = keep forever)';
        $f->value = (int) $data['dataRetention'];
        $f->columnWidth = 50;
        $inputfields->add($f);

        $f = $modules->get('InputfieldText');
        $f->attr('name', 'hashSalt');
        $f->label = 'Hash salt';
        $f->description = 'Used to hash voter cookie tokens and IPs. Generated on install.';
        $f->value = $data['hashSalt'];
        $f->collapsed = Inputfield::collapsedYes;
        $f->columnWidth = 50;
        $inputfields->add($f);

        $f = $modules->get('InputfieldCheckbox');
        $f->attr('name', 'debugMode');
        $f->label = 'Debug mode';
        $f->description = 'Detailed logging to pulse-debug';
        $f->attr('checked', $data['debugMode'] ? 'checked' : '');
        $f->columnWidth = 50;
        $inputfields->add($f);

        return $inputfields;
    }
}
