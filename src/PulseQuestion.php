<?php namespace ProcessWire;

require_once(__DIR__ . '/PulseOption.php');

/**
 * PulseQuestion
 *
 * A single question belonging to a PulseItem item, holding its answer options.
 *
 * @property int $id
 * @property int $pulse_id
 * @property int $sort
 * @property string $type   // radio | checkbox | boolean | text
 * @property string $text
 * @property string $image
 * @property string $explanation
 * @property string $hint
 * @property int $required
 * @property int $points
 * @property int $archived
 */
class PulseQuestion extends WireData {

    protected $defaults = [
        'id' => 0,
        'pulse_id' => 0,
        'sort' => 0,
        'type' => 'radio',
        'text' => '',
        'image' => '',
        'explanation' => '',
        'hint' => '',
        'required' => 1,
        'points' => 1,
        'archived' => 0,
    ];

    /** @var WireArray|null */
    protected $options = null;

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

        if($key === 'options') {
            return $this->setOptions($value);
        }

        if(isset($this->defaults[$key]) && is_int($this->defaults[$key])) {
            $value = (int) $value;
        }

        return parent::set($key, $value);
    }

    public function get($key) {
        if($key === 'options') return $this->getOptions();
        return parent::get($key);
    }

    /**
     * Answer options as a WireArray of PulseOption.
     *
     * @param bool $refresh
     * @return WireArray
     */
    public function getOptions($refresh = false) {
        if($this->options !== null && !$refresh) return $this->options;

        if(!$this->id) {
            $this->options = $this->wire(new WireArray());
            return $this->options;
        }

        if(!class_exists('ProcessWire\\Pulses')) require_once(__DIR__ . '/Pulses.php');
        $repo = $this->wire(new Pulses());
        $this->options = $repo->loadOptions($this->id);
        return $this->options;
    }

    /**
     * Replace the in-memory options (used before save).
     *
     * @param iterable $options array rows or PulseOption objects
     * @return self
     */
    public function setOptions($options) {
        $arr = $this->wire(new WireArray());
        if(is_iterable($options)) {
            foreach($options as $opt) {
                if(!$opt instanceof PulseOption) {
                    $row = $opt;
                    $opt = $this->wire(new PulseOption());
                    if(is_array($row)) $opt->setArray($row);
                }
                $arr->add($opt);
            }
        }
        $this->options = $arr;
        return $this;
    }
}
