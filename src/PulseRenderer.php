<?php namespace ProcessWire;

require_once(__DIR__ . '/PulseItem.php');
require_once(__DIR__ . '/PulseSubmissions.php');

/**
 * PulseRenderer
 *
 * Framework-independent renderer for the static widget shell. Emits no personal
 * state and no answer keys (is_correct / match_value / outcome_points never reach
 * the client). Personal state is hydrated later by pulse.js via /pulse/state.
 */
class PulseRenderer extends Wire {

    /** @var PulseItem */
    protected $item;

    public function __construct(PulseItem $item) {
        $this->item = $item;
    }

    /**
     * Public URL for an uploaded option/outcome image.
     *
     * @param string $file
     * @return string
     */
    public function imageUrl($file) {
        $file = $this->assetFileName($file);
        if($file === '') return '';
        return $this->wire('config')->urls->assets . 'Pulse/' . rawurlencode($file);
    }

    protected function assetFileName($file) {
        $file = basename((string) $file);
        return preg_match('/^[A-Za-z0-9._-]+\\.(?:jpe?g|png|gif|webp)$/i', $file) ? $file : '';
    }

    protected function e($s) {
        return $this->wire('sanitizer')->entities((string) $s);
    }

    protected function safeEndpointBase($value) {
        $base = trim((string) $value, "/ \t\n\r\0\x0B");
        $base = preg_replace('~[^A-Za-z0-9/_-]+~', '', $base);
        $base = preg_replace('~/+~', '/', $base);
        $base = trim($base, '/');
        return $base !== '' ? $base : 'pulse';
    }

    /**
     * Render the widget shell.
     *
     * @param array $context  preview => bool forces a visible form for admin preview
     * @return string
     */
    public function render(array $context = []) {
        $item = $this->item;
        $preview = !empty($context['preview']);

        $endpointBase = 'pulse';
        try {
            $cfg = (array) $this->wire('modules')->getModuleConfigData('ProcessPulse');
            if(!empty($cfg['endpointBase'])) $endpointBase = $this->safeEndpointBase($cfg['endpointBase']);
        } catch(\Exception $e) {}
        $action = '/' . $endpointBase . '/submit';

        $state = $preview ? 'form' : 'loading';
        $kind = $this->e($item->kind);
        $name = $this->e($item->name);

        $counts = !empty($item->settings['show_counts']) ? '1' : '0';
        $attrs = "data-pulse-name=\"{$name}\" data-pulse-kind=\"{$kind}\""
            . " data-pulse-counts=\"{$counts}\" data-pulse-state=\"{$state}\"";
        if($item->isPoll()) {
            $multipleAttr = !empty($item->settings['multiple']) ? '1' : '0';
            $minSel = isset($item->settings['min_select']) ? max(0, (int) $item->settings['min_select']) : 0;
            $maxSel = isset($item->settings['max_select']) ? max(0, (int) $item->settings['max_select']) : 0;
            $attrs .= " data-pulse-multiple=\"{$multipleAttr}\" data-pulse-minselect=\"{$minSel}\" data-pulse-maxselect=\"{$maxSel}\"";
        }

        $video = ($item->isQuiz() && !empty($item->settings['video']['provider']) && !empty($item->settings['video']['src']))
            ? $item->settings['video'] : null;

        $isExam = $item->isQuiz() && $item->getMode() === 'exam';
        if($video) $attrs .= " data-pulse-video=\"1\"";
        if(!empty($item->settings['share'])) $attrs .= " data-pulse-share=\"1\"";
        if($item->isQuiz()) {
            $pagination = (isset($item->settings['pagination']) && $item->settings['pagination'] === 'one_per_page') ? 'one_per_page' : 'all';
            $progress = !empty($item->settings['progress_bar']) ? '1' : '0';
            $attrs .= " data-pulse-mode=\"" . $this->e($item->getMode()) . "\""
                . " data-pulse-pagination=\"{$pagination}\" data-pulse-progress=\"{$progress}\"";
            if($isExam) {
                $timeLimit = isset($item->settings['time_limit']) ? max(0, (int) $item->settings['time_limit']) : 0;
                $attrs .= " data-pulse-timelimit=\"{$timeLimit}\"";
            }
        }

        $out = "<div class=\"pulse pulse--{$kind}\" {$attrs}>";

        if((string) $item->intro !== '') {
            $out .= "<div class=\"pulse__intro\">" . $this->wire('sanitizer')->purify($item->intro) . "</div>";
        }

        if($video) {
            $out .= $this->renderVideo($video);
        }

        if($item->isQuiz() && !empty($item->settings['progress_bar'])) {
            $out .= "<div class=\"pulse__progress\" role=\"progressbar\" aria-valuemin=\"0\" aria-valuemax=\"100\" aria-valuenow=\"0\" hidden>"
                . "<div class=\"pulse__progress-fill\"></div></div>";
        }

        $out .= "<form class=\"pulse__form\" method=\"post\" action=\"" . $this->e($action) . "\">";

        $multiple = $item->isPoll() && !empty($item->settings['multiple']);

        // Build the question list (exam: shuffle + pick_random bank).
        $questions = array_values($item->getQuestions()->getArray());
        $shuffleOptions = $item->isQuiz() && !empty($item->settings['shuffle_options']);
        if($item->isQuiz() && !empty($item->settings['shuffle_questions'])) shuffle($questions);

        // NOTE — pick_random and full-page caching are incompatible:
        // The question bank is shuffled and sliced here at PHP render time, so every
        // cached copy of the page will contain the same fixed subset of questions.
        // If ProCache, StaticCache or any other page-level cache is active, either
        // exclude exam pages from caching or disable pick_random for those items.
        $signedIds = null;
        if($isExam) {
            $pick = isset($item->settings['pick_random']) ? max(0, (int) $item->settings['pick_random']) : 0;
            if($pick > 0 && $pick < count($questions)) {
                shuffle($questions);
                $questions = array_slice($questions, 0, $pick);
                $signedIds = array_map(function($q){ return (int) $q->id; }, $questions);
            }
        }

        $idx = 0;
        foreach($questions as $q) {
            $out .= $this->renderQuestion($q, $multiple, $idx++, $shuffleOptions);
        }

        // lead capture
        $fields = (isset($item->settings['require_fields']) && is_array($item->settings['require_fields']))
            ? $item->settings['require_fields'] : [];
        if($fields) {
            $out .= "<div class=\"pulse__lead\">";
            if(in_array('name', $fields, true)) {
                $out .= "<label class=\"pulse__lead-field\"><span>" . $this->_('Name') . "</span>"
                    . "<input type=\"text\" name=\"lead_name\" autocomplete=\"name\" required></label>";
            }
            if(in_array('email', $fields, true)) {
                $out .= "<label class=\"pulse__lead-field\"><span>" . $this->_('Email') . "</span>"
                    . "<input type=\"email\" name=\"lead_email\" autocomplete=\"email\" required></label>";
            }
            $out .= "</div>";
        }

        // honeypot (hidden from a11y)
        $out .= "<div class=\"pulse__hp\" aria-hidden=\"true\">"
            . "<input type=\"text\" name=\"website\" tabindex=\"-1\" autocomplete=\"off\"></div>";

        $out .= "<input type=\"hidden\" name=\"name\" value=\"{$name}\">";
        $out .= "<input type=\"hidden\" name=\"kind\" value=\"{$kind}\">";

        if($isExam) {
            $out .= "<input type=\"hidden\" class=\"pulse__timespent\" name=\"time_spent\" value=\"\">";
            if($signedIds !== null) {
                $subs = $this->wire(new PulseSubmissions());
                $csv = implode(',', $signedIds);
                $sig = $subs->signQuestionIds($signedIds, (int) $item->id);
                $out .= "<input type=\"hidden\" name=\"qids\" value=\"" . $this->e($csv) . "\">";
                $out .= "<input type=\"hidden\" name=\"qsig\" value=\"" . $this->e($sig) . "\">";
            }
        }

        $label = $item->isPoll() ? $this->_('Vote') : $this->_('Submit');
        $disabled = $preview ? ' disabled' : '';
        $out .= "<button class=\"pulse__submit\" type=\"submit\"{$disabled}>" . $this->e($label) . "</button>";

        $out .= "</form>";
        $out .= "<div class=\"pulse__results\" aria-live=\"polite\" hidden></div>";
        $out .= "</div>";

        return $out;
    }

    /**
     * @param PulseQuestion $q
     * @param bool $multiple poll multi-select
     * @return string
     */
    protected function renderQuestion(PulseQuestion $q, $multiple = false, $index = 0, $shuffleOptions = false) {
        $qid = (int) $q->id;
        $type = $q->type;
        $req = $q->required ? '1' : '0';

        $out = "<fieldset class=\"pulse__question\" data-pulse-qtype=\"" . $this->e($type) . "\""
            . " data-pulse-qindex=\"" . (int) $index . "\" data-pulse-required=\"{$req}\">";
        $legend = $this->e($q->text);
        if($q->required) $legend .= " <span class=\"pulse__required\" aria-hidden=\"true\">*</span>";
        $out .= "<legend class=\"pulse__qtext\">{$legend}</legend>";

        if((string) $q->hint !== '') {
            $out .= "<p class=\"pulse__hint\">" . $this->e($q->hint) . "</p>";
        }

        if((string) $q->image !== '') {
            $out .= "<img class=\"pulse__question-img\" src=\"" . $this->e($this->imageUrl($q->image))
                . "\" alt=\"\" loading=\"lazy\">";
        }

        if($type === 'text') {
            $out .= "<input type=\"text\" class=\"pulse__text-input\" name=\"answers[{$qid}]\" autocomplete=\"off\">";
        } else {
            // radio / boolean -> single; checkbox or poll-multiple -> multi
            $isMulti = ($type === 'checkbox') || ($multiple && $type !== 'boolean');
            $inputType = $isMulti ? 'checkbox' : 'radio';
            $fieldName = $isMulti ? "answers[{$qid}][]" : "answers[{$qid}]";

            $options = array_values($q->getOptions()->getArray());
            if($shuffleOptions) shuffle($options);
            foreach($options as $o) {
                if($o->archived) continue;
                $out .= $this->renderOption($o, $inputType, $fieldName);
            }
            if($this->item->isPoll() && !empty($this->item->settings['allow_other']) && $type !== 'boolean') {
                $out .= $this->renderOtherOption($inputType, $fieldName, $qid);
            }
        }

        $out .= "</fieldset>";
        return $out;
    }

    /**
     * Render poll results (server-side, used for the no-JS fallback).
     *
     * @param array $results { total, options:{ id => {label,image,count,percent} } }
     * @return string
     */
    public function renderResults(array $results) {
        $total = isset($results['total']) ? (int) $results['total'] : 0;
        $options = isset($results['options']) && is_array($results['options']) ? $results['options'] : [];
        $showCounts = !empty($this->item->settings['show_counts']);

        $out = "<div class=\"pulse__results-inner\">";
        foreach($options as $o) {
            $pct = isset($o['percent']) ? (int) $o['percent'] : 0;
            $cnt = isset($o['count']) ? (int) $o['count'] : 0;
            $meta = $showCounts ? ($pct . '% · ' . $cnt) : ($pct . '%');
            $out .= "<div class=\"pulse__result-row\">"
                . "<div class=\"pulse__result-head\"><span>" . $this->e($o['label']) . "</span>"
                . "<span class=\"pulse__result-meta\">" . $this->e($meta) . "</span></div>"
                . "<div class=\"pulse__bar\"><div class=\"pulse__bar-fill\" style=\"width:{$pct}%\"></div></div>"
                . "</div>";
        }
        $out .= "<div class=\"pulse__total\">" . sprintf($this->_n('%d vote', '%d votes', $total), $total) . "</div>";
        $out .= "</div>";
        return $out;
    }

    /**
     * Render the video gate block (player + gating data attributes).
     *
     * @param array $video { provider, src, gate, percent }
     * @return string
     */
    public function renderVideo(array $video) {
        $provider = isset($video['provider']) ? $video['provider'] : '';
        $src = isset($video['src']) ? (string) $video['src'] : '';
        $gate = isset($video['gate']) ? $video['gate'] : 'ended';
        $percent = isset($video['percent']) ? max(0, min(100, (int) $video['percent'])) : 90;
        if($src === '' || !in_array($provider, ['youtube', 'vimeo', 'mp4'], true)) return '';

        $player = '';
        if($provider === 'youtube') {
            $id = $this->youtubeId($src);
            if(!$id) return '';
            $u = 'https://www.youtube.com/embed/' . rawurlencode($id) . '?enablejsapi=1&rel=0';
            $player = "<iframe class=\"pulse__video-frame\" src=\"" . $this->e($u) . "\""
                . " title=\"" . $this->_('Video') . "\""
                . " frameborder=\"0\" allow=\"autoplay; encrypted-media\" allowfullscreen></iframe>";
        } elseif($provider === 'vimeo') {
            $id = $this->vimeoId($src);
            if(!$id) return '';
            $u = 'https://player.vimeo.com/video/' . rawurlencode($id);
            $player = "<iframe class=\"pulse__video-frame\" src=\"" . $this->e($u) . "\""
                . " title=\"" . $this->_('Video') . "\""
                . " frameborder=\"0\" allow=\"autoplay; fullscreen\" allowfullscreen></iframe>";
        } else { // mp4
            $url = $this->mp4Url($src);
            if($url === '') return '';
            $player = "<video class=\"pulse__video-el\" src=\"" . $this->e($url) . "\" controls playsinline preload=\"metadata\"></video>";
        }

        $unlock = $gate === 'button'
            ? "<button type=\"button\" class=\"pulse__video-unlock\">" . $this->_('I have watched the video') . "</button>"
            : '';

        return "<div class=\"pulse__video\" data-pulse-provider=\"" . $this->e($provider) . "\""
            . " data-pulse-gate=\"" . $this->e($gate) . "\" data-pulse-percent=\"" . $percent . "\">"
            . "<div class=\"pulse__video-wrap\">" . $player . "</div>" . $unlock . "</div>";
    }

    protected function youtubeId($src) {
        $src = trim($src);
        if(preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|embed/|v/|shorts/))([A-Za-z0-9_-]{6,})~', $src, $m)) return $m[1];
        if(preg_match('~^[A-Za-z0-9_-]{6,}$~', $src)) return $src; // bare id
        return '';
    }

    protected function vimeoId($src) {
        $src = trim($src);
        if(preg_match('~vimeo\.com/(?:video/)?(\d+)~', $src, $m)) return $m[1];
        if(preg_match('~^\d+$~', $src)) return $src;
        return '';
    }

    protected function mp4Url($src) {
        $src = trim((string) $src);
        if(preg_match('~^https://[^\\s?#]+\\.mp4(?:[?#][^\\s]*)?$~i', $src)) return $src;
        $file = basename($src);
        if(preg_match('/^[A-Za-z0-9._-]+\\.mp4$/i', $file)) {
            return $this->wire('config')->urls->assets . 'Pulse/' . rawurlencode($file);
        }
        return '';
    }

    /**
     * Render a personality outcome (server-side, no-JS fallback).
     *
     * @param array $result  expects result['outcome'] = { label, description, image(URL) }
     * @return string
     */
    public function renderOutcome(array $result) {
        $o = isset($result['outcome']) && is_array($result['outcome']) ? $result['outcome'] : null;
        if(!$o) return "<div class=\"pulse__quiz-result\"><p>" . $this->_('No result.') . "</p></div>";

        $out = "<div class=\"pulse__quiz-result pulse__outcome\">";
        if(!empty($o['image'])) {
            $out .= "<img class=\"pulse__outcome-img\" src=\"" . $this->e($o['image']) . "\" alt=\"" . $this->e($o['label']) . "\">";
        }
        $out .= "<h3 class=\"pulse__outcome-label\">" . $this->e($o['label']) . "</h3>";
        if(!empty($o['description'])) {
            $out .= "<div class=\"pulse__outcome-desc\">" . $this->e($o['description']) . "</div>";
        }
        $out .= "</div>";
        return $out;
    }

    /**
     * Render a graded/exam quiz result + review (server-side, no-JS fallback).
     *
     * @param array $result
     * @return string
     */
    public function renderQuizResult(array $result) {
        $score = isset($result['score']) ? (int) $result['score'] : 0;
        $max = isset($result['max_score']) ? (int) $result['max_score'] : 0;
        $percent = isset($result['percent']) ? (int) $result['percent'] : 0;
        $passed = !empty($result['passed']);

        $out = "<div class=\"pulse__quiz-result\">";
        $out .= "<div class=\"pulse__score " . ($passed ? 'is-pass' : 'is-fail') . "\">"
            . sprintf($this->_('%1$d / %2$d (%3$d%%)'), $score, $max, $percent) . "</div>";

        if(!empty($result['messages'])) {
            $m = $result['messages'];
            if(!empty($m['title'])) $out .= "<h3 class=\"pulse__result-title\">" . $this->e($m['title']) . "</h3>";
            if(!empty($m['text'])) $out .= "<p class=\"pulse__result-text\">" . $this->e($m['text']) . "</p>";
        }

        if(!empty($result['review']) && is_array($result['review'])) {
            $out .= "<ol class=\"pulse__review\">";
            foreach($result['review'] as $r) {
                $cls = !empty($r['is_correct']) ? 'is-correct' : 'is-incorrect';
                $out .= "<li class=\"pulse__review-item {$cls}\">";
                $out .= "<div class=\"pulse__review-q\">" . $this->e($r['question']) . "</div>";
                $your = isset($r['your']) ? array_filter((array) $r['your'], 'strlen') : [];
                $out .= "<div class=\"pulse__review-your\">" . $this->_('Your answer:') . ' '
                    . $this->e($your ? implode(', ', $your) : $this->_('(none)')) . "</div>";
                if(isset($r['correct'])) {
                    $out .= "<div class=\"pulse__review-correct\">" . $this->_('Correct:') . ' '
                        . $this->e(implode(', ', (array) $r['correct'])) . "</div>";
                }
                if(!empty($r['explanation'])) {
                    $out .= "<div class=\"pulse__review-exp\">" . $this->e($r['explanation']) . "</div>";
                }
                $out .= "</li>";
            }
            $out .= "</ol>";
        }

        $out .= "</div>";
        return $out;
    }

    /**
     * @param PulseOption $o
     * @param string $inputType radio|checkbox
     * @param string $fieldName
     * @return string
     */
    protected function renderOption(PulseOption $o, $inputType, $fieldName) {
        $val = (int) $o->id;
        $hasImage = $o->image !== null && $o->image !== '';
        $cls = $hasImage ? 'pulse__option pulse__option--image' : 'pulse__option';

        $out = "<label class=\"{$cls}\">";
        $out .= "<input type=\"{$inputType}\" name=\"" . $this->e($fieldName) . "\" value=\"{$val}\">";
        if($hasImage) {
            $out .= "<img class=\"pulse__option-img\" src=\"" . $this->e($this->imageUrl($o->image))
                . "\" alt=\"" . $this->e($o->label) . "\">";
        }
        $out .= "<span class=\"pulse__label\">" . $this->e($o->label) . "</span>";
        $out .= "</label>";
        return $out;
    }

    protected function renderOtherOption($inputType, $fieldName, $qid) {
        $out = "<label class=\"pulse__option pulse__option--other\">";
        $out .= "<input type=\"{$inputType}\" name=\"" . $this->e($fieldName) . "\" value=\"__other\">";
        $out .= "<span class=\"pulse__label\">" . $this->_('Other') . "</span>";
        $out .= "<input type=\"text\" class=\"pulse__other\" name=\"other[" . (int) $qid . "]\" autocomplete=\"off\">";
        $out .= "</label>";
        return $out;
    }
}
