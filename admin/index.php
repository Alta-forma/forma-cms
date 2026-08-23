<?php
/**
 * Forma — Admin shell (htmx)
 */
define('ROOT_DIR', dirname(__DIR__));
require_once ROOT_DIR . '/lib/bootstrap.php';
Auth::requireAdmin(false);
Htaccess::ensureDefault();

$section = $_GET['section'] ?? 'pages';
$podcastLicensed = License::isPodcastLicensed();
$valid = ['pages', 'blog', 'uploads', 'snippets', 'settings'];
if ($podcastLicensed) {
    $valid[] = 'podcast';
}
if ($section === 'podcast' && !$podcastLicensed) {
    $section = 'settings';
    $_GET['sub'] = $_GET['sub'] ?? 'general';
}
if (!in_array($section, $valid, true)) {
    $section = 'pages';
}
// Preserve settings subsection for deep links / htmx nav
if ($section === 'settings' && empty($_GET['sub']) && !empty($_GET['subsection'])) {
    $_GET['sub'] = $_GET['subsection'];
}

$csrf = Auth::csrf();
$base = htmlspecialchars(forma_admin_base_href(), ENT_QUOTES, 'UTF-8');
$fxAsset = static function (string $rel): string {
    $path = ADMIN_DIR . '/' . ltrim($rel, '/');
    $v = is_file($path) ? (string)filemtime($path) : (string)time();
    return $rel . '?v=' . $v;
};

// Partial-only requests (htmx into #main)
if (!empty($_GET['partial']) || (($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true' && empty($_GET['full']))) {
    // Section shell (settings may include ?sub=)
    if (isset($_GET['section']) && !isset($_GET['file'])) {
        require ADMIN_DIR . '/partials/' . $section . '.php';
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?php echo $base; ?>">
    <title><?php echo htmlspecialchars(FORMA_PRODUCT); ?> — <?php echo htmlspecialchars(ucfirst($section)); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/6.65.7/codemirror.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/6.65.7/theme/monokai.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($fxAsset('css/core.css'), ENT_QUOTES, 'UTF-8'); ?>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/htmx/2.0.4/htmx.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/6.65.7/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/6.65.7/mode/xml/xml.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/6.65.7/mode/markdown/markdown.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/6.65.7/mode/htmlmixed/htmlmixed.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/6.65.7/mode/css/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/6.65.7/mode/javascript/javascript.min.js"></script>
</head>
<body
    hx-headers='{"X-CSRF-Token":"<?php echo $csrf; ?>"}'
>
    <header>
        <nav>
            <div class="button-group">
                <?php
                $nav = ['pages' => 'file-alt', 'blog' => 'blog'];
                if ($podcastLicensed) {
                    $nav['podcast'] = 'podcast';
                }
                $nav += ['uploads' => 'upload', 'snippets' => 'code', 'settings' => 'cog'];
                foreach ($nav as $s => $icon): ?>
                <button
                    type="button"
                    data-section="<?php echo $s; ?>"
                    class="<?php echo $section === $s ? 'active' : ''; ?>"
                    hx-get="index.php?section=<?php echo $s; ?>&partial=1"
                    hx-target="#main"
                    hx-push-url="index.php?section=<?php echo $s; ?>"
                    hx-on::after-request="document.querySelectorAll('nav button[data-section]').forEach(b=>b.classList.toggle('active', b.dataset.section==='<?php echo $s; ?>'))"
                >
                    <i class="fas fa-<?php echo $icon; ?>"></i> <?php echo ucfirst($s); ?>
                </button>
                <?php endforeach; ?>
            </div>
            <a href="logout.php" id="app-close-btn" title="Log out" style="text-decoration:none;color:inherit;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-xmark"></i>
            </a>
        </nav>
    </header>

    <div class="main-container" id="main">
        <?php
        $partial = ADMIN_DIR . '/partials/' . $section . '.php';
        if (!is_file($partial)) {
            echo '<div style="padding:2rem;color:#f87171">'
                . '<h2>Missing admin partial</h2>'
                . '<p><code>' . htmlspecialchars($partial, ENT_QUOTES, 'UTF-8') . '</code> is not on the server.</p>'
                . '<p>Re-upload the local <code>admin/partials/</code> folder into <code>/admin/partials/</code> '
                . '(not nested as <code>admin/admin/partials</code>).</p>'
                . '</div>';
        } else {
            require $partial;
        }
        ?>
    </div>

    <div id="fx-toast" class="toast" data-show="0">Saved</div>
    <div id="fx-upload-toasts" class="fx-upload-toasts" aria-live="polite" aria-relevant="additions"></div>
    <script src="<?php echo htmlspecialchars($fxAsset('js/editor-toolbar.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
    <script src="<?php echo htmlspecialchars($fxAsset('js/admin.js'), ENT_QUOTES, 'UTF-8'); ?>"></script>
</body>
</html>
