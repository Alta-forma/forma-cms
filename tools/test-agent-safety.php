<?php
/**
 * Destructive local integration test for v0.5 agent safety.
 *
 * Run only in a development install:
 *   php tools/test-agent-safety.php
 *
 * The test refuses to run if a real rollback point already exists. It creates
 * uniquely named temporary page/media records, exercises checkpoint stability,
 * approval movement, DB/media restore, derived-output rebuild, and cleans up.
 */
define('ROOT_DIR', dirname(__DIR__));
require ROOT_DIR . '/lib/bootstrap.php';

if (AgentCheckpoint::status()['available']) {
    fwrite(STDERR, "Refusing to replace an existing rollback point.\n");
    exit(2);
}

$suffix = bin2hex(random_bytes(4));
$filename = '_agent_safety_' . $suffix;
$slug = '/' . $filename;
$media = null;
$token = ['id' => 0, 'name' => 'Agent safety test'];
$failure = null;
$checkpointFiles = [
    dirname(DB_FILE) . '/agent-checkpoint.db',
    dirname(DB_FILE) . '/agent-checkpoint.json',
    dirname(DB_FILE) . '/agent-checkpoint.lock',
];

try {
    // The media exists at baseline, then an agent deletes it during the session.
    $media = MediaRepo::saveBase64(
        'agent-safety-' . $suffix . '.txt',
        base64_encode('restore this file'),
        'text/plain'
    );

    AgentCheckpoint::beforeMutation($token);
    PageRepo::save(
        $filename,
        '<html><head>[[seo]]</head><body>approved-v1</body></html>',
        'html',
        $slug
    );
    AgentCheckpoint::preserveDeletedMedia($media['filename']);
    MediaRepo::delete($media['filename']);
    AgentCheckpoint::finishMutation();

    $first = AgentCheckpoint::status();
    AgentCheckpoint::beforeMutation($token);
    $second = AgentCheckpoint::status();
    AgentCheckpoint::finishMutation();
    if (!$first['pending'] || $first['created_at'] !== $second['created_at']) {
        throw new RuntimeException('Rollback point moved during one edit session');
    }

    $firstRestore = AgentCheckpoint::restore('Agent safety test');
    if (!empty($firstRestore['rebuilt']['fallback']['enabled'])
        && empty($firstRestore['rebuilt']['fallback']['complete'])
    ) {
        throw new RuntimeException('Static fallback rebuild was incomplete');
    }
    if (PageRepo::get($filename)) {
        throw new RuntimeException('Database page survived rollback');
    }
    if (!MediaRepo::get($media['filename'])) {
        throw new RuntimeException('Agent-deleted media was not restored');
    }
    if (is_file(StaticFallback::fileForPath($slug))) {
        throw new RuntimeException('Obsolete fallback HTML survived rollback');
    }

    // Approval moves the one point: v1 is kept, v2 is discarded.
    AgentCheckpoint::beforeMutation($token);
    PageRepo::save(
        $filename,
        '<html><head>[[seo]]</head><body>approved-v1</body></html>',
        'html',
        $slug
    );
    AgentCheckpoint::finishMutation();
    AgentCheckpoint::accept('Agent safety test');
    AgentCheckpoint::beforeMutation($token);
    PageRepo::save(
        $filename,
        '<html><head>[[seo]]</head><body>bad-v2</body></html>',
        'html',
        $slug
    );
    AgentCheckpoint::finishMutation();
    $secondRestore = AgentCheckpoint::restore('Agent safety test');
    if (!empty($secondRestore['rebuilt']['fallback']['enabled'])
        && empty($secondRestore['rebuilt']['fallback']['complete'])
    ) {
        throw new RuntimeException('Static fallback rebuild after approval was incomplete');
    }
    $restored = PageRepo::get($filename);
    if (!$restored
        || !str_contains((string)$restored['content'], 'approved-v1')
        || str_contains((string)$restored['content'], 'bad-v2')
    ) {
        throw new RuntimeException('Approval did not move the rollback point');
    }

    echo "PASS: checkpoint, approval, DB/media restore, and fallback cleanup\n";
} catch (Throwable $e) {
    $failure = $e;
    AgentCheckpoint::finishMutation();
    try {
        if (AgentCheckpoint::status()['available']) {
            AgentCheckpoint::restore('Agent safety test cleanup');
        }
    } catch (Throwable $ignored) {
    }
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
} finally {
    AgentCheckpoint::finishMutation();
    if (PageRepo::get($filename)) {
        PageRepo::delete($filename);
    }
    if ($media && MediaRepo::get((string)$media['filename'])) {
        MediaRepo::delete((string)$media['filename']);
    }
    foreach ($checkpointFiles as $file) {
        @unlink($file);
    }
    $trash = dirname(DB_FILE) . '/agent-checkpoint-media';
    foreach (glob($trash . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($trash);
}

if ($failure !== null) {
    exit(1);
}
