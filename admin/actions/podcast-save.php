<?php
define('ROOT_DIR', dirname(__DIR__, 2));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);

try {
    $row = PodcastRepo::save([
        'episode_id'     => $_POST['episode_id'] ?? '',
        'title'          => $_POST['title'] ?? '',
        'description'    => $_POST['description'] ?? '',
        'show_notes'     => $_POST['show_notes'] ?? '',
        'audio_file'     => $_POST['audio_file'] ?? '',
        'episode_art'    => $_POST['episode_art'] ?? '',
        'duration'       => $_POST['duration'] ?? '00:00:00',
        'episode_number' => $_POST['episode_number'] ?? 0,
        'season_number'  => $_POST['season_number'] ?? 1,
        'explicit'       => !empty($_POST['explicit']),
        'date'           => $_POST['date'] ?? '',
    ]);
    $_GET['file'] = $row['episode_id'] ?? '';
    require ADMIN_DIR . '/partials/_helpers.php';
    require ADMIN_DIR . '/partials/podcast-unlocked.php';
    echo fx_toast_oob('Episode saved');
} catch (Throwable $e) {
    http_response_code(400);
    echo '<p style="padding:2rem;color:var(--error)">' . htmlspecialchars($e->getMessage()) . '</p>';
}
