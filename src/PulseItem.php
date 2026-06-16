<?php namespace ProcessWire;

require_once(__DIR__ . '/PulseQuestion.php');
require_once(__DIR__ . '/PulseOutcome.php');

/**
 * PulseItem
 *
 * A single poll or quiz item.
 *
 * @property int $id
 * @property string $name      // shortcode slug, ^[a-z][a-z0-9_-]*$
 * @property string $kind      // poll | quiz
 * @property string $title
 * @property string $intro
 * @property int $status       // 0 draft, 1 published
 * @property array $settings   // decoded JSON settings
 * @property int|null $open_at
 * @property int|null $close_at
 * @property int $created
 * @property int $modified
 */
class PulseItem extends WireData {

    protected $defaults = [
        'id' => 0,
        'name' => '',
        'kind' => 'poll',
        'title' => '',
        'intro' => '',
        'status' => 0,
        'settings' => [],
        'open_at' => null,
        'close_at' => null,
        'created' => 0,
        'modified' => 0,
    ];

    /** @var WireArray|null */
    protected $questions = null;

    /** @var WireArray|null */
    protected $outcomes = null;

    public function __construct() {
        $this->setArray($this->defaults);
        parent::__construct();
    }

    public function set($key, $value) {
        if($key === 'id') {
            $currentId = (int) parent::get('id');
            $newId = (int) $value;
            if($currentId > 0 && $newId === 0) return $this;
            return parent::set('id', $newId);
        }

        if($key === 'settings') {
            if(is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [];
            } elseif(!is_array($value)) {
                $value = [];
            }
            return parent::set('settings', $value);
        }

        if($key === 'open_at' || $key === 'close_at') {
            if($value === null || $value === '' || $value === 0 || $value === '0') {
                return parent::set($key, null);
            }
            return parent::set($key, (int) $value);
        }

        if($key === 'questions') return $this->setQuestions($value);
        if($key === 'outcomes') return $this->setOutcomes($value);

        if(isset($this->defaults[$key]) && is_int($this->defaults[$key])) {
            $value = (int) $value;
        }

        return parent::set($key, $value);
    }

    public function get($key) {
        if($key === 'questions') return $this->getQuestions();
        if($key === 'outcomes') return $this->getOutcomes();
        return parent::get($key);
    }

    /**
     * Questions (each with its options) as a WireArray of PulseQuestion.
     *
     * @param bool $refresh
     * @return WireArray
     */
    public function getQuestions($refresh = false) {
        if($this->questions !== null && !$refresh) return $this->questions;

        if(!$this->id) {
            $this->questions = $this->wire(new WireArray());
            return $this->questions;
        }

        if(!class_exists('ProcessWire\\Pulses')) require_once(__DIR__ . '/Pulses.php');
        $repo = $this->wire(new Pulses());
        $this->questions = $repo->loadQuestions($this->id);
        return $this->questions;
    }

    /**
     * @param iterable $questions array rows or PulseQuestion objects
     * @return self
     */
    public function setQuestions($questions) {
        $arr = $this->wire(new WireArray());
        if(is_iterable($questions)) {
            foreach($questions as $q) {
                if(!$q instanceof PulseQuestion) {
                    $row = $q;
                    $q = $this->wire(new PulseQuestion());
                    if(is_array($row)) $q->setArray($row);
                }
                $arr->add($q);
            }
        }
        $this->questions = $arr;
        return $this;
    }

    /**
     * Outcomes as a WireArray of PulseOutcome (personality quizzes).
     *
     * @param bool $refresh
     * @return WireArray
     */
    public function getOutcomes($refresh = false) {
        if($this->outcomes !== null && !$refresh) return $this->outcomes;

        if(!$this->id) {
            $this->outcomes = $this->wire(new WireArray());
            return $this->outcomes;
        }

        if(!class_exists('ProcessWire\\Pulses')) require_once(__DIR__ . '/Pulses.php');
        $repo = $this->wire(new Pulses());
        $this->outcomes = $repo->loadOutcomes($this->id);
        return $this->outcomes;
    }

    /**
     * @param iterable $outcomes array rows or PulseOutcome objects
     * @return self
     */
    public function setOutcomes($outcomes) {
        $arr = $this->wire(new WireArray());
        if(is_iterable($outcomes)) {
            foreach($outcomes as $o) {
                if(!$o instanceof PulseOutcome) {
                    $row = $o;
                    $o = $this->wire(new PulseOutcome());
                    if(is_array($row)) $o->setArray($row);
                }
                $arr->add($o);
            }
        }
        $this->outcomes = $arr;
        return $this;
    }

    public function isPoll() {
        return $this->kind === 'poll';
    }

    public function isQuiz() {
        return $this->kind === 'quiz';
    }

    /**
     * Is the item currently accepting answers (status + activity window)?
     *
     * @return bool
     */
    public function isOpen() {
        if(!$this->status) return false;
        $now = time();
        if($this->open_at && $now < $this->open_at) return false;
        if($this->close_at && $now > $this->close_at) return false;
        return true;
    }

    /**
     * The quiz mode (graded|personality|exam); empty string for polls.
     *
     * @return string
     */
    public function getMode() {
        if(!$this->isQuiz()) return '';
        $mode = isset($this->settings['mode']) ? (string) $this->settings['mode'] : 'graded';
        return in_array($mode, ['graded', 'personality', 'exam'], true) ? $mode : 'graded';
    }

    /**
     * Validate the definition before saving.
     *
     * @return bool|string true if valid, otherwise an error message
     */
    public function validate() {
        if($this->name === '') return $this->_('Name is required');
        if(!preg_match('/^[a-z][a-z0-9_-]*$/', $this->name)) {
            return $this->_('Name must start with a lowercase letter and contain only lowercase letters, numbers, hyphens and underscores');
        }
        if($this->title === '') return $this->_('Title is required');
        if(!in_array($this->kind, ['poll', 'quiz'], true)) return $this->_('Invalid kind');

        if($this->isPoll()) {
            if(!$this->getQuestions()->count()) return $this->_('Poll must have a question');
            return true;
        }

        // quiz
        $questions = $this->getQuestions();
        if(!$questions->count()) return $this->_('Quiz must have at least one question');

        $mode = $this->getMode();

        if($mode === 'graded' || $mode === 'exam') {
            foreach($questions as $q) {
                if($q->type === 'text') {
                    $hasMatch = false;
                    foreach($q->getOptions() as $o) {
                        if($o->archived) continue;
                        if($o->match_value !== null && $o->match_value !== '') { $hasMatch = true; break; }
                    }
                    if(!$hasMatch) {
                        return sprintf($this->_('Text question "%s" needs at least one accepted answer'), $this->truncate($q->text));
                    }
                    continue;
                }
                $hasCorrect = false;
                foreach($q->getOptions() as $o) {
                    if($o->archived) continue;
                    if($o->is_correct) { $hasCorrect = true; break; }
                }
                if(!$hasCorrect) {
                    return sprintf($this->_('Question "%s" needs at least one correct option'), $this->truncate($q->text));
                }
            }
        } elseif($mode === 'personality') {
            $outcomes = $this->getOutcomes();
            if(!$outcomes->count()) return $this->_('Personality quiz must have at least one outcome');

            $hasPoints = false;
            foreach($questions as $q) {
                foreach($q->getOptions() as $o) {
                    if($o->archived) continue;
                    foreach($o->outcome_points as $points) {
                        if((int) $points !== 0) { $hasPoints = true; break 3; }
                    }
                }
            }
            if(!$hasPoints) return $this->_('Personality quiz needs outcome points on at least one option');
        }

        return true;
    }

    protected function truncate($text, $len = 40) {
        $text = (string) $text;
        return strlen($text) > $len ? substr($text, 0, $len) . '…' : $text;
    }

    /**
     * Shortcode for embedding, e.g. [[pulse:poll name="example"]].
     *
     * @return string
     */
    public function getShortcode() {
        return '[[pulse:' . $this->kind . ' name="' . $this->name . '"]]';
    }

    /**
     * Render the widget shell.
     *
     * Uses a custom PHP template at /site/templates/<componentsPath>/pulse/<name>.php
     * if present, otherwise the built-in PulseRenderer. Failures are caught and
     * logged to pulse-errors; the page never breaks.
     *
     * @param array $context
     * @return string
     */
    public function render($context = []) {
        $templatePath = $this->getCustomTemplatePath();
        if($templatePath) {
            return $this->renderCustomTemplate($templatePath, $context);
        }

        if(is_file(__DIR__ . '/PulseRenderer.php')) {
            require_once(__DIR__ . '/PulseRenderer.php');
            try {
                $renderer = $this->wire(new PulseRenderer($this));
                return $renderer->render();
            } catch(\Throwable $e) {
                $this->logError('render', $e);
                return "<!-- Pulse render error -->";
            }
        }

        return "<!-- Pulse: renderer not available ({$this->name}) -->";
    }

    /**
     * Resolve the custom template path for this item, or '' if none exists.
     *
     * @return string
     */
    protected function getCustomTemplatePath() {
        if(!$this->name) return '';
        $componentsPath = 'components/';
        $cfg = $this->moduleConfig();
        if(!empty($cfg['componentsPath'])) $componentsPath = $cfg['componentsPath'];
        $componentsPath = $this->normalizeComponentsPath($componentsPath);
        if($componentsPath === '') return '';

        $path = $this->wire('config')->paths->templates
            . $componentsPath . '/pulse/' . $this->name . '.php';

        return is_file($path) ? $path : '';
    }

    protected function normalizeComponentsPath($path) {
        $path = str_replace('\\', '/', trim((string) $path));
        $path = trim($path, "/ \t\n\r\0\x0B");
        if($path === '') return '';
        if(strpos($path, '://') !== false) return '';

        $parts = [];
        foreach(explode('/', $path) as $part) {
            if($part === '' || $part === '.') continue;
            if($part === '..') return '';
            if(!preg_match('/^[A-Za-z0-9._-]+$/', $part)) return '';
            $parts[] = $part;
        }
        return $parts ? implode('/', $parts) : '';
    }

    /**
     * @param string $templatePath
     * @param array $context
     * @return string
     */
    protected function renderCustomTemplate($templatePath, array $context) {
        $item = $this;
        $questions = $this->getQuestions();
        $results = isset($context['results']) ? $context['results'] : null;
        $page = $this->wire('page');
        $config = $this->wire('config');
        $sanitizer = $this->wire('sanitizer');

        try {
            ob_start();
            try {
                include $templatePath;
            } catch(\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            return ob_get_clean();
        } catch(\Throwable $e) {
            $this->logError('template', $e);
            return "<!-- Pulse template error -->";
        }
    }

    /**
     * Read ProcessPulse config without instantiating the (permission-gated) module.
     *
     * @return array
     */
    protected function moduleConfig() {
        try {
            return (array) $this->wire('modules')->getModuleConfigData('ProcessPulse');
        } catch(\Exception $e) {
            return [];
        }
    }

    protected function logError($context, \Throwable $e) {
        $this->wire('log')->save('pulse-errors', sprintf(
            '[%s] item=%s | %s in %s:%d',
            $context,
            $this->name,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
    }

    public function __toString() {
        return $this->title ?: $this->name;
    }
}
