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
                $isImg = MediaRepo::isImageExt($f['ext']) && $f['ext'] !== 'svg';
            ?>
            <div class="file-item upload-item <?php echo $active === $f['filename'] ? 'active' : ''; ?>"
                 title="<?php echo h($f['filename']); ?>"
                 hx-get="partials/uploads.php?file=<?php echo urlencode($f['filename']); ?>"
                 hx-target="#main"
                 hx-swap="innerHTML"
                 hx-push-url="index.php?section=uploads&file=<?php echo urlencode($f['filename']); ?>">
                <?php if ($isImg): ?>
                    <span class="upload-thumb" style="background-image:url('<?php echo h($f['url']); ?>')"></span>
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
        <form id="upload-form" class="dropzone"
              hx-post="actions/uploads-save.php"
              hx-encoding="multipart/form-data"
              hx-target="#main"
              hx-swap="innerHTML"
              onclick="if(!event.target.closest('.browse-btn')) document.getElementById('upload-input').click()"
              ondragover="event.preventDefault(); this.classList.add('dz-drag-hover')"
              ondragleave="this.classList.remove('dz-drag-hover')"
              ondrop="event.preventDefault(); this.classList.remove('dz-drag-hover'); var i=document.getElementById('upload-input'); i.files=event.dataTransfer.files; htmx.trigger('#upload-form','submit')">
            <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">
            <input type="file" name="file[]" id="upload-input" multiple style="display:none"
                   onclick="event.stopPropagation()"
                   onchange="htmx.trigger('#upload-form','submit')">
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

            <div class="form-group">
                <label for="filename-edit">Filename</label>
                <input type="text" id="filename-edit" name="new_filename" value="<?php echo h($sel['filename']); ?>" required>
                <span class="hint">
                    <?php echo number_format($sel['size'] / 1024, 1); ?> KB
                    · <a href="<?php echo h($sel['url']); ?>" target="_blank" rel="noopener">Open</a>
                    · <code class="upload-url"><?php echo h($sel['url']); ?></code>
                    <button type="button" class="linkish" title="Copy URL"
                            onclick="navigator.clipboard.writeText(<?php echo h(json_encode($sel['url'])); ?>).then(()=>{this.textContent='Copied';setTimeout(()=>this.textContent='Copy',1200)})">Copy</button>
                </span>
            </div>

            <?php if (MediaRepo::isTextExt($sel['ext'])): ?>
                <div class="form-group text-editor upload-text-editor">
                    <textarea class="code-editor" name="content" data-mode="<?php echo h($cmMode); ?>" rows="20"><?php echo h($textContent); ?></textarea>
                </div>
            <?php else: ?>
                <div class="form-group preview-content">
                    <?php if (MediaRepo::isImageExt($sel['ext'])): ?>
                        <div><img src="<?php echo h($sel['url']); ?>" alt="<?php echo h($sel['filename']); ?>"></div>
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
