<?php namespace ProcessWire;

require_once(__DIR__ . '/src/Pulses.php');

/**
 * Pulse Text Formatter
 *
 * Parses [[pulse:poll name="name"]] / [[pulse:quiz name="name"]] tokens and
 * replaces them with the rendered widget. Unknown or unpublished items become
 * a safe HTML comment.
 */
class TextformatterPulse extends Textformatter implements ConfigurableModule {

    public static function getModuleInfo() {
        return [
            'title' => 'Pulse Text Formatter',
            'version' => 104,
            'summary' => 'Parses [[pulse:poll name="name"]] / [[pulse:quiz name="name"]] tokens into rendered widgets.',
            'author' => 'Maxim Semenov',
            'href'     => 'https://smnv.org',
            'icon' => 'bar-chart',
            'requires' => ['Pulse'],
        ];
    }

    /** @var Pulses|null */
    protected $pulses = null;

    /** @var bool assets injected once per request */
    protected static $assetsInjected = false;

    protected function pulses() {
        if($this->pulses === null) $this->pulses = $this->wire(new Pulses());
        return $this->pulses;
    }

    /**
     * @param Page $page
     * @param Field $field
     * @param string $value
     */
    public function formatValue(Page $page, Field $field, &$value) {
        $value = $this->render($value, $page, $field);
    }

    /**
     * Replace all Pulse shortcodes in a string.
     *
     * @param string $value
     * @param Page|null $page
     * @param Field|null $field
     * @return string
     */
    public function render($value, $page = null, $field = null) {
        // Early exit when there is nothing to do.
        if(strpos($value, '[[pulse:') === false && strpos($value, '((') === false) return $value;

        // Vox-style tokens: [[pulse:poll name="customer-feedback"]].
        $pattern = '/(?:<p>\s*)?\[\[pulse\s*:\s*(poll|quiz)([^\]]*)\]\](?:\s*<\/p>)?/i';
        $value = preg_replace_callback($pattern, function($m) {
            $kind = strtolower($m[1]);
            $attrs = $this->parseAttributes($m[2] ?? '');
            $name = isset($attrs['name']) ? strtolower($attrs['name']) : '';
            if($name === '' && isset($attrs[0])) $name = strtolower($attrs[0]);

            return $this->renderItem($kind, $name);
        }, $value);

        // Backward compatibility for older content: ((poll:name)).
        $legacy = '/(?:<p>\s*)?\(\(\s*(poll|quiz)\s*:\s*([a-z][a-z0-9_-]*)\s*\)\)(?:\s*<\/p>)?/i';
        return preg_replace_callback($legacy, function($m) {
            return $this->renderItem(strtolower($m[1]), strtolower($m[2]));
        }, $value);
    }

    protected function renderItem($kind, $name) {
        $kind = in_array($kind, ['poll', 'quiz'], true) ? $kind : '';
        $name = preg_match('/^[a-z][a-z0-9_-]*$/', (string) $name) ? (string) $name : '';
        if($kind === '' || $name === '') return '<!-- Pulse: invalid token -->';

        $item = $this->pulses()->get($name);
        if(!$item || $item->kind !== $kind) {
            return "<!-- Pulse: {$kind}:{$name} not found -->";
        }
        if(!$item->status) {
            return "<!-- Pulse: {$kind}:{$name} not published -->";
        }

        return $this->assetTags() . $item->render();
    }

    protected function parseAttributes($raw) {
        $attrs = [];
        $raw = trim((string) $raw);
        if($raw === '') return $attrs;

        preg_match_all('/([a-z_]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s]+))|([a-z][a-z0-9_-]*)/i', $raw, $matches, PREG_SET_ORDER);
        foreach($matches as $m) {
            if(!empty($m[1])) {
                $value = ($m[2] ?? '') !== '' ? $m[2] : ((($m[3] ?? '') !== '') ? $m[3] : ($m[4] ?? ''));
                $attrs[strtolower($m[1])] = $this->wire('sanitizer')->text($value);
            } elseif(!empty($m[5])) {
                $attrs[] = strtolower($m[5]);
            }
        }
        return $attrs;
    }

    /**
     * Asset <link>/<script> tags, emitted once per request before the first widget.
     *
     * @return string
     */
    protected function assetTags() {
        if(self::$assetsInjected) return '';
        self::$assetsInjected = true;
        $url = $this->wire('sanitizer')->entities1($this->wire('config')->urls->siteModules . 'Pulse/assets/');
        $path = $this->wire('config')->paths->siteModules . 'Pulse/assets/';
        $cssVersion = is_file($path . 'pulse.css') ? filemtime($path . 'pulse.css') : 0;
        $jsVersion = is_file($path . 'pulse.js') ? filemtime($path . 'pulse.js') : 0;
        return "<link rel=\"stylesheet\" href=\"{$url}pulse.css?v={$cssVersion}\">"
            . "<script src=\"{$url}pulse.js?v={$jsVersion}\" defer></script>";
    }

    public static function getModuleConfigInputfields(array $data) {
        $inputfields = new InputfieldWrapper();
        $f = wire('modules')->get('InputfieldMarkup');
        $f->label = 'Tokens';
        $f->value = '<p>Use <code>[[pulse:poll name="my-poll"]]</code> or <code>[[pulse:quiz name="my-quiz"]]</code>.</p>';
        $inputfields->add($f);
        return $inputfields;
    }
}
