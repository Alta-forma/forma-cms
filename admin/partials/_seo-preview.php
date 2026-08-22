<?php
/**
 * SERP + social card preview.
 * Expects: $previewTitle, $previewDesc, $previewImage, $previewUrl (optional)
 */
$previewTitle = $previewTitle ?? 'Page title';
$previewDesc  = $previewDesc ?? '';
$previewImage = $previewImage ?? '';
$previewUrl   = $previewUrl ?? (rtrim(Database::get()->getSetting('site')['url'] ?? 'https://example.com', '/') . '/');
$host = parse_url($previewUrl, PHP_URL_HOST) ?: 'example.com';
$imgAbs = $previewImage !== '' ? (preg_match('#^https?://#i', $previewImage) ? $previewImage : Seo::absoluteUrl($previewImage)) : '';
?>
<div class="seo-preview" data-seo-preview>
    <div class="seo-preview-col">
        <div class="seo-preview-label">Google</div>
        <div class="serp-card">
            <div class="serp-url"><?php echo h($host); ?> › …</div>
            <div class="serp-title" data-preview="title"><?php echo h(mb_strimwidth($previewTitle, 0, 60, '…')); ?></div>
            <div class="serp-desc" data-preview="desc"><?php echo h(mb_strimwidth($previewDesc !== '' ? $previewDesc : 'Meta description will appear here.', 0, 160, '…')); ?></div>
        </div>
    </div>
    <div class="seo-preview-col">
        <div class="seo-preview-label">Social card</div>
        <div class="og-card">
            <div class="og-img" data-preview="image" style="<?php echo $imgAbs !== '' ? 'background-image:url(' . h($imgAbs) . ')' : ''; ?>">
                <?php if ($imgAbs === ''): ?><span>No image</span><?php endif; ?>
            </div>
            <div class="og-body">
                <div class="og-host"><?php echo h(strtoupper($host)); ?></div>
                <div class="og-title" data-preview="og-title"><?php echo h($previewTitle); ?></div>
                <div class="og-desc" data-preview="og-desc"><?php echo h(mb_strimwidth($previewDesc, 0, 120, '…')); ?></div>
            </div>
        </div>
    </div>
</div>
