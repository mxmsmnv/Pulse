<?php namespace ProcessWire;

/**
 * Verify that persisted question images reach the public Pulse renderer.
 *
 * Usage:
 *   php tests/pulse-image-smoke.php /absolute/path/to/processwire/index.php quiz-name
 */

if(PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This smoke test must run from the command line.\n");
    exit(2);
}

$bootstrap = isset($argv[1]) ? (string) $argv[1] : '';
$name = isset($argv[2]) ? (string) $argv[2] : '';

if($bootstrap === '' || !is_file($bootstrap) || !preg_match('/^[a-z][a-z0-9_-]*$/', $name)) {
    fwrite(STDERR, "Usage: php tests/pulse-image-smoke.php /absolute/path/to/processwire/index.php quiz-name\n");
    exit(2);
}

require $bootstrap;

$repository = wire(new Pulses());
$item = $repository->get($name);
if(!$item) {
    fwrite(STDERR, "Pulse item not found: {$name}\n");
    exit(1);
}

$expected = 0;
foreach($item->getQuestions() as $question) {
    if((string) $question->image !== '') $expected++;
}

$html = $item->render();
$rendered = substr_count($html, 'class="pulse__question-img"');

if(!preg_match('/<div class="pulse__hp"[^>]*\shidden(?:\s|>)/', $html)) {
    fwrite(STDERR, "Honeypot must be hidden without depending on Pulse CSS.\n");
    exit(1);
}

if($expected !== $rendered) {
    fwrite(STDERR, "Question image mismatch: persisted={$expected}, rendered={$rendered}\n");
    exit(1);
}

echo "OK: {$name} persisted={$expected}, rendered={$rendered}\n";
