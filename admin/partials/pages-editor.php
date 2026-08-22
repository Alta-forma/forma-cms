<?php
require_once __DIR__ . '/_helpers.php';
$file = PageRepo::sanitizeFilename($_GET['file'] ?? '');
$row = $file ? PageRepo::get($file) : null;
$meta = $row ? PageRepo::extractMeta($row['content']) : [];
$filename = $row['filename'] ?? '';
$slug = $meta['slug'] ?? ($filename ? '/' . $filename : '');
$contentType = $row['content_type'] ?? 'html';
$content = $row['content'] ?? "<!--META\nslug: /\ntitle: New Page\n-->\n";
$mode = $contentType === 'md' ? 'markdown' : 'htmlmixed';
$summary = trim(($filename ?: 'new') . ' · ' . ($slug ?: '/') . ' · ' . strtoupper($contentType));
?>
<form id="page-form" class="editor-form"
      hx-post="actions/pages-save.php"
      hx-target="#pages-list"
      hx-swap="outerHTML"
      hx-encoding="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">

    <div class="meta-panel">
        <button type="button" class="meta-panel-toggle" aria-expanded="true">
            <span><i class="fas fa-sliders"></i> Page details</span>
            <span class="meta-panel-summary"><?php echo h($summary); ?></span>
            <i class="fas fa-chevron-down chev"></i>
        </button>
        <div class="meta-panel-body">
            <div class="form-group">
                <label for="filename">Filename</label>
                <input type="text" id="filename" name="filename" required value="<?php echo h($filename); ?>">
                <span class="hint">Unique id (e.g. home, about). Templates: blog-archive, blog-single</span>
            </div>
            <div class="form-group">
                <label for="slug">Slug</label>
                <input type="text" id="slug" name="slug" value="<?php echo h($slug); ?>">
                <span class="hint">URL path, e.g. / or /about</span>
            </div>
            <div class="form-group">
                <label for="content_type">Content Type</label>
                <select id="content_type" name="content_type"
                        onchange="var ta=document.querySelector('#page-form .code-editor'); if(ta&&ta.codemirror){ta.codemirror.setOption('mode', this.value==='md'?'markdown':'htmlmixed'); ta.dataset.mode=this.value==='md'?'markdown':'htmlmixed';}">
                    <option value="html" <?php echo $contentType === 'html' ? 'selected' : ''; ?>>HTML / Twig</option>
                    <option value="md" <?php echo $contentType === 'md' ? 'selected' : ''; ?>>Markdown</option>
                </select>
            </div>
        </div>
    </div>

    <div class="meta-panel collapsed">
        <button type="button" class="meta-panel-toggle" aria-expanded="false">
            <span><i class="fas fa-search"></i> SEO</span>
            <span class="meta-panel-summary"><?php echo h($meta['seo_title'] ?? $meta['title'] ?? 'defaults'); ?></span>
            <i class="fas fa-chevron-down chev"></i>
        </button>
        <div class="meta-panel-body">
            <?php
            $feat = $meta['featured_image'] ?? ($meta['og_image'] ?? '');
            $previewTitle = $meta['seo_title'] ?? ($meta['title'] ?? 'Page title');
            $previewDesc = $meta['seo_description'] ?? ($meta['description'] ?? '');
            $previewImage = $feat;
            $previewUrl = Seo::absoluteUrl($slug ?: '/');
            require __DIR__ . '/_seo-preview.php';
            ?>
            <div class="form-group">
                <label>SEO title <span class="char-count" data-count-for="seo_title"></span></label>
                <input type="text" name="seo_title" value="<?php echo h($meta['seo_title'] ?? ''); ?>" placeholder="Override &lt;title&gt;" data-seo-field="title">
            </div>
            <div class="form-group">
                <label>SEO / meta description <span class="char-count" data-count-for="seo_description"></span></label>
                <textarea name="seo_description" rows="2" data-seo-field="desc"><?php echo h($meta['seo_description'] ?? ($meta['description'] ?? '')); ?></textarea>
            </div>
            <div class="form-group">
                <?php echo fx_media_field('featured_image', $feat, [
                    'label' => 'Featured / social image',
                    'placeholder' => '/uploads/…',
                    'hint' => 'Used for Open Graph, Twitter, and image sitemap',
                    'attrs' => 'data-seo-field="image"',
                ]); ?>
                <input type="hidden" name="og_image" value="<?php echo h($feat); ?>" data-seo-og-mirror>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Canonical URL</label>
                    <input type="text" name="canonical" value="<?php echo h($meta['canonical'] ?? ''); ?>" placeholder="Leave blank = this page’s URL">
                </div>
                <div class="form-group">
                    <label>Schema</label>
                    <select name="schema_type">
                        <?php foreach (['' => 'Site default', 'article' => 'Article', 'none' => 'None (page only)'] as $val => $label): ?>
                        <option value="<?php echo h($val); ?>" <?php echo ($meta['schema_type'] ?? '') === $val ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Robots / indexing</label>
                    <select name="robots">
                        <?php
                        $robots = $meta['robots'] ?? '';
                        $opts = ['' => 'Site default (index)', 'index,follow' => 'Index + follow', 'noindex,follow' => 'Noindex (keep out of sitemap)', 'noindex,nofollow' => 'Noindex + nofollow'];
                        foreach ($opts as $val => $label):
                        ?>
                        <option value="<?php echo h($val); ?>" <?php echo $robots === $val ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Twitter card</label>
                    <select name="twitter_card">
                        <?php foreach (['' => 'Site default', 'summary_large_image' => 'summary_large_image', 'summary' => 'summary'] as $val => $label): ?>
                        <option value="<?php echo h($val); ?>" <?php echo ($meta['twitter_card'] ?? '') === $val ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="form-group" style="flex:1;min-height:0;display:flex;flex-direction:column">
        <textarea name="content" class="code-editor" data-mode="<?php echo h($mode); ?>"><?php echo h($content); ?></textarea>
    </div>
</form>
<footer>
    <div class="buttons">
        <div class="button-group">
            <button type="submit" form="page-form" class="standard-btn" id="btn-save">
                <i class="small fas fa-save"></i> Save
            </button>
            <?php if ($filename && !in_array($filename, ['home', '_404', '_403', '_500'], true)): ?>
            <button type="button" class="delete-btn" id="btn-delete"
                    hx-post="actions/pages-delete.php"
                    hx-vals='{"filename":"<?php echo h($filename); ?>","csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                    hx-target="#main"
                    hx-confirm="Delete page <?php echo h($filename); ?>?">
                <i class="small fas fa-trash"></i> Delete
            </button>
            <?php endif; ?>
        </div>
    </div>
</footer>
