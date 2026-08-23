<?php
require_once __DIR__ . '/_helpers.php';
$files = MediaRepo::list();
$active = (string)($_GET['file'] ?? '');
$sel = $active !== '' ? MediaRepo::get($active) : null;
$showDrop = !$sel;

$textModes = [
    'md' => 'markdown', 'html' => 'htmlmixed', 'htm' => 'htmlmixed',
    'js' => 'javascript', 'css' => 'css', 'json' => 'javascript',
    'xml' => 'xml', 'svg' => 'xml', 'txt' => 'null', 'csv' => 'null', 'rtf' => 'null',
];
$textContent = '';
$cmMode = 'null';
if ($sel && MediaRepo::isTextExt($sel['ext'])) {
    try {
        $textContent = MediaRepo::readText($sel['filename']);
    } catch (Throwable $e) {
        $textContent = '';
    }
    $cmMode = $textModes[$sel['ext']] ?? 'null';
}
?>
<div class="section-container uploads-section">
    <div class="file-list">
        <div class="file-item new-file <?php echo $showDrop ? 'active' : ''; ?>"
             hx-get="partials/uploads.php"
             hx-target="#main"
             hx-swap="innerHTML"
             hx-push-url="index.php?section=uploads">
            <i class="fas fa-cloud-upload-alt"></i> Upload Files
        </div>
        <div class="file-list-content" id="uploads-list">
            <?php if (!$files): ?>
                <div class="hint" style="padding:1rem">No uploads yet</div>
            <?php endif; ?>
            <?php foreach ($files as $f):
                $icon = MediaRepo::iconFor($f['ext']);
                $isImg = MediaRepo::isImageExt($f['ext']);
            ?>
            <div class="file-item upload-item <?php echo $active === $f['filename'] ? 'active' : ''; ?>"
                 title="<?php echo h($f['filename']); ?>"
                 hx-get="partials/uploads.php?file=<?php echo urlencode($f['filename']); ?>"
                 hx-target="#main"
                 hx-swap="innerHTML"
                 hx-push-url="index.php?section=uploads&file=<?php echo urlencode($f['filename']); ?>">
                <?php if ($isImg): ?>
                    <span class="upload-thumb<?php echo $f['ext'] === 'svg' ? ' is-svg' : ''; ?>">
                        <img src="<?php echo h($f['url']); ?>" alt="" loading="lazy" decoding="async"
                             onerror="this.parentNode.classList.add('is-missing');this.remove()">
                    </span>
                <?php else: ?>
                    <i class="fas <?php echo h($icon); ?>"></i>
                <?php endif; ?>
                <span class="upload-name"><?php echo h($f['filename']); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="editor-container">
        <?php if ($showDrop): ?>
        <form id="upload-form" class="dropzone" data-fx-uploads
              onclick="if(!event.target.closest('.browse-btn')) document.getElementById('upload-input').click()"
              ondragover="event.preventDefault(); this.classList.add('dz-drag-hover')"
              ondragleave="this.classList.remove('dz-drag-hover')"
              ondrop="event.preventDefault(); this.classList.remove('dz-drag-hover');">
            <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
            <input type="file" name="file[]" id="upload-input" class="fx-file-hidden" multiple
                   onclick="event.stopPropagation()"
                   onchange="if(window.FormaUploads){window.FormaUploads.fromDropzone(this);}">
            <div class="dz-message">
                <i class="fas fa-cloud-upload-alt"></i>
                <h3>Drop files here or click to upload</h3>
                <p>Images, audio, video, PDF, CSS, JS, Markdown…</p>
                <a href="#" class="browse-btn" onclick="event.preventDefault(); event.stopPropagation(); document.getElementById('upload-input').click()">
                    <i class="fas fa-folder-open"></i> Browse Files
                </a>
            </div>
        </form>
        <?php else: ?>
        <form id="file-preview" class="editor-form upload-preview-form"
              hx-post="actions/uploads-update.php"
              hx-target="#main"
              hx-swap="innerHTML">
            <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
            <input type="hidden" name="filename" value="<?php echo h($sel['filename']); ?>">

            <?php
            $bytes = (int)$sel['size'];
            if ($bytes < 1024) {
                $sizeLabel = $bytes . ' B';
            } elseif ($bytes < 1048576) {
                $kb = $bytes / 1024;
                $sizeLabel = number_format($kb, $kb >= 100 ? 0 : 1) . ' KB';
            } else {
                $sizeLabel = number_format($bytes / 1048576, 1) . ' MB';
            }
            $kind = strtoupper((string)$sel['ext']);
            $when = date('M j, Y', (int)$sel['mtime']);
            ?>

            <div class="upload-asset-head">
                <div class="form-group">
                    <label for="filename-edit">Filename</label>
                    <input type="text" id="filename-edit" name="new_filename" value="<?php echo h($sel['filename']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Public URL</label>
                    <?php echo fx_url_pill($sel['url'], ['open' => true]); ?>
                    <span class="hint upload-asset-stats"><?php echo h($sizeLabel); ?> · <?php echo h($kind); ?> · <?php echo h($when); ?></span>
                </div>
            </div>

            <?php if (MediaRepo::isTextExt($sel['ext'])): ?>
                <div class="form-group text-editor upload-text-editor">
                    <textarea class="code-editor" name="content" data-mode="<?php echo h($cmMode); ?>" rows="20"><?php echo h($textContent); ?></textarea>
                </div>
            <?php else: ?>
                <div class="form-group preview-content">
                    <?php if (MediaRepo::isImageExt($sel['ext'])): ?>
                        <div class="upload-preview-stage">
                            <img src="<?php echo h($sel['url']); ?>" alt="<?php echo h($sel['filename']); ?>"
                                 onerror="this.style.display='none';var n=this.nextElementSibling;if(n)n.hidden=false">
                            <div class="no-preview" hidden>Couldn’t load this image.</div>
                        </div>
                    <?php elseif (MediaRepo::isVideoExt($sel['ext'])): ?>
                        <div><video src="<?php echo h($sel['url']); ?>" controls></video></div>
                    <?php elseif (MediaRepo::isAudioExt($sel['ext'])): ?>
                        <div><audio src="<?php echo h($sel['url']); ?>" controls></audio></div>
                    <?php elseif ($sel['ext'] === 'pdf'): ?>
                        <div class="pdf-frame"><iframe src="<?php echo h($sel['url']); ?>" title="<?php echo h($sel['filename']); ?>"></iframe></div>
                    <?php else: ?>
                        <div class="no-preview">No preview available for this file type</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>

        <footer>
            <div class="buttons">
                <div class="button-group">
                    <button type="submit" form="file-preview" class="standard-btn">
                        <i class="fas fa-save"></i> Save
                    </button>
                    <button type="button" class="delete-btn"
                            hx-post="actions/uploads-delete.php"
                            hx-vals='{"filename":<?php echo json_encode($sel['filename']); ?>,"csrf_token":<?php echo json_encode(Auth::csrf()); ?>}'
                            hx-target="#main"
                            hx-swap="innerHTML"
                            hx-confirm="Delete <?php echo h($sel['filename']); ?>?">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            </div>
        </footer>
        <?php endif; ?>
    </div>
</div>
