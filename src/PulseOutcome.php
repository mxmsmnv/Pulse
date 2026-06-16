<?php namespace ProcessWire;

/**
 * PulseOutcome
 *
 * A personality-test outcome belonging to a PulseItem item.
 *
 * @property int $id
 * @property int $pulse_id
 * @property int $sort
 * @property string $label
 * @property string $description
 * @property string|null $image
 * @property int|null $min_score
 * @property int|null $max_score
 * @property int $archived
 */
class PulseOutcome extends WireData {

    protected $defaults = [
        'id' => 0,
        'pulse_id' => 0,
        'sort' => 0,
        'label' => '',
        'description' => '',
        'image' => null,
        'min_score' => null,
        'max_score' => null,
        'archived' => 0,
    ];

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

        if($key === 'image') {
            if($value === null || $value === '') return parent::set($key, null);
            return parent::set($key, (string) $value);
        }

        if($key === 'min_score' || $key === 'max_score') {
            if($value === null || $value === '') return parent::set($key, null);
            return parent::set($key, (int) $value);
        }

        if(isset($this->defaults[$key]) && is_int($this->defaults[$key])) {
            $value = (int) $value;
        }

        return parent::set($key, $value);
    }
}
