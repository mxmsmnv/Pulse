<?php namespace ProcessWire;

require_once(__DIR__ . '/PulseItem.php');

/**
 * Pulses — repository for PulseItem items and their nested entities.
 *
 * Owns the tables: pulse, pulse_questions, pulse_options, pulse_outcomes,
 * pulse_submissions, pulse_answers. All access via prepared PDO statements.
 */
class Pulses extends Wire {

    const TABLE = 'pulse';
    const TABLE_QUESTIONS = 'pulse_questions';
    const TABLE_OPTIONS = 'pulse_options';
    const TABLE_OUTCOMES = 'pulse_outcomes';
    const TABLE_SUBMISSIONS = 'pulse_submissions';
    const TABLE_ANSWERS = 'pulse_answers';

    /** @var WireArray|null in-request cache of all items */
    protected $items = null;

    /* ---------------------------------------------------------------------
     * Install / uninstall
     * ------------------------------------------------------------------ */

    public function install() {
        $database = $this->wire('database');
        foreach($this->schema() as $sql) {
            try {
                $database->exec($sql);
            } catch(\Exception $e) {
                $this->error('Error creating table: ' . $e->getMessage());
                return false;
            }
        }
        return true;
    }

    public function uninstall() {
        $database = $this->wire('database');
        // Drop children first to respect foreign keys.
        $tables = [
            self::TABLE_ANSWERS,
            self::TABLE_SUBMISSIONS,
            self::TABLE_OPTIONS,
            self::TABLE_OUTCOMES,
            self::TABLE_QUESTIONS,
            self::TABLE,
        ];
        try {
            $database->exec('SET FOREIGN_KEY_CHECKS=0');
            foreach($tables as $t) {
                $database->exec("DROP TABLE IF EXISTS `$t`");
            }
            $database->exec('SET FOREIGN_KEY_CHECKS=1');
            return true;
        } catch(\Exception $e) {
            $this->error('Error dropping tables: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Ordered list of CREATE TABLE statements (parent → children).
     *
     * @return array
     */
    protected function schema() {
        $t = self::TABLE;
        $tq = self::TABLE_QUESTIONS;
        $to = self::TABLE_OPTIONS;
        $tout = self::TABLE_OUTCOMES;
        $ts = self::TABLE_SUBMISSIONS;
        $ta = self::TABLE_ANSWERS;

        return [
            "CREATE TABLE IF NOT EXISTS `$t` (
                `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`     VARCHAR(128) NOT NULL,
                `kind`     ENUM('poll','quiz') NOT NULL DEFAULT 'poll',
                `title`    VARCHAR(255) NOT NULL DEFAULT '',
                `intro`    TEXT NULL,
                `status`   TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `settings` JSON NULL,
                `open_at`  INT UNSIGNED NULL,
                `close_at` INT UNSIGNED NULL,
                `created`  INT UNSIGNED NOT NULL DEFAULT 0,
                `modified` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `name` (`name`),
                KEY `kind_status` (`kind`,`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `$tq` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `pulse_id`    INT UNSIGNED NOT NULL,
                `sort`        INT UNSIGNED NOT NULL DEFAULT 0,
                `type`        ENUM('radio','checkbox','boolean','text') NOT NULL DEFAULT 'radio',
                `text`        TEXT NOT NULL,
                `image`       VARCHAR(255) NULL,
                `explanation` TEXT NULL,
                `hint`        TEXT NULL,
                `required`    TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `points`      INT UNSIGNED NOT NULL DEFAULT 1,
                `archived`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `pulse_sort` (`pulse_id`,`sort`),
                CONSTRAINT `fk_q_pulse` FOREIGN KEY (`pulse_id`) REFERENCES `$t`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `$to` (
                `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `question_id`    INT UNSIGNED NOT NULL,
                `sort`           INT UNSIGNED NOT NULL DEFAULT 0,
                `label`          VARCHAR(512) NOT NULL DEFAULT '',
                `image`          VARCHAR(255) NULL,
                `is_correct`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `match_value`    VARCHAR(255) NULL,
                `outcome_points` JSON NULL,
                `archived`       TINYINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `q_sort` (`question_id`,`sort`),
                CONSTRAINT `fk_o_q` FOREIGN KEY (`question_id`) REFERENCES `$tq`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `$tout` (
                `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `pulse_id`    INT UNSIGNED NOT NULL,
                `sort`        INT UNSIGNED NOT NULL DEFAULT 0,
                `label`       VARCHAR(255) NOT NULL DEFAULT '',
                `description` TEXT NULL,
                `image`       VARCHAR(255) NULL,
                `min_score`   INT NULL,
                `max_score`   INT NULL,
                `archived`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `pulse_sort` (`pulse_id`,`sort`),
                CONSTRAINT `fk_out_pulse` FOREIGN KEY (`pulse_id`) REFERENCES `$t`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `$ts` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `pulse_id`   INT UNSIGNED NOT NULL,
                `voter_hash` CHAR(64) NULL,
                `ip_hash`    CHAR(64) NULL,
                `user_id`    INT UNSIGNED NULL,
                `lead_name`  VARCHAR(255) NULL,
                `lead_email` VARCHAR(255) NULL,
                `score`      INT NULL,
                `max_score`  INT NULL,
                `passed`     TINYINT NULL,
                `outcome_id` INT UNSIGNED NULL,
                `attempt_no` INT UNSIGNED NOT NULL DEFAULT 1,
                `time_spent` INT UNSIGNED NULL,
                `complete`   TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `created`    INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                KEY `pulse_voter` (`pulse_id`,`voter_hash`),
                KEY `pulse_ip` (`pulse_id`,`ip_hash`),
                KEY `pulse_user` (`pulse_id`,`user_id`),
                KEY `pulse_created` (`pulse_id`,`created`),
                CONSTRAINT `fk_s_pulse` FOREIGN KEY (`pulse_id`) REFERENCES `$t`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

            "CREATE TABLE IF NOT EXISTS `$ta` (
                `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `submission_id` INT UNSIGNED NOT NULL,
                `question_id`   INT UNSIGNED NOT NULL,
                `option_id`     INT UNSIGNED NULL,
                `text_answer`   VARCHAR(512) NULL,
                `is_correct`    TINYINT NULL,
                PRIMARY KEY (`id`),
                KEY `sub` (`submission_id`),
                KEY `q_opt` (`question_id`,`option_id`),
                CONSTRAINT `fk_a_sub` FOREIGN KEY (`submission_id`) REFERENCES `$ts`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }

    /* ---------------------------------------------------------------------
     * Reads
     * ------------------------------------------------------------------ */

    /**
     * @param bool $refresh
     * @return WireArray
     */
    public function getAll($refresh = false) {
        if($this->items !== null && !$refresh) return $this->items;

        $database = $this->wire('database');
        $table = self::TABLE;
        $items = $this->wire(new WireArray());

        try {
            $query = $database->prepare("SELECT * FROM `$table` ORDER BY `name`");
            $query->execute();
            while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
                $items->add($this->rowToItem($row));
            }
            $this->items = $items;
        } catch(\Exception $e) {
            $this->error('Error loading pulses: ' . $e->getMessage());
        }

        return $items;
    }

    /**
     * @param int $id
     * @return PulseItem|null
     */
    public function getById($id) {
        $id = (int) $id;
        if(!$id) return null;
        return $this->getAll()->get("id=$id");
    }

    /**
     * Get by name (slug) or numeric id.
     *
     * @param string|int $name
     * @return PulseItem|null
     */
    public function get($name) {
        if(is_numeric($name)) return $this->getById((int) $name);
        $name = $this->wire('sanitizer')->selectorValue($name);
        if($name === '') return null;
        return $this->getAll()->get("name=$name");
    }

    /**
     * @param string $kind poll|quiz
     * @return WireArray
     */
    public function findByKind($kind) {
        $kind = $this->wire('sanitizer')->selectorValue($kind);
        return $this->getAll()->find("kind=$kind");
    }

    protected function rowToItem(array $row) {
        $item = $this->wire(new PulseItem());
        $item->setArray($row);
        return $item;
    }

    /**
     * Load questions (with their options) for a pulse id.
     *
     * @param int $pulseId
     * @return WireArray
     */
    public function loadQuestions($pulseId) {
        $pulseId = (int) $pulseId;
        $out = $this->wire(new WireArray());
        if(!$pulseId) return $out;

        $database = $this->wire('database');
        $tq = self::TABLE_QUESTIONS;

        try {
            $query = $database->prepare("SELECT * FROM `$tq` WHERE `pulse_id` = :pid AND `archived` = 0 ORDER BY `sort`, `id`");
            $query->execute([':pid' => $pulseId]);
            while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
                $q = $this->wire(new PulseQuestion());
                $q->setArray($row);
                $q->setOptions($this->loadOptions($row['id']));
                $out->add($q);
            }
        } catch(\Exception $e) {
            $this->error('Error loading questions: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * @param int $questionId
     * @return WireArray
     */
    public function loadOptions($questionId) {
        $questionId = (int) $questionId;
        $out = $this->wire(new WireArray());
        if(!$questionId) return $out;

        $database = $this->wire('database');
        $to = self::TABLE_OPTIONS;

        try {
            $query = $database->prepare("SELECT * FROM `$to` WHERE `question_id` = :qid ORDER BY `sort`, `id`");
            $query->execute([':qid' => $questionId]);
            while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
                $o = $this->wire(new PulseOption());
                $o->setArray($row);
                $out->add($o);
            }
        } catch(\Exception $e) {
            $this->error('Error loading options: ' . $e->getMessage());
        }

        return $out;
    }

    /**
     * @param int $pulseId
     * @return WireArray
     */
    public function loadOutcomes($pulseId) {
        $pulseId = (int) $pulseId;
        $out = $this->wire(new WireArray());
        if(!$pulseId) return $out;

        $database = $this->wire('database');
        $tout = self::TABLE_OUTCOMES;

        try {
            $query = $database->prepare("SELECT * FROM `$tout` WHERE `pulse_id` = :pid AND `archived` = 0 ORDER BY `sort`, `id`");
            $query->execute([':pid' => $pulseId]);
            while($row = $query->fetch(\PDO::FETCH_ASSOC)) {
                $o = $this->wire(new PulseOutcome());
                $o->setArray($row);
                $out->add($o);
            }
        } catch(\Exception $e) {
            $this->error('Error loading outcomes: ' . $e->getMessage());
        }

        return $out;
    }

    /* ---------------------------------------------------------------------
     * Write (transactional)
     * ------------------------------------------------------------------ */

    /**
     * Save an item with its nested questions/options/outcomes in one transaction.
     *
     * @param PulseItem $item
     * @return int|false item id on success, false on failure
     */
    public function save(PulseItem $item) {
        $valid = $item->validate();
        if($valid !== true) {
            $this->error($valid);
            return false;
        }

        $database = $this->wire('database');
        $sanitizer = $this->wire('sanitizer');
        $table = self::TABLE;

        $name = strtolower($sanitizer->name($item->name));
        $kind = in_array($item->kind, ['poll', 'quiz'], true) ? $item->kind : 'poll';
        $title = $this->boundedText($item->title, 255);
        $intro = $item->intro === '' ? null : $sanitizer->purify($item->intro);
        $status = $item->status ? 1 : 0;
        $settings = json_encode($item->settings ?: [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $openAt = $item->open_at ?: null;
        $closeAt = $item->close_at ?: null;

        try {
            $database->beginTransaction();

            if($item->id) {
                // Guard against name collisions on rename.
                $check = $database->prepare("SELECT COUNT(*) FROM `$table` WHERE `name` = :name AND `id` != :id");
                $check->execute([':name' => $name, ':id' => $item->id]);
                if($check->fetchColumn() > 0) {
                    $database->rollBack();
                    $this->error(sprintf($this->_('An item named "%s" already exists'), $name));
                    return false;
                }

                $sql = "UPDATE `$table` SET
                    `name` = :name, `kind` = :kind, `title` = :title, `intro` = :intro,
                    `status` = :status, `settings` = :settings,
                    `open_at` = :open_at, `close_at` = :close_at, `modified` = :modified
                    WHERE `id` = :id";
                $q = $database->prepare($sql);
                $q->execute([
                    ':name' => $name, ':kind' => $kind, ':title' => $title, ':intro' => $intro,
                    ':status' => $status, ':settings' => $settings,
                    ':open_at' => $openAt, ':close_at' => $closeAt,
                    ':modified' => time(), ':id' => $item->id,
                ]);
            } else {
                $check = $database->prepare("SELECT COUNT(*) FROM `$table` WHERE `name` = :name");
                $check->execute([':name' => $name]);
                if($check->fetchColumn() > 0) {
                    $database->rollBack();
                    $this->error(sprintf($this->_('An item named "%s" already exists'), $name));
                    return false;
                }

                $now = time();
                $sql = "INSERT INTO `$table`
                    (`name`,`kind`,`title`,`intro`,`status`,`settings`,`open_at`,`close_at`,`created`,`modified`)
                    VALUES (:name,:kind,:title,:intro,:status,:settings,:open_at,:close_at,:created,:modified)";
                $q = $database->prepare($sql);
                $q->execute([
                    ':name' => $name, ':kind' => $kind, ':title' => $title, ':intro' => $intro,
                    ':status' => $status, ':settings' => $settings,
                    ':open_at' => $openAt, ':close_at' => $closeAt,
                    ':created' => $now, ':modified' => $now,
                ]);
                $item->id = (int) $database->lastInsertId();
                $item->created = $now;
                $item->modified = $now;
            }

            $this->saveQuestions($item);
            $this->saveOutcomes($item);

            $database->commit();
            $this->items = null; // clear cache
            return $item->id;

        } catch(\Exception $e) {
            if($database->inTransaction()) $database->rollBack();
            $this->error('Error saving item: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Upsert questions/options; archive removed rows once responses exist.
     */
    protected function saveQuestions(PulseItem $item) {
        $database = $this->wire('database');
        $tq = self::TABLE_QUESTIONS;
        $pulseId = (int) $item->id;
        $preserveHistory = $this->hasSubmissions($pulseId);

        $existingIds = $this->childIds($tq, 'pulse_id', $pulseId);
        $keptIds = [];

        $sort = 0;
        foreach($item->getQuestions() as $q) {
            /** @var PulseQuestion $q */
            $type = in_array($q->type, ['radio', 'checkbox', 'boolean', 'text'], true) ? $q->type : 'radio';
            $params = [
                ':pulse_id' => $pulseId,
                ':sort' => $sort++,
                ':type' => $type,
                ':text' => (string) $q->text,
                ':image' => $q->image ?: null,
                ':explanation' => $q->explanation === '' ? null : (string) $q->explanation,
                ':hint' => $q->hint === '' ? null : (string) $q->hint,
                ':required' => $q->required ? 1 : 0,
                ':points' => (int) $q->points,
                ':archived' => 0,
            ];

            if($q->id && in_array((int) $q->id, $existingIds, true)) {
                $sql = "UPDATE `$tq` SET `pulse_id`=:pulse_id, `sort`=:sort, `type`=:type,
                    `text`=:text, `image`=:image, `explanation`=:explanation, `hint`=:hint,
                    `required`=:required, `points`=:points, `archived`=:archived WHERE `id`=:id";
                $params[':id'] = (int) $q->id;
                $database->prepare($sql)->execute($params);
            } else {
                $sql = "INSERT INTO `$tq`
                    (`pulse_id`,`sort`,`type`,`text`,`image`,`explanation`,`hint`,`required`,`points`,`archived`)
                    VALUES (:pulse_id,:sort,:type,:text,:image,:explanation,:hint,:required,:points,:archived)";
                $database->prepare($sql)->execute($params);
                $q->id = (int) $database->lastInsertId();
            }

            $keptIds[] = (int) $q->id;
            $this->saveOptions($q, $preserveHistory);
        }

        $this->deleteMissing($tq, 'pulse_id', $pulseId, $keptIds, $preserveHistory);
    }

    /**
     * Upsert options for a question, preserving historical rows when needed.
     */
    protected function saveOptions(PulseQuestion $question, $preserveHistory = false) {
        $database = $this->wire('database');
        $sanitizer = $this->wire('sanitizer');
        $to = self::TABLE_OPTIONS;
        $questionId = (int) $question->id;

        $existingIds = $this->childIds($to, 'question_id', $questionId);
        $keptIds = [];

        $sort = 0;
        foreach($question->getOptions() as $o) {
            /** @var PulseOption $o */
            $outcomePoints = $o->outcome_points ? json_encode($o->outcome_points) : null;
            $params = [
                ':question_id' => $questionId,
                ':sort' => $sort++,
                ':label' => $this->boundedText($o->label, 512),
                ':image' => $o->image ?: null,
                ':is_correct' => $o->is_correct ? 1 : 0,
                ':match_value' => $o->match_value === null || $o->match_value === '' ? null : $this->boundedText($o->match_value, 255),
                ':outcome_points' => $outcomePoints,
                ':archived' => $o->archived ? 1 : 0,
            ];

            if($o->id && in_array((int) $o->id, $existingIds, true)) {
                $sql = "UPDATE `$to` SET `question_id`=:question_id, `sort`=:sort, `label`=:label,
                    `image`=:image, `is_correct`=:is_correct, `match_value`=:match_value,
                    `outcome_points`=:outcome_points, `archived`=:archived WHERE `id`=:id";
                $params[':id'] = (int) $o->id;
                $database->prepare($sql)->execute($params);
            } else {
                $sql = "INSERT INTO `$to`
                    (`question_id`,`sort`,`label`,`image`,`is_correct`,`match_value`,`outcome_points`,`archived`)
                    VALUES (:question_id,:sort,:label,:image,:is_correct,:match_value,:outcome_points,:archived)";
                $database->prepare($sql)->execute($params);
                $o->id = (int) $database->lastInsertId();
            }

            $keptIds[] = (int) $o->id;
        }

        $this->deleteMissing($to, 'question_id', $questionId, $keptIds, $preserveHistory);
    }

    /**
     * Upsert outcomes, preserving historical rows when needed.
     */
    protected function saveOutcomes(PulseItem $item) {
        $database = $this->wire('database');
        $sanitizer = $this->wire('sanitizer');
        $tout = self::TABLE_OUTCOMES;
        $pulseId = (int) $item->id;
        $preserveHistory = $this->hasSubmissions($pulseId);

        $existingIds = $this->childIds($tout, 'pulse_id', $pulseId);
        $keptIds = [];

        $sort = 0;
        foreach($item->getOutcomes() as $o) {
            /** @var PulseOutcome $o */
            $params = [
                ':pulse_id' => $pulseId,
                ':sort' => $sort++,
                ':label' => $this->boundedText($o->label, 255),
                ':description' => $o->description === '' ? null : $sanitizer->purify($o->description),
                ':image' => $o->image ?: null,
                ':min_score' => $o->min_score,
                ':max_score' => $o->max_score,
                ':archived' => 0,
            ];

            if($o->id && in_array((int) $o->id, $existingIds, true)) {
                $sql = "UPDATE `$tout` SET `pulse_id`=:pulse_id, `sort`=:sort, `label`=:label,
                    `description`=:description, `image`=:image, `min_score`=:min_score,
                    `max_score`=:max_score, `archived`=:archived WHERE `id`=:id";
                $params[':id'] = (int) $o->id;
                $database->prepare($sql)->execute($params);
            } else {
                $sql = "INSERT INTO `$tout`
                    (`pulse_id`,`sort`,`label`,`description`,`image`,`min_score`,`max_score`,`archived`)
                    VALUES (:pulse_id,:sort,:label,:description,:image,:min_score,:max_score,:archived)";
                $database->prepare($sql)->execute($params);
                $o->id = (int) $database->lastInsertId();
            }

            $keptIds[] = (int) $o->id;
        }

        $this->deleteMissing($tout, 'pulse_id', $pulseId, $keptIds, $preserveHistory);
    }

    protected function hasSubmissions($pulseId) {
        $ts = self::TABLE_SUBMISSIONS;
        $q = $this->wire('database')->prepare("SELECT 1 FROM `$ts` WHERE `pulse_id` = :pid LIMIT 1");
        $q->execute([':pid' => (int) $pulseId]);
        return (bool) $q->fetchColumn();
    }

    protected function boundedText($value, $max) {
        $text = $this->limitString(trim((string) $value), $max);
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
     * @return int[] existing child ids for a parent
     */
    protected function childIds($table, $parentColumn, $parentId) {
        $database = $this->wire('database');
        $q = $database->prepare("SELECT `id` FROM `$table` WHERE `$parentColumn` = :pid");
        $q->execute([':pid' => (int) $parentId]);
        return array_map('intval', $q->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Delete or archive child rows whose ids are not in $keptIds.
     */
    protected function deleteMissing($table, $parentColumn, $parentId, array $keptIds, $archiveMissing = false) {
        $database = $this->wire('database');
        $operation = $archiveMissing ? "UPDATE `$table` SET `archived` = 1" : "DELETE FROM `$table`";
        if(empty($keptIds)) {
            $q = $database->prepare("$operation WHERE `$parentColumn` = :pid");
            $q->execute([':pid' => (int) $parentId]);
            return;
        }
        $placeholders = implode(',', array_fill(0, count($keptIds), '?'));
        $sql = "$operation WHERE `$parentColumn` = ? AND `id` NOT IN ($placeholders)";
        $params = array_merge([(int) $parentId], array_map('intval', $keptIds));
        $database->prepare($sql)->execute($params);
    }

    /**
     * Delete an item (cascades to nested rows and submissions via FK).
     *
     * @param int|PulseItem $item
     * @return bool
     */
    public function delete($item) {
        $id = is_object($item) ? (int) $item->id : (int) $item;
        if(!$id) return false;

        $database = $this->wire('database');
        $table = self::TABLE;

        try {
            $q = $database->prepare("DELETE FROM `$table` WHERE `id` = :id");
            $q->execute([':id' => $id]);
            $this->items = null;
            return true;
        } catch(\Exception $e) {
            $this->error('Error deleting item: ' . $e->getMessage());
            return false;
        }
    }

    /* ---------------------------------------------------------------------
     * Import / export of definitions (no answers)
     * ------------------------------------------------------------------ */

    /**
     * Export an item definition as a portable payload array (builder format).
     * outcome_points keys are rewritten to idx:N so they survive re-import.
     *
     * @param int $id
     * @return array|null
     */
    public function exportData($id) {
        $item = $this->getById($id);
        if(!$item) return null;

        $outcomeIndex = []; $i = 0; $outcomes = [];
        foreach($item->getOutcomes() as $o) {
            $outcomeIndex[(int) $o->id] = $i++;
            $outcomes[] = [
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
                $row = [
                    'label' => $o->label,
                    'image' => $o->image,
                    'is_correct' => (int) $o->is_correct,
                    'match_value' => $o->match_value,
                    'archived' => (int) $o->archived,
                ];
                if($o->outcome_points) {
                    $op = [];
                    foreach($o->outcome_points as $oid => $pts) {
                        if(isset($outcomeIndex[(int) $oid])) $op['idx:' . $outcomeIndex[(int) $oid]] = (int) $pts;
                    }
                    if($op) $row['outcome_points'] = $op;
                }
                $options[] = $row;
            }
            $questions[] = [
                'type' => $q->type,
                'text' => $q->text,
                'image' => $q->image,
                'explanation' => $q->explanation,
                'hint' => $q->hint,
                'required' => (int) $q->required,
                'points' => (int) $q->points,
                'options' => $options,
            ];
        }

        return [
            'pulse_export' => 1,
            'name' => $item->name,
            'kind' => $item->kind,
            'title' => $item->title,
            'intro' => $item->intro,
            'status' => (int) $item->status,
            'settings' => $item->settings,
            'questions' => $questions,
            'outcomes' => $outcomes,
        ];
    }

    /**
     * Export as a JSON string.
     *
     * @param int $id
     * @return string|false
     */
    public function exportJson($id) {
        $data = $this->exportData($id);
        if($data === null) return false;
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A name not yet used by any item (appends -2, -3, … on conflict).
     *
     * @param string $base
     * @return string
     */
    public function uniqueName($base) {
        $base = strtolower($this->wire('sanitizer')->name($base));
        if($base === '') $base = 'pulse';
        if(!preg_match('/^[a-z]/', $base)) $base = 'pulse-' . $base;
        $name = $base; $n = 1;
        while($this->get($name)) { $n++; $name = $base . '-' . $n; }
        return $name;
    }
}
