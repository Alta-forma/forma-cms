<?php
require_once __DIR__ . '/_helpers.php';
if (!License::isPodcastLicensed()):
?>
<div class="section-container">
    <div class="locked-banner" style="width:100%">
        <i class="fas fa-lock" style="font-size:2rem;color:var(--primary)"></i>
        <h2>Podcasts are a paid unlock</h2>
        <p>Activate a license in Settings → General (or use <code>FX-DEV-LOCAL</code> for local testing).</p>
        <button type="button" class="standard-btn"
                hx-get="index.php?section=settings&partial=1&sub=general"
                hx-target="#main"
                hx-push-url="index.php?section=settings&sub=general">
            Open Settings
        </button>
    </div>
</div>
<?php
else:
    require __DIR__ . '/podcast-unlocked.php';
endif;
