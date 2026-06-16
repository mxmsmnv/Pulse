<?php namespace ProcessWire;

/**
 * PulseOption
 *
 * A single answer option belonging to a PulseQuestion.
 *
 * @property int $id
 * @property int $question_id
 * @property int $sort
 * @property string $label
 * @property string|null $image
 * @property int $is_correct
 * @property string|null $match_value
 * @property array $outcome_points  // { "<outcome_id>": points }
 * @property int $archived
 */
class PulseOption extends WireData {

    protected $defaults = [
        'id' => 0,
        'question_id' => 0,
        'sort' => 0,
        'label' => '',
        'image' => null,
        'is_correct' => 0,
        'match_value' => null,
        'outcome_points' => [],
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

        if($key === 'outcome_points') {
            if(is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [];
            } elseif(!is_array($value)) {
                $value = [];
            }
            // normalise to int points keyed by outcome id
            $clean = [];
            foreach($value as $outcomeId => $points) {
                $clean[(int) $outcomeId] = (int) $points;
            }
            return parent::set('outcome_points', $clean);
        }

        if($key === 'image' || $key === 'match_value') {
            if($value === null || $value === '') return parent::set($key, null);
            return parent::set($key, (string) $value);
        }

        if(isset($this->defaults[$key]) && is_int($this->defaults[$key])) {
            $value = (int) $value;
        }

        return parent::set($key, $value);
    }
}
