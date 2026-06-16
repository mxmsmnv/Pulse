<?php namespace ProcessWire;

require_once(__DIR__ . '/PulseItem.php');

/**
 * PulseSubmissions — accepts answers, deduplicates, aggregates results.
 *
 * Identifiers (cookie token, IP) are stored only as salted hashes. Quiz scoring
 * arrives in a later stage; this stage covers polls end-to-end.
 */
class PulseSubmissions extends Wire {

    const COOKIE = 'pulse_v';

    /* ---------------------------------------------------------------------
     * Voter context
     * ------------------------------------------------------------------ */

    /**
     * Build the voter context (hashes + user id) for the current request.
     *
     * @param bool $createCookie issue a voter cookie if missing (use only when recording)
     * @return array { voter_hash, ip_hash, user_id, dedupe }
     */
    public function buildContext($createCookie = false) {
        $salt = $this->salt();
        $session = $this->wire('session');
        $user = $this->wire('user');

        $token = $this->wire('input')->cookie(self::COOKIE);
        $token = $token ? $this->wire('sanitizer')->alphanumeric($token) : '';
        if(!$token && $createCookie) {
            $token = bin2hex(random_bytes(16));
            $this->setCookie($token);
        }

        $ip = (string) $session->getIP();

        return [
            'voter_hash' => $token ? hash('sha256', $salt . '|v|' . $token) : null,
            'ip_hash' => $ip ? hash('sha256', $salt . '|ip|' . $ip) : null,
            'user_id' => $user && $user->id && !$user->isGuest() ? (int) $user->id : null,
        ];
    }

    protected function setCookie($token) {
        $secure = $this->wire('config')->https;
        // 1 year; lax; httponly
        setcookie(self::COOKIE, $token, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $token;
    }

    protected function salt() {
        try {
            $cfg = $this->wire('modules')->getModuleConfigData('ProcessPulse');
            if(!empty($cfg['hashSalt'])) return (string) $cfg['hashSalt'];
        } catch(\Exception $e) {}
        return 'pulse'; // fallback; install generates a real salt
    }

    /* ---------------------------------------------------------------------
     * Eligibility
     * ------------------------------------------------------------------ */

    /**
     * Can this visitor submit? Returns true or an error code string.
     *
     * @param PulseItem $item
     * @param array $context
     * @return bool|string  true | 'closed' | 'already'
     */
    public function canSubmit(PulseItem $item, array $context) {
        if(!$item->isOpen()) return 'closed';

        if($item->isQuiz() && $item->getMode() === 'exam') {
            $max = isset($item->settings['max_attempts']) ? (int) $item->settings['max_attempts'] : 0;
            if($max > 0 && $this->countAttempts($item, $context) >= $max) return 'attempts';
            return true;
        }

        if($this->hasSubmitted($item, $context)) return 'already';
        return true;
    }

    /**
     * Has this visitor already submitted (per the item's dedupe strategy)?
     *
     * @param PulseItem $item
     * @param array $context
     * @return bool
     */
    public function hasSubmitted(PulseItem $item, array $context) {
        return $this->countAttempts($item, $context) > 0;
    }

    /**
     * Count this visitor's submissions for the item (per dedupe identity).
     *
     * @param PulseItem $item
     * @param array $context
     * @return int
     */
    public function countAttempts(PulseItem $item, array $context) {
        $dedupe = isset($item->settings['dedupe']) ? $item->settings['dedupe'] : 'cookie_ip';
        $database = $this->wire('database');
        $ts = Pulses::TABLE_SUBMISSIONS;
        $pid = (int) $item->id;
        if(!$pid) return 0;

        $where = [];
        $params = [':pid' => $pid];

        if($dedupe === 'user') {
            if(empty($context['user_id'])) return 0;
            $where[] = '`user_id` = :uid';
            $params[':uid'] = (int) $context['user_id'];
        } elseif($dedupe === 'soft') {
            if(empty($context['voter_hash'])) return 0;
            $where[] = '`voter_hash` = :vh';
            $params[':vh'] = $context['voter_hash'];
        } else {
            // cookie_ip
            $ors = [];
            if(!empty($context['voter_hash'])) { $ors[] = '`voter_hash` = :vh'; $params[':vh'] = $context['voter_hash']; }
            if(!empty($context['ip_hash'])) { $ors[] = '`ip_hash` = :ih'; $params[':ih'] = $context['ip_hash']; }
            if(!$ors) return 0;
            $where[] = '(' . implode(' OR ', $ors) . ')';
        }

        $sql = "SELECT COUNT(*) FROM `$ts` WHERE `pulse_id` = :pid AND " . implode(' AND ', $where);
        $q = $database->prepare($sql);
        $q->execute($params);
        return (int) $q->fetchColumn();
    }

    /**
     * Rate limit per hashed IP, shared across browser sessions. Returns true if allowed.
     *
     * @param array $context visitor context from buildContext(false)
     * @return bool
     */
    public function checkRateLimit(array $context = []) {
        $limit = (int) $this->cfg('rateLimit', 10);
        $window = (int) $this->cfg('rateWindow', 60);
        if($limit <= 0) return true;
        if($window <= 0) $window = 60;

        $now = time();
        $ipHash = isset($context['ip_hash']) ? (string) $context['ip_hash'] : '';
        $cache = $ipHash !== '' ? $this->wire('cache') : null;
        $cacheKey = $ipHash !== '' ? 'Pulse.rl.' . $ipHash : '';
        $session = $this->wire('session');
        $hits = $cache ? $cache->get($cacheKey) : $session->getFor('Pulse', 'rl');
        if(!is_array($hits)) $hits = [];
        $hits = array_values(array_filter($hits, function($t) use ($now, $window) { return $t > $now - $window; }));
        if(count($hits) >= $limit) {
            if($cache) $cache->save($cacheKey, $hits, $window + 5);
            else $session->setFor('Pulse', 'rl', $hits);
            return false;
        }
        $hits[] = $now;
        if($cache) $cache->save($cacheKey, $hits, $window + 5);
        else $session->setFor('Pulse', 'rl', $hits);
        return true;
    }

    protected function cfg($key, $default) {
        try {
            $c = $this->wire('modules')->getModuleConfigData('ProcessPulse');
            if(isset($c[$key]) && $c[$key] !== '') return $c[$key];
        } catch(\Exception $e) {}
        return $default;
    }

    /* ---------------------------------------------------------------------
     * Record a poll vote
     * ------------------------------------------------------------------ */

    /**
     * Record a poll vote.
     *
     * @param PulseItem $item
     * @param array $answers  qid => optionId | [optionIds]  (from the form)
     * @param array $context
     * @return array { ok, view, results } | { ok:false, code }
     */
    public function recordPoll(PulseItem $item, array $answers, array $context) {
        $can = $this->canSubmit($item, $context);
        if($can !== true) return ['ok' => false, 'code' => $can];

        $multiple = !empty($item->settings['multiple']);
        $minSel = isset($item->settings['min_select']) ? max(0, (int) $item->settings['min_select']) : 0;
        $maxSel = isset($item->settings['max_select']) ? max(0, (int) $item->settings['max_select']) : 0;
        $allowOther = !empty($item->settings['allow_other']);
        $otherRaw = (isset($context['other']) && is_array($context['other'])) ? $context['other'] : [];
        $otherText = [];
        foreach($otherRaw as $qid => $text) {
            $qid = (int) $qid;
            $text = $this->boundedText($text, 512);
            if($qid > 0 && $text !== '') $otherText[$qid] = $text;
        }

        // Map valid (non-archived) option ids to their question id.
        $valid = []; // optionId => questionId
        $required = []; // qid => bool
        foreach($item->getQuestions() as $q) {
            $required[(int) $q->id] = (bool) $q->required;
            foreach($q->getOptions() as $o) {
                if($o->archived) continue;
                $valid[(int) $o->id] = (int) $q->id;
            }
        }

        // Collect chosen option ids per question, validating ownership.
        $chosen = []; // qid => [optionIds]
        $chosenOther = []; // qid => text
        foreach($answers as $qid => $sel) {
            $qid = (int) $qid;
            $ids = is_array($sel) ? $sel : [$sel];
            foreach($ids as $oid) {
                if(!is_scalar($oid)) return ['ok' => false, 'code' => 'invalid'];
                if((string) $oid === '__other') {
                    if(!$allowOther || !isset($required[$qid]) || empty($otherText[$qid])) return ['ok' => false, 'code' => 'invalid'];
                    $chosenOther[$qid] = $otherText[$qid];
                    continue;
                }
                $oid = (int) $oid;
                if(!isset($valid[$oid]) || $valid[$oid] !== $qid) return ['ok' => false, 'code' => 'invalid'];
                $chosen[$qid][] = $oid;
            }
        }

        // Per-question validation.
        foreach($item->getQuestions() as $q) {
            $qid = (int) $q->id;
            $picks = isset($chosen[$qid]) ? array_values(array_unique($chosen[$qid])) : [];
            $chosen[$qid] = $picks;
            $pickCount = count($picks) + (isset($chosenOther[$qid]) ? 1 : 0);

            if($required[$qid] && $pickCount === 0) return ['ok' => false, 'code' => 'invalid'];
            if(!$multiple && $pickCount > 1) return ['ok' => false, 'code' => 'invalid'];
            if($multiple) {
                if($minSel && $pickCount < $minSel) return ['ok' => false, 'code' => 'invalid'];
                if($maxSel && $pickCount > $maxSel) return ['ok' => false, 'code' => 'invalid'];
            }
        }

        $flat = [];
        foreach($chosen as $qid => $ids) foreach($ids as $oid) $flat[] = [$qid, $oid, null];
        foreach($chosenOther as $qid => $text) $flat[] = [$qid, null, $text];
        if(!$flat) return ['ok' => false, 'code' => 'invalid'];

        $lead = $this->validateLead($item, $context);
        if($lead === false) return ['ok' => false, 'code' => 'invalid'];

        $database = $this->wire('database');
        $ts = Pulses::TABLE_SUBMISSIONS;
        $ta = Pulses::TABLE_ANSWERS;

        try {
            $database->beginTransaction();

            $q = $database->prepare("INSERT INTO `$ts`
                (`pulse_id`,`voter_hash`,`ip_hash`,`user_id`,`lead_name`,`lead_email`,`complete`,`created`)
                VALUES (:pid,:vh,:ih,:uid,:ln,:le,1,:created)");
            $q->execute([
                ':pid' => (int) $item->id,
                ':vh' => $context['voter_hash'] ?? null,
                ':ih' => $context['ip_hash'] ?? null,
                ':uid' => $context['user_id'] ?? null,
                ':ln' => $lead['name'],
                ':le' => $lead['email'],
                ':created' => time(),
            ]);
            $subId = (int) $database->lastInsertId();

            $ins = $database->prepare("INSERT INTO `$ta`
                (`submission_id`,`question_id`,`option_id`,`text_answer`) VALUES (:sid,:qid,:oid,:txt)");
            foreach($flat as $pair) {
                $ins->execute([':sid' => $subId, ':qid' => $pair[0], ':oid' => $pair[1], ':txt' => $pair[2]]);
            }

            $database->commit();
        } catch(\Exception $e) {
            if($database->inTransaction()) $database->rollBack();
            $this->wire('log')->save('pulse-errors', 'recordPoll: ' . $e->getMessage());
            return ['ok' => false, 'code' => 'error'];
        }

        $result = ['ok' => true, 'view' => 'results', 'results' => $this->getPollResults($item)];
        $this->notify($item, $result, $lead);
        return $result;
    }

    /* ---------------------------------------------------------------------
     * Record a quiz (graded / exam scoring)
     * ------------------------------------------------------------------ */

    /**
     * Grade and record a quiz submission. Graded/exam scoring; personality
     * arrives in a later stage.
     *
     * @param PulseItem $item
     * @param array $answers  qid => optionId | [optionIds] | text
     * @param array $context
     * @return array result data, or { ok:false, code } on rejection
     */
    public function recordQuiz(PulseItem $item, array $answers, array $context) {
        $can = $this->canSubmit($item, $context);
        if($can !== true) return ['ok' => false, 'code' => $can];

        $mode = $item->getMode();
        if($mode === 'personality') return $this->recordPersonality($item, $answers, $context);

        $lead = $this->validateLead($item, $context);
        if($lead === false) return ['ok' => false, 'code' => 'invalid'];

        $showCorrect = !empty($item->settings['show_correct']);
        $isExam = $mode === 'exam';

        // Exam: grade only the presented questions (pick_random bank); the endpoint
        // validates the signed id list and passes it via context['question_ids'].
        $questions = $item->getQuestions();
        if(!empty($context['question_ids']) && is_array($context['question_ids'])) {
            $allowed = array_map('intval', $context['question_ids']);
            $filtered = $this->wire(new WireArray());
            foreach($questions as $q) if(in_array((int) $q->id, $allowed, true)) $filtered->add($q);
            if($filtered->count()) $questions = $filtered;
        }
        $questionIds = [];
        foreach($questions as $q) $questionIds[(int) $q->id] = true;
        foreach($answers as $qid => $unused) {
            if(!isset($questionIds[(int) $qid])) return ['ok' => false, 'code' => 'invalid'];
        }

        $score = 0; $maxScore = 0;
        $review = [];
        $answerRows = []; // [question_id, option_id|null, text|null, is_correct]

        foreach($questions as $q) {
            $qid = (int) $q->id;
            $maxScore += (int) $q->points;

            $raw = isset($answers[$qid]) ? $answers[$qid] : null;
            if(!$this->validAnswerInput($q, $raw)) return ['ok' => false, 'code' => 'invalid'];
            $hasAnswer = $this->answerProvided($q, $raw);
            if($q->required && !$hasAnswer) return ['ok' => false, 'code' => 'invalid'];

            $graded = $this->gradeQuestion($q, $raw);
            if($graded['correct']) $score += (int) $q->points;

            foreach($graded['rows'] as $row) $answerRows[] = $row;

            $entry = [
                'question' => $q->text,
                'your' => $graded['your'],
                'is_correct' => $graded['correct'],
            ];
            if($q->explanation !== '' && $q->explanation !== null) $entry['explanation'] = $q->explanation;
            if($showCorrect) $entry['correct'] = $graded['answer'];
            $review[] = $entry;
        }

        $percent = $maxScore > 0 ? (int) round($score / $maxScore * 100) : 0;
        $passPercent = isset($item->settings['pass_percent']) ? (int) $item->settings['pass_percent'] : 60;
        $passPercent = max(0, min(100, $passPercent));
        $passed = $percent >= $passPercent;

        $attemptNo = $isExam ? ($this->countAttempts($item, $context) + 1) : 1;
        $timeSpent = $isExam && isset($context['time_spent']) ? (int) $context['time_spent'] : null;

        // persist
        $database = $this->wire('database');
        $ts = Pulses::TABLE_SUBMISSIONS;
        $ta = Pulses::TABLE_ANSWERS;
        try {
            $database->beginTransaction();
            $q = $database->prepare("INSERT INTO `$ts`
                (`pulse_id`,`voter_hash`,`ip_hash`,`user_id`,`lead_name`,`lead_email`,`score`,`max_score`,`passed`,`attempt_no`,`time_spent`,`complete`,`created`)
                VALUES (:pid,:vh,:ih,:uid,:ln,:le,:score,:max,:passed,:attempt,:tspent,1,:created)");
            $q->execute([
                ':pid' => (int) $item->id,
                ':vh' => $context['voter_hash'] ?? null,
                ':ih' => $context['ip_hash'] ?? null,
                ':uid' => $context['user_id'] ?? null,
                ':ln' => $lead['name'],
                ':le' => $lead['email'],
                ':score' => $score,
                ':max' => $maxScore,
                ':passed' => $passed ? 1 : 0,
                ':attempt' => $attemptNo,
                ':tspent' => $timeSpent,
                ':created' => time(),
            ]);
            $subId = (int) $database->lastInsertId();

            $ins = $database->prepare("INSERT INTO `$ta`
                (`submission_id`,`question_id`,`option_id`,`text_answer`,`is_correct`)
                VALUES (:sid,:qid,:oid,:txt,:ic)");
            foreach($answerRows as $row) {
                $ins->execute([
                    ':sid' => $subId,
                    ':qid' => $row[0],
                    ':oid' => $row[1],
                    ':txt' => $row[2],
                    ':ic' => $row[3],
                ]);
            }
            $database->commit();
        } catch(\Exception $e) {
            if($database->inTransaction()) $database->rollBack();
            $this->wire('log')->save('pulse-errors', 'recordQuiz: ' . $e->getMessage());
            return ['ok' => false, 'code' => 'error'];
        }

        $result = [
            'ok' => true,
            'view' => 'quiz_result',
            'mode' => $mode,
            'score' => $score,
            'max_score' => $maxScore,
            'percent' => $percent,
            'passed' => $passed,
            'review' => $review,
        ];
        $msg = $this->pickMessage($item, $percent);
        if($msg) $result['messages'] = $msg;

        if($isExam) {
            $result['attempt_no'] = $attemptNo;
            if($timeSpent !== null) $result['time_spent'] = $timeSpent;
            if($passed && !empty($item->settings['certificate'])) {
                $token = $this->certificateToken($subId);
                $base = $this->safeEndpointBase($this->cfg('endpointBase', 'pulse'));
                $result['certificate_url'] = '/' . $base . '/certificate/' . $token;
            }
        }

        $this->notify($item, $result, $lead);
        return $result;
    }

    /**
     * Persist an elapsed exam attempt without grading submitted answers.
     *
     * @return array {ok:false, code:string}
     */
    public function recordExamTimeout(PulseItem $item, array $context) {
        $can = $this->canSubmit($item, $context);
        if($can !== true) return ['ok' => false, 'code' => $can];

        $questions = $item->getQuestions();
        if(!empty($context['question_ids']) && is_array($context['question_ids'])) {
            $allowed = array_map('intval', $context['question_ids']);
            $filtered = $this->wire(new WireArray());
            foreach($questions as $q) if(in_array((int) $q->id, $allowed, true)) $filtered->add($q);
            if($filtered->count()) $questions = $filtered;
        }
        $maxScore = 0;
        foreach($questions as $q) $maxScore += (int) $q->points;

        $ts = Pulses::TABLE_SUBMISSIONS;
        try {
            $q = $this->wire('database')->prepare("INSERT INTO `$ts`
                (`pulse_id`,`voter_hash`,`ip_hash`,`user_id`,`score`,`max_score`,`passed`,`attempt_no`,`time_spent`,`complete`,`created`)
                VALUES (:pid,:vh,:ih,:uid,0,:max,0,:attempt,:tspent,1,:created)");
            $q->execute([
                ':pid' => (int) $item->id,
                ':vh' => $context['voter_hash'] ?? null,
                ':ih' => $context['ip_hash'] ?? null,
                ':uid' => $context['user_id'] ?? null,
                ':max' => $maxScore,
                ':attempt' => $this->countAttempts($item, $context) + 1,
                ':tspent' => isset($context['time_spent']) ? (int) $context['time_spent'] : null,
                ':created' => time(),
            ]);
        } catch(\Exception $e) {
            $this->wire('log')->save('pulse-errors', 'recordExamTimeout: ' . $e->getMessage());
            return ['ok' => false, 'code' => 'error'];
        }
        return ['ok' => false, 'code' => 'timeout'];
    }

    /* ---------------------------------------------------------------------
     * Certificate token (signed submission id)
     * ------------------------------------------------------------------ */

    /**
     * @param int $submissionId
     * @return string  "<id>.<hmac>"
     */
    public function certificateToken($submissionId) {
        $id = (int) $submissionId;
        // Hyphen separator (not '.') so the URL path segment isn't treated as a file extension.
        return $id . '-' . hash_hmac('sha256', 'cert|' . $id, $this->salt());
    }

    /**
     * Verify a certificate token and return the submission id, or 0 if invalid.
     *
     * @param string $token
     * @return int
     */
    public function verifyCertificateToken($token) {
        if(!is_string($token) || strpos($token, '-') === false) return 0;
        list($id, $sig) = explode('-', $token, 2);
        $id = (int) $id;
        if(!$id) return 0;
        $expected = hash_hmac('sha256', 'cert|' . $id, $this->salt());
        return hash_equals($expected, (string) $sig) ? $id : 0;
    }

    /**
     * Sign a set of presented question ids (pick_random bank integrity).
     *
     * @param int[] $ids
     * @param int $pulseId
     * @return string
     */
    public function signQuestionIds(array $ids, $pulseId) {
        $ids = array_map('intval', $ids);
        sort($ids);
        return hash_hmac('sha256', 'qids|' . (int) $pulseId . '|' . implode(',', $ids), $this->salt());
    }

    /**
     * Verify a posted question-id list against its signature.
     *
     * @param string $csv
     * @param string $sig
     * @param int $pulseId
     * @return int[]  validated ids, or [] if invalid
     */
    public function verifyQuestionIds($csv, $sig, $pulseId) {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $csv))));
        if(!$ids) return [];
        if(!hash_equals($this->signQuestionIds($ids, $pulseId), (string) $sig)) return [];
        return $ids;
    }

    /**
     * Load a completed submission row by id (for certificate rendering).
     *
     * @param int $submissionId
     * @return array|null
     */
    public function getSubmissionRow($submissionId) {
        $ts = Pulses::TABLE_SUBMISSIONS;
        $q = $this->wire('database')->prepare("SELECT * FROM `$ts` WHERE `id` = :id");
        $q->execute([':id' => (int) $submissionId]);
        $row = $q->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /* ---------------------------------------------------------------------
     * Lead capture + notifications
     * ------------------------------------------------------------------ */

    /**
     * Validate lead fields against require_fields.
     *
     * @return array|false  ['name'=>?, 'email'=>?] or false if a required field is missing/invalid
     */
    protected function validateLead(PulseItem $item, array $context) {
        $fields = (isset($item->settings['require_fields']) && is_array($item->settings['require_fields']))
            ? $item->settings['require_fields'] : [];
        $name = isset($context['lead_name']) ? trim((string) $context['lead_name']) : '';
        $email = isset($context['lead_email']) ? trim((string) $context['lead_email']) : '';

        if(in_array('name', $fields, true) && $name === '') return false;
        if($email !== '' && $this->limitString($email, 255) !== $email) return false;
        if(in_array('email', $fields, true)) {
            if($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        }
        if($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;

        $san = $this->wire('sanitizer');
        $email = $email !== '' ? $san->email($email) : null;
        return [
            'name' => $name !== '' ? $this->boundedText($name, 255) : null,
            'email' => $email !== '' ? $email : null,
        ];
    }

    /**
     * Sanitize text for VARCHAR-backed columns and cap it without splitting UTF-8.
     */
    protected function boundedText($value, $max) {
        $text = trim((string) $value);
        $text = $this->limitString($text, $max);
        $text = $this->wire('sanitizer')->text($text, ['maxLength' => (int) $max]);
        return $this->limitString($text, $max);
    }

    protected function limitString($value, $max) {
        $value = (string) $value;
        $max = max(0, (int) $max);
        if($max === 0) return '';
        if(function_exists('mb_substr')) return mb_substr($value, 0, $max, 'UTF-8');
        return substr($value, 0, $max);
    }

    /**
     * Merge-tag map for email/share templates.
     *
     * @return array
     */
    protected function mergeTags(PulseItem $item, array $result, array $lead) {
        $percent = isset($result['percent']) ? $result['percent'] : '';
        return [
            '{title}' => (string) $item->title,
            '{score}' => isset($result['score']) ? (string) $result['score'] : '',
            '{max_score}' => isset($result['max_score']) ? (string) $result['max_score'] : '',
            '{percent}' => $percent === '' ? '' : (string) $percent,
            '{passed}' => isset($result['passed']) ? ($result['passed'] ? $this->_('yes') : $this->_('no')) : '',
            '{outcome}' => isset($result['outcome']['label']) ? (string) $result['outcome']['label'] : '',
            '{date}' => date('Y-m-d'),
            '{name}' => isset($lead['name']) ? (string) $lead['name'] : '',
        ];
    }

    protected function applyTags($template, array $tags) {
        return strtr((string) $template, $tags);
    }

    /**
     * Build a notification message (to/subject/body) with merge-tags applied.
     * Public so the admin can preview templates and tests can assert substitution.
     *
     * @param string $which 'admin' | 'user'
     * @return array { to, subject, body }
     */
    public function buildMessage(PulseItem $item, array $result, array $lead, $which) {
        $s = $item->settings;
        $tags = $this->mergeTags($item, $result, $lead);

        if($which === 'user') {
            $cfg = (isset($s['notify_user']) && is_array($s['notify_user'])) ? $s['notify_user'] : [];
            $subject = !empty($cfg['subject']) ? $cfg['subject'] : $this->_('Your result: {title}');
            $body = !empty($cfg['body']) ? $cfg['body']
                : $this->_("Hi {name},\n\nYour result for {title}: {score}/{max_score} ({percent}%).\n");
            $to = isset($lead['email']) ? $lead['email'] : null;
        } else {
            $cfg = (isset($s['notify_admin']) && is_array($s['notify_admin'])) ? $s['notify_admin'] : [];
            $subject = !empty($cfg['subject']) ? $cfg['subject'] : $this->_('New response: {title}');
            $body = !empty($cfg['body']) ? $cfg['body']
                : $this->_("New submission for {title}.\nScore: {score}/{max_score} ({percent}%)\nName: {name}\nDate: {date}\n");
            $to = !empty($cfg['to']) ? $cfg['to'] : $this->wire('config')->adminEmail;
        }

        return [
            'to' => $this->normalizeMailTo($to),
            'subject' => $this->normalizeMailSubject($this->applyTags($subject, $tags)),
            'body' => $this->applyTags($body, $tags),
        ];
    }

    /**
     * Send admin/user notifications if enabled. Failures are logged, never thrown.
     */
    protected function notify(PulseItem $item, array $result, array $lead) {
        $s = $item->settings;
        $sendAdmin = !empty($s['notify_admin']);
        $sendUser = !empty($s['notify_user']) && !empty($lead['email']);
        if(!$sendAdmin && !$sendUser) return;

        try {
            if($sendAdmin) {
                $m = $this->buildMessage($item, $result, $lead, 'admin');
                if(!empty($m['to'])) $this->sendMail($m['to'], $m['subject'], $m['body']);
            }
            if($sendUser) {
                $m = $this->buildMessage($item, $result, $lead, 'user');
                if(!empty($m['to'])) $this->sendMail($m['to'], $m['subject'], $m['body']);
            }
        } catch(\Exception $e) {
            $this->wire('log')->save('pulse-errors', 'notify: ' . $e->getMessage());
        }
    }

    protected function sendMail($to, $subject, $body) {
        $module = $this->mailModule();
        $mail = $module !== '' ? $this->wire('mail')->new($module) : $this->wire('mail')->new();
        $mail->to($to)->subject($subject)->body($body);
        $mail->send();
    }

    protected function mailModule() {
        try {
            $cfg = (array) $this->wire('modules')->getModuleConfigData('ProcessPulse');
            $module = isset($cfg['mail_module']) ? (string) $cfg['mail_module'] : '';
            if($module === '' || $module === 'WireMail') return '';
            if(strpos($module, 'WireMail') !== 0) return '';
            return $this->wire('modules')->isInstalled($module) ? $module : '';
        } catch(\Exception $e) {
            return '';
        }
    }

    protected function normalizeMailTo($value) {
        $value = (string) $value;
        if($value === '') return '';
        $san = $this->wire('sanitizer');
        $parts = preg_split('/[;,\r\n]+/', $value);
        $emails = [];
        foreach($parts as $part) {
            $email = $san->email(trim($part));
            if($email !== '') $emails[] = $email;
        }
        return implode(',', array_unique($emails));
    }

    protected function normalizeMailSubject($value) {
        $value = preg_replace('/[\r\n]+/', ' ', (string) $value);
        return trim($this->wire('sanitizer')->text($value));
    }

    protected function safeEndpointBase($value) {
        $base = trim((string) $value, "/ \t\n\r\0\x0B");
        $base = preg_replace('~[^A-Za-z0-9/_-]+~', '', $base);
        $base = preg_replace('~/+~', '/', $base);
        $base = trim($base, '/');
        return $base !== '' ? $base : 'pulse';
    }

    /**
     * Score and record a personality quiz. Sums outcome points across selected
     * options; winner by 'highest' (max points) or 'range' (total within min/max).
     *
     * @return array
     */
    protected function recordPersonality(PulseItem $item, array $answers, array $context) {
        $lead = $this->validateLead($item, $context);
        if($lead === false) return ['ok' => false, 'code' => 'invalid'];
        $resultMode = isset($item->settings['result_mode']) ? $item->settings['result_mode'] : 'highest';

        $tally = []; // outcomeId => points
        $total = 0;
        $answerRows = [];

        $questions = $item->getQuestions();
        $questionIds = [];
        foreach($questions as $q) $questionIds[(int) $q->id] = true;
        foreach($answers as $qid => $unused) {
            if(!isset($questionIds[(int) $qid])) return ['ok' => false, 'code' => 'invalid'];
        }

        foreach($questions as $q) {
            $qid = (int) $q->id;
            $raw = isset($answers[$qid]) ? $answers[$qid] : null;
            if(!$this->validAnswerInput($q, $raw)) return ['ok' => false, 'code' => 'invalid'];
            if($q->required && !$this->answerProvided($q, $raw)) return ['ok' => false, 'code' => 'invalid'];
            if($q->type === 'text') continue; // text carries no outcome points

            $options = [];
            foreach($q->getOptions() as $o) {
                if($o->archived) continue;
                $options[(int) $o->id] = $o;
            }

            $ids = is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]);
            foreach($ids as $oid) {
                $oid = (int) $oid;
                if(!isset($options[$oid])) continue;
                $o = $options[$oid];
                $answerRows[] = [$qid, $oid, null, null];
                foreach($o->outcome_points as $outId => $pts) {
                    $outId = (int) $outId; $pts = (int) $pts;
                    if(!isset($tally[$outId])) $tally[$outId] = 0;
                    $tally[$outId] += $pts;
                    $total += $pts;
                }
            }
        }

        $outcomes = [];
        foreach($item->getOutcomes() as $o) $outcomes[(int) $o->id] = $o;

        $winner = null;
        if($resultMode === 'range') {
            foreach($item->getOutcomes() as $o) {
                $min = $o->min_score; $max = $o->max_score;
                if(($min === null || $total >= $min) && ($max === null || $total <= $max)) { $winner = $o; break; }
            }
        }
        if(!$winner && !empty($tally)) {
            arsort($tally);
            $topId = (int) array_key_first($tally);
            if(isset($outcomes[$topId])) $winner = $outcomes[$topId];
        }
        if(!$winner) $winner = $item->getOutcomes()->first();

        $database = $this->wire('database');
        $ts = Pulses::TABLE_SUBMISSIONS;
        $ta = Pulses::TABLE_ANSWERS;
        try {
            $database->beginTransaction();
            $q = $database->prepare("INSERT INTO `$ts`
                (`pulse_id`,`voter_hash`,`ip_hash`,`user_id`,`lead_name`,`lead_email`,`outcome_id`,`complete`,`created`)
                VALUES (:pid,:vh,:ih,:uid,:ln,:le,:oid,1,:created)");
            $q->execute([
                ':pid' => (int) $item->id,
                ':vh' => $context['voter_hash'] ?? null,
                ':ih' => $context['ip_hash'] ?? null,
                ':uid' => $context['user_id'] ?? null,
                ':ln' => $lead['name'],
                ':le' => $lead['email'],
                ':oid' => $winner ? (int) $winner->id : null,
                ':created' => time(),
            ]);
            $subId = (int) $database->lastInsertId();
            $ins = $database->prepare("INSERT INTO `$ta` (`submission_id`,`question_id`,`option_id`) VALUES (:sid,:qid,:oid)");
            foreach($answerRows as $row) $ins->execute([':sid' => $subId, ':qid' => $row[0], ':oid' => $row[1]]);
            $database->commit();
        } catch(\Exception $e) {
            if($database->inTransaction()) $database->rollBack();
            $this->wire('log')->save('pulse-errors', 'recordPersonality: ' . $e->getMessage());
            return ['ok' => false, 'code' => 'error'];
        }

        $result = [
            'ok' => true,
            'view' => 'quiz_result',
            'mode' => 'personality',
            'outcome' => $winner ? [
                'label' => $winner->label,
                'description' => $winner->description,
                'image' => $winner->image ? $this->wire('config')->urls->assets . 'Pulse/' . rawurlencode($winner->image) : null,
            ] : null,
        ];
        $this->notify($item, $result, $lead);
        return $result;
    }

    /**
     * Was an answer provided for this question?
     */
    protected function answerProvided(PulseQuestion $q, $raw) {
        if($q->type === 'text') return is_string($raw) && trim($raw) !== '';
        if(is_array($raw)) return count(array_filter($raw, function($v){ return $v !== '' && $v !== null; })) > 0;
        return $raw !== null && $raw !== '';
    }

    /**
     * Ensure submitted values belong to this active question before scoring.
     */
    protected function validAnswerInput(PulseQuestion $q, $raw) {
        if($raw === null || $raw === '') return true;
        if($q->type === 'text') return is_string($raw);

        $values = is_array($raw) ? $raw : [$raw];
        if($q->type !== 'checkbox' && count($values) > 1) return false;
        $active = [];
        foreach($q->getOptions() as $o) {
            if(!$o->archived) $active[(int) $o->id] = true;
        }
        foreach($values as $oid) {
            if(!is_scalar($oid) || !isset($active[(int) $oid])) return false;
        }
        return true;
    }

    /**
     * Grade a single question. Returns:
     *   correct (bool), your (array of submitted labels/text),
     *   answer (array of correct labels/match values), rows (answer rows to insert)
     */
    protected function gradeQuestion(PulseQuestion $q, $raw) {
        $type = $q->type;
        $options = [];
        foreach($q->getOptions() as $o) {
            if($o->archived) continue;
            $options[(int) $o->id] = $o;
        }

        $your = []; $answer = []; $rows = []; $correct = false;

        if($type === 'text') {
            $text = is_string($raw) ? $this->boundedText($raw, 512) : '';
            $your[] = $text;
            $norm = $this->normalizeText($text);
            foreach($q->getOptions() as $o) {
                if($o->archived) continue;
                if($o->match_value === null || $o->match_value === '') continue;
                $answer[] = $o->match_value;
                if($norm !== '' && $this->normalizeText($o->match_value) === $norm) $correct = true;
            }
            $rows[] = [(int) $q->id, null, $text !== '' ? $text : null, $correct ? 1 : 0];
            return ['correct' => $correct, 'your' => $your, 'answer' => $answer, 'rows' => $rows];
        }

        // choice types
        foreach($q->getOptions() as $o) {
            if($o->archived) continue;
            if($o->is_correct) $answer[] = $o->label;
        }

        $selectedIds = [];
        if($type === 'checkbox') {
            $ids = is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]);
            foreach($ids as $oid) { $oid = (int) $oid; if(isset($options[$oid])) $selectedIds[] = $oid; }
            $selectedIds = array_values(array_unique($selectedIds));

            $correctIds = [];
            foreach($options as $oid => $o) if($o->is_correct) $correctIds[] = $oid;
            sort($selectedIds); sort($correctIds);
            $correct = !empty($correctIds) && $selectedIds === $correctIds;
        } else {
            // radio / boolean — single
            $oid = is_array($raw) ? (int) reset($raw) : (int) $raw;
            if(isset($options[$oid])) {
                $selectedIds[] = $oid;
                $correct = (bool) $options[$oid]->is_correct;
            }
        }

        foreach($selectedIds as $oid) {
            $o = $options[$oid];
            $your[] = $o->label;
            $rows[] = [(int) $q->id, $oid, null, $o->is_correct ? 1 : 0];
        }
        if(!$selectedIds) $rows[] = [(int) $q->id, null, null, 0]; // record non-answer

        return ['correct' => $correct, 'your' => $your, 'answer' => $answer, 'rows' => $rows];
    }

    protected function normalizeText($s) {
        $s = trim((string) $s);
        $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s;
    }

    /**
     * Pick the result_messages entry whose [min,max] covers the percent.
     *
     * @return array|null { title, text }
     */
    protected function pickMessage(PulseItem $item, $percent) {
        $messages = isset($item->settings['result_messages']) ? $item->settings['result_messages'] : null;
        if(!is_array($messages)) return null;
        foreach($messages as $m) {
            $min = isset($m['min']) ? max(0, min(100, (int) $m['min'])) : 0;
            $max = isset($m['max']) ? max(0, min(100, (int) $m['max'])) : 100;
            if($percent >= $min && $percent <= $max) {
                return ['title' => isset($m['title']) ? $m['title'] : '', 'text' => isset($m['text']) ? $m['text'] : ''];
            }
        }
        return null;
    }

    /* ---------------------------------------------------------------------
     * Aggregate results
     * ------------------------------------------------------------------ */

    /**
     * @param PulseItem $item
     * @return array { total, options: { id => { label, image, count, percent } } }
     */
    public function getPollResults(PulseItem $item) {
        $database = $this->wire('database');
        $to = Pulses::TABLE_OPTIONS;
        $tq = Pulses::TABLE_QUESTIONS;
        $ta = Pulses::TABLE_ANSWERS;
        $ts = Pulses::TABLE_SUBMISSIONS;
        $pid = (int) $item->id;

        $out = ['total' => 0, 'options' => []];
        if(!$pid) return $out;

        // total = completed submissions
        $q = $database->prepare("SELECT COUNT(*) FROM `$ts` WHERE `pulse_id` = :pid AND `complete` = 1");
        $q->execute([':pid' => $pid]);
        $total = (int) $q->fetchColumn();
        $out['total'] = $total;

        $sql = "SELECT o.`id`, o.`label`, o.`image`, o.`archived`, COUNT(s.`id`) AS cnt
                FROM `$to` o
                JOIN `$tq` q ON q.`id` = o.`question_id`
                LEFT JOIN `$ta` a ON a.`option_id` = o.`id`
                LEFT JOIN `$ts` s ON s.`id` = a.`submission_id`
                    AND s.`pulse_id` = :pid
                    AND s.`complete` = 1
                WHERE q.`pulse_id` = :pid
                GROUP BY o.`id`
                ORDER BY q.`sort`, o.`sort`, o.`id`";
        $q = $database->prepare($sql);
        $q->execute([':pid' => $pid]);
        while($row = $q->fetch(\PDO::FETCH_ASSOC)) {
            $cnt = (int) $row['cnt'];
            if($row['archived'] && $cnt === 0) continue; // hide empty archived options
            $out['options'][(int) $row['id']] = [
                'label' => $row['label'],
                'image' => $row['image'],
                'count' => $cnt,
                'percent' => $total > 0 ? (int) round($cnt / $total * 100) : 0,
            ];
        }

        if(!empty($item->settings['allow_other'])) {
            $q = $database->prepare("SELECT COUNT(a.`id`)
                FROM `$ta` a
                JOIN `$tq` q ON q.`id` = a.`question_id`
                JOIN `$ts` s ON s.`id` = a.`submission_id`
                WHERE q.`pulse_id` = :pid
                    AND s.`pulse_id` = :pid
                    AND s.`complete` = 1
                    AND a.`option_id` IS NULL
                    AND a.`text_answer` IS NOT NULL
                    AND a.`text_answer` <> ''");
            $q->execute([':pid' => $pid]);
            $cnt = (int) $q->fetchColumn();
            if($cnt > 0) {
                $out['options']['other'] = [
                    'label' => $this->_('Other'),
                    'image' => null,
                    'count' => $cnt,
                    'percent' => $total > 0 ? (int) round($cnt / $total * 100) : 0,
                ];
            }
        }

        return $out;
    }

    /**
     * Whether results may be shown to the current visitor.
     *
     * @param PulseItem $item
     * @param bool $submitted has this visitor voted
     * @return bool
     */
    public function resultsVisible(PulseItem $item, $submitted) {
        $vis = isset($item->settings['result_visibility']) ? $item->settings['result_visibility'] : 'after_vote';
        if($vis === 'admin_only') return $this->wire('user')->hasPermission('pulse');
        if($vis === 'after_close') return !$item->isOpen();
        return $submitted; // after_vote
    }

    /* ---------------------------------------------------------------------
     * Analytics
     * ------------------------------------------------------------------ */

    /**
     * Poll summary for the admin card.
     *
     * @return array { total, options }
     */
    public function getPollStats(PulseItem $item) {
        return $this->getPollResults($item);
    }

    /**
     * Quiz analytics: counts, averages, per-question correctness, drop-off,
     * outcome distribution (personality), average time (exam).
     *
     * @return array
     */
    public function getQuizStats(PulseItem $item) {
        $database = $this->wire('database');
        $ts = Pulses::TABLE_SUBMISSIONS;
        $ta = Pulses::TABLE_ANSWERS;
        $pid = (int) $item->id;
        $mode = $item->getMode();

        $out = [
            'mode' => $mode,
            'completed' => 0,
            'started' => 0,
            'drop_off' => 0.0,
            'avg_percent' => 0,
            'pass_rate' => 0,
            'avg_time' => null,
            'questions' => [],
            'outcomes' => [],
        ];
        if(!$pid) return $out;

        $row = $database->prepare("SELECT
                COUNT(*) AS started,
                SUM(complete=1) AS completed,
                AVG(CASE WHEN complete=1 AND max_score>0 THEN score/max_score*100 END) AS avg_percent,
                AVG(CASE WHEN complete=1 THEN passed END) AS pass_rate,
                AVG(CASE WHEN complete=1 THEN time_spent END) AS avg_time
            FROM `$ts` WHERE pulse_id = :pid");
        $row->execute([':pid' => $pid]);
        $r = $row->fetch(\PDO::FETCH_ASSOC);
        $started = (int) $r['started'];
        $completed = (int) $r['completed'];
        $out['started'] = $started;
        $out['completed'] = $completed;
        $out['drop_off'] = $started > 0 ? round(($started - $completed) / $started * 100, 1) : 0.0;
        $out['avg_percent'] = $r['avg_percent'] !== null ? (int) round($r['avg_percent']) : 0;
        $out['pass_rate'] = $r['pass_rate'] !== null ? (int) round($r['pass_rate'] * 100) : 0;
        if($mode === 'exam') $out['avg_time'] = $r['avg_time'] !== null ? (int) round($r['avg_time']) : null;

        if($mode === 'personality') {
            $tout = Pulses::TABLE_OUTCOMES;
            $q = $database->prepare("SELECT o.id, o.label, COUNT(s.id) AS cnt
                FROM `$tout` o
                LEFT JOIN `$ts` s ON s.outcome_id = o.id AND s.complete = 1
                WHERE o.pulse_id = :pid GROUP BY o.id ORDER BY o.sort, o.id");
            $q->execute([':pid' => $pid]);
            while($o = $q->fetch(\PDO::FETCH_ASSOC)) {
                $out['outcomes'][] = ['label' => $o['label'], 'count' => (int) $o['cnt']];
            }
            return $out;
        }

        // % correct per question — single JOIN query (replaces N+1 per-question loop)
        $tq = Pulses::TABLE_QUESTIONS;
        $q = $database->prepare("SELECT
                q.`id` AS question_id,
                q.`text`,
                COUNT(DISTINCT CASE WHEN s.`complete` = 1 THEN a.`submission_id` END) AS answered,
                COUNT(DISTINCT CASE WHEN s.`complete` = 1 AND a.`is_correct` = 1 THEN a.`submission_id` END) AS correct
            FROM `$tq` q
            LEFT JOIN `$ta` a ON a.`question_id` = q.`id`
            LEFT JOIN `$ts` s ON s.`id` = a.`submission_id`
            WHERE q.`pulse_id` = :pid
            GROUP BY q.`id`, q.`text`, q.`sort`
            ORDER BY q.`sort`, q.`id`");
        $q->execute([':pid' => $pid]);
        while($qr = $q->fetch(\PDO::FETCH_ASSOC)) {
            $answered = (int) $qr['answered'];
            $correct = (int) $qr['correct'];
            $out['questions'][] = [
                'text' => $qr['text'],
                'answered' => $answered,
                'correct' => $correct,
                'percent_correct' => $answered > 0 ? (int) round($correct / $answered * 100) : 0,
            ];
        }

        return $out;
    }

    /* ---------------------------------------------------------------------
     * CSV export
     * ------------------------------------------------------------------ */

    /**
     * Export submissions to CSV (one row per submission).
     *
     * @return string CSV text
     */
    public function exportCsv(PulseItem $item) {
        $database = $this->wire('database');
        $ts = Pulses::TABLE_SUBMISSIONS;
        $pid = (int) $item->id;

        $cols = ['id', 'created', 'lead_name', 'lead_email', 'score', 'max_score', 'passed', 'outcome_id', 'attempt_no', 'time_spent', 'complete'];
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $cols, ',', '"', '\\');
        if($pid) {
            $q = $database->prepare("SELECT * FROM `$ts` WHERE pulse_id = :pid ORDER BY id");
            $q->execute([':pid' => $pid]);
            while($r = $q->fetch(\PDO::FETCH_ASSOC)) {
                $rowOut = [];
                foreach($cols as $c) {
                    $value = $c === 'created' && $r[$c] ? date('Y-m-d H:i:s', (int) $r[$c]) : $r[$c];
                    if(($c === 'lead_name' || $c === 'lead_email') && is_string($value)
                        && preg_match('/^[\x00-\x20]*[=+\-@]/', $value)) {
                        $value = "'" . $value;
                    }
                    $rowOut[] = $value;
                }
                fputcsv($fh, $rowOut, ',', '"', '\\');
            }
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    /* ---------------------------------------------------------------------
     * GDPR / retention
     * ------------------------------------------------------------------ */

    /**
     * Purge or anonymize submissions older than a cutoff.
     *
     * @param PulseItem $item
     * @param int $olderThan unix timestamp; submissions created before this are affected
     * @param string $mode 'delete' | 'anonymize'
     * @return int affected rows
     */
    public function purgeOld(PulseItem $item, $olderThan, $mode = 'delete') {
        $database = $this->wire('database');
        $ts = Pulses::TABLE_SUBMISSIONS;
        $pid = (int) $item->id;
        $olderThan = (int) $olderThan;
        if(!$pid || !$olderThan) return 0;

        if($mode === 'anonymize') {
            $q = $database->prepare("UPDATE `$ts`
                SET voter_hash = NULL, ip_hash = NULL, lead_name = NULL, lead_email = NULL
                WHERE pulse_id = :pid AND created < :t");
        } else {
            $q = $database->prepare("DELETE FROM `$ts` WHERE pulse_id = :pid AND created < :t");
        }
        $q->execute([':pid' => $pid, ':t' => $olderThan]);
        return $q->rowCount();
    }
}
