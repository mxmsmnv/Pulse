<?php namespace ProcessWire;

/**
 * Verify certificate token integrity and server-rendered fallback markup.
 *
 * Usage:
 *   php tests/pulse-certificate-smoke.php /absolute/path/to/processwire/index.php quiz-name submission-id
 */

if(PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This smoke test must run from the command line.\n");
    exit(2);
}

$bootstrap = isset($argv[1]) ? (string) $argv[1] : '';
$name = isset($argv[2]) ? (string) $argv[2] : '';
$submissionId = isset($argv[3]) ? (int) $argv[3] : 0;

if($bootstrap === '' || !is_file($bootstrap) || !preg_match('/^[a-z][a-z0-9_-]*$/', $name) || $submissionId < 1) {
    fwrite(STDERR, "Usage: php tests/pulse-certificate-smoke.php /absolute/path/to/processwire/index.php quiz-name submission-id\n");
    exit(2);
}

require $bootstrap;
require_once dirname(__DIR__) . '/src/PulseRenderer.php';

$repository = wire(new Pulses());
$item = $repository->get($name);
$submissions = wire(new PulseSubmissions());
$row = $submissions->getSubmissionRow($submissionId);

if(!$item || !$row || (int) $row['pulse_id'] !== (int) $item->id || empty($row['passed']) || empty($item->settings['certificate'])) {
    fwrite(STDERR, "The submission is not eligible for a certificate.\n");
    exit(1);
}

$token = $submissions->certificateToken($submissionId);
if($submissions->verifyCertificateToken($token) !== $submissionId) {
    fwrite(STDERR, "Certificate token verification failed.\n");
    exit(1);
}

$renderer = wire(new PulseRenderer($item));
$resultHtml = $renderer->renderQuizResult([
    'score' => 1,
    'max_score' => 1,
    'percent' => 100,
    'passed' => true,
    'certificate_url' => '/pulse/certificate/' . $token,
]);
if(strpos($resultHtml, 'pulse__cert-link') === false) {
    fwrite(STDERR, "Server-rendered certificate link is missing.\n");
    exit(1);
}

$process = wire('modules')->get('ProcessPulse');
$method = new \ReflectionMethod($process, 'htmlPage');
$method->setAccessible(true);
$fallback = $method->invoke($process, '<p>OK</p>', 'quiz');
if(strpos($fallback, 'pulse--quiz') === false || strpos($fallback, 'pulse--poll') !== false) {
    fwrite(STDERR, "Quiz fallback wrapper is incorrect.\n");
    exit(1);
}

$root = rtrim((string) wire('config')->urls->root, '/');
echo "OK: certificate={$root}/pulse/certificate/{$token}, fallback=pulse--quiz\n";
