<?php namespace ProcessWire;

/**
 * Verify that a timed exam stays idle until the explicit lead-gate start.
 *
 * Usage:
 *   php tests/pulse-exam-gate-smoke.php /absolute/path/to/processwire/index.php quiz-name
 */

if(PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This smoke test must run from the command line.\n");
    exit(2);
}

$bootstrap = isset($argv[1]) ? (string) $argv[1] : '';
$name = isset($argv[2]) ? (string) $argv[2] : '';

if($bootstrap === '' || !is_file($bootstrap) || !preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
    fwrite(STDERR, "Usage: php tests/pulse-exam-gate-smoke.php /absolute/path/to/processwire/index.php quiz-name\n");
    exit(2);
}

require $bootstrap;
require_once dirname(__DIR__) . '/ProcessPulse.module.php';

$repository = wire(new Pulses());
$item = $repository->get($name);
if(!$item || $item->getMode() !== 'exam' || empty($item->settings['time_limit'])) {
    fwrite(STDERR, "A published timed exam is required.\n");
    exit(1);
}

$key = 'examstart_' . $item->name;
$session = wire('session');
$input = wire('input');
$original = $session->getFor('Pulse', $key);
$process = wire(new ProcessPulse());

try {
    $session->setFor('Pulse', $key, null);
    $input->get->set('name', $name);
    $input->get->set('start', null);
    $idleEvent = wire(new HookEvent());
    $process->handleState($idleEvent);
    $idle = json_decode((string) $idleEvent->return, true);
    if(!is_array($idle) || !empty($idle['exam_started']) || ($idle['time_remaining'] ?? false) !== null) {
        throw new \RuntimeException('Timed exam started before the lead gate.');
    }

    $input->get->set('start', '1');
    $startEvent = wire(new HookEvent());
    $process->handleState($startEvent);
    $started = json_decode((string) $startEvent->return, true);
    $limit = (int) $item->settings['time_limit'];
    if(empty($started['exam_started']) || (int) ($started['time_remaining'] ?? 0) !== $limit) {
        throw new \RuntimeException('Explicit exam start did not initialize the timer.');
    }

    $session->setFor('Pulse', $key, time() - 10);
    $repeatEvent = wire(new HookEvent());
    $process->handleState($repeatEvent);
    $repeated = json_decode((string) $repeatEvent->return, true);
    $remaining = (int) ($repeated['time_remaining'] ?? 0);
    if($remaining > $limit - 9 || $remaining < $limit - 12) {
        throw new \RuntimeException('Repeated start reset the server-authoritative timer.');
    }
} catch(\Throwable $e) {
    $session->setFor('Pulse', $key, $original ?: null);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$session->setFor('Pulse', $key, $original ?: null);
echo "OK: {$name} idle-before-lead, starts-at=" . (int) $item->settings['time_limit'] . "s\n";
