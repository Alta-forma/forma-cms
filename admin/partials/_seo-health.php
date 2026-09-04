<?php
/**
 * Contextual per-document SEO health — right where the editor already is,
 * instead of only in the central Settings -> SEO health report.
 * Expects: $healthIssues (list from Seo::quickHealth()['issues']), each
 * shaped ['field' => 'title|desc|image|slot', 'severity' => 'warn|info', 'message' => '...'].
 */
$healthIssues = $healthIssues ?? [];
$fixableFields = ['title', 'desc', 'image'];
?>
<?php if ($healthIssues): ?>
<div class="fx-page-health">
    <?php foreach ($healthIssues as $iss): ?>
    <div class="fx-page-health-item is-<?php echo h($iss['severity']); ?>">
        <i class="fas fa-<?php echo $iss['severity'] === 'warn' ? 'triangle-exclamation' : 'circle-info'; ?>"></i>
        <span><?php echo h($iss['message']); ?></span>
        <?php if (in_array($iss['field'], $fixableFields, true)): ?>
        <button type="button" class="fx-page-health-fix" data-fix-field="<?php echo h($iss['field']); ?>">Fix</button>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="fx-page-health is-ok">
    <div class="fx-page-health-item is-ok">
        <i class="fas fa-circle-check"></i>
        <span>SEO looks good on this page.</span>
    </div>
</div>
<?php endif; ?>
