<?php
require_once __DIR__ . '/_helpers.php';
$file = PageRepo::sanitizeFilename($_GET['file'] ?? '');
$row = $file ? BlogRepo::get($file) : null;
$site = Database::get()->getSetting('site');
$filename = $row['filename'] ?? '';
$title = $row['title'] ?? '';
$slug = $row['slug'] ?? '';
$author = $row['author'] ?? ($site['default_author'] ?? 'Admin');
$description = $row['description'] ?? '';
$date = $row['published_at'] ? date('Y-m-d', (int)$row['published_at']) : date('Y-m-d');
$categories = implode(', ', json_decode($row['categories'] ?? '[]', true) ?: []);
$tags = implode(', ', json_decode($row['tags'] ?? '[]', true) ?: []);
$seo = json_decode($row['seo_json'] ?? '{}', true) ?: [];
$body = $row['body'] ?? "## New post\n\n";
$summary = trim(($title ?: $filename ?: 'new post') . ($slug ? " · /blog/{$slug}" : '') . ' · ' . $date);
?>
<form id="blog-form" class="editor-form"
      hx-post="actions/blog-save.php"
      hx-target="#blog-list"
      hx-swap="outerHTML">
    <input type="hidden" name="csrf_token" value="<?php echo h(Auth::csrf()); ?>">

    <div class="meta-panel collapsed">
        <button type="button" class="meta-panel-toggle" aria-expanded="false">
            <span><i class="fas fa-sliders"></i> Post metadata</span>
            <span class="meta-panel-summary"><?php echo h($summary); ?></span>
            <i class="fas fa-chevron-down chev"></i>
        </button>
        <div class="meta-panel-body">
            <div class="form-row">
                <div class="form-group">
                    <label for="filename">Filename</label>
                    <input type="text" name="filename" id="filename" required value="<?php echo h($filename); ?>">
                </div>
                <div class="form-group">
                    <label for="slug">Slug</label>
                    <input type="text" name="slug" id="slug" value="<?php echo h($slug); ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" name="title" id="title" required value="<?php echo h($title); ?>">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="date">Publish date</label>
                    <input type="date" name="date" id="date" value="<?php echo h($date); ?>">
                </div>
                <div class="form-group">
                    <label for="author">Author</label>
                    <input type="text" name="author" id="author" value="<?php echo h($author); ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="description">Description</label>
                <input type="text" name="description" id="description" value="<?php echo h($description); ?>">
                <span class="hint">Used in RSS and archive excerpts</span>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="categories">Categories</label>
                    <input type="text" name="categories" id="categories" value="<?php echo h($categories); ?>">
                    <span class="hint">Comma-separated</span>
                </div>
                <div class="form-group">
                    <label for="tags">Tags</label>
                    <input type="text" name="tags" id="tags" value="<?php echo h($tags); ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="meta-panel collapsed">
        <button type="button" class="meta-panel-toggle" aria-expanded="false">
            <span><i class="fas fa-search"></i> SEO</span>
            <span class="meta-panel-summary"><?php echo h(($seo['seo_title'] ?? '') !== '' ? $seo['seo_title'] : ($title !== '' ? $title : 'defaults')); ?></span>
            <i class="fas fa-chevron-down chev"></i>
        </button>
        <div class="meta-panel-body">
            <?php
            $feat = $seo['featured_image'] ?? ($seo['og_image'] ?? '');
            $previewTitle = $seo['seo_title'] ?? ($title !== '' ? $title : 'Post title');
            $previewDesc = $seo['seo_description'] ?? ($description ?? '');
            $previewImage = $feat;
            $previewUrl = Seo::absoluteUrl('/blog/' . ltrim((string)($slug ?? ''), '/'));
            require __DIR__ . '/_seo-preview.php';
            $healthIssues = $row ? Seo::quickHealth(Seo::forPost($row))['issues'] : [];
            require __DIR__ . '/_seo-health.php';
            ?>
            <div class="form-group">
                <label>SEO title <span class="char-count" data-count-for="seo_title"></span></label>
                <input type="text" name="seo_title" value="<?php echo h($seo['seo_title'] ?? ''); ?>" placeholder="Override &lt;title&gt;" data-seo-field="title">
            </div>
            <div class="form-group">
                <label>SEO description <span class="char-count" data-count-for="seo_description"></span></label>
                <textarea name="seo_description" rows="2" data-seo-field="desc"><?php echo h($seo['seo_description'] ?? ''); ?></textarea>
                <span class="hint">Falls back to post description if empty</span>
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
                    <input type="text" name="canonical" value="<?php echo h($seo['canonical'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label>Schema</label>
                    <select name="schema_type">
                        <?php foreach (['' => 'Article (default)', 'article' => 'Article', 'none' => 'None'] as $val => $label): ?>
                        <option value="<?php echo h($val); ?>" <?php echo ($seo['schema_type'] ?? '') === $val ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Robots / indexing</label>
                    <select name="robots">
                        <?php
                        $robots = $seo['robots'] ?? '';
                        foreach (['' => 'Site default (index)', 'index,follow' => 'Index + follow', 'noindex,follow' => 'Noindex (out of sitemap)', 'noindex,nofollow' => 'Noindex + nofollow'] as $val => $label):
                        ?>
                        <option value="<?php echo h($val); ?>" <?php echo $robots === $val ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Twitter card</label>
                    <select name="twitter_card">
                        <?php foreach (['' => 'Site default', 'summary_large_image' => 'summary_large_image', 'summary' => 'summary'] as $val => $label): ?>
                        <option value="<?php echo h($val); ?>" <?php echo ($seo['twitter_card'] ?? '') === $val ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="form-group" style="flex:1;min-height:0;display:flex;flex-direction:column">
        <label>Body (Markdown)</label>
        <textarea name="body" class="code-editor" data-mode="markdown" data-chips="1"><?php echo h($body); ?></textarea>
    </div>
</form>
<footer>
    <div class="buttons">
        <div class="button-group">
            <button type="button" class="standard-btn" data-blog-preview>
                <i class="small fas fa-eye"></i> Preview
            </button>
            <button type="submit" form="blog-form" class="standard-btn">
                <i class="small fas fa-save"></i> Save
            </button>
            <?php if ($filename): ?>
            <button type="button" class="delete-btn"
                    hx-post="actions/blog-delete.php"
                    hx-vals='{"filename":"<?php echo h($filename); ?>","csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                    hx-target="#main"
                    hx-confirm="Delete post <?php echo h($filename); ?>?">
                <i class="small fas fa-trash"></i> Delete
            </button>
            <?php endif; ?>
        </div>
    </div>
</footer>
