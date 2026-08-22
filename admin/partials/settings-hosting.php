<?php
require_once __DIR__ . '/_helpers.php';
$db = Database::get();
$checks = HostingCheck::run($db);
$summary = HostingCheck::summarize($checks);
?>
<?php echo fx_panel_header('cloud', 'Hosting check', 'Compatibility checklist for shared hosts — fix-actions try safe local repairs'); ?>

<div class="hosting-summary" id="hosting-summary"
     data-fail="<?php echo (int)$summary['fail']; ?>"
     data-warn="<?php echo (int)$summary['warn']; ?>">
    <span class="fail"><i class="fas fa-times-circle"></i> <?php echo (int)$summary['fail']; ?> fail</span>
    <span class="warn"><i class="fas fa-exclamation-triangle"></i> <?php echo (int)$summary['warn']; ?> warn</span>
    <span class="pass"><i class="fas fa-check-circle"></i> <?php echo (int)$summary['pass']; ?> pass</span>
</div>

<button type="button" class="standard-btn" style="margin-bottom:1rem"
        hx-get="partials/settings-hosting.php"
        hx-target="#settings-panel"
        hx-swap="innerHTML">
    <i class="fas fa-sync-alt"></i> Run checks again
</button>

<div id="hosting-check-list" style="display:flex;flex-direction:column;gap:12px;">
<?php foreach ($checks as $c):
    $level = $c['level'] ?? 'pass';
    $icon = $level === 'fail' ? 'times-circle' : ($level === 'warn' ? 'exclamation-triangle' : 'check-circle');
    $color = $level === 'fail' ? '#f87171' : ($level === 'warn' ? '#fbbf24' : '#4ade80');
?>
    <div class="hosting-check <?php echo h($level); ?>">
        <h4>
            <i class="fas fa-<?php echo $icon; ?>" style="color:<?php echo $color; ?>"></i>
            <?php echo h($c['title'] ?? ''); ?>
        </h4>
        <div class="detail"><?php echo h($c['detail'] ?? ''); ?></div>
        <?php if (!empty($c['fix_steps'])): ?>
            <ul>
                <?php foreach ($c['fix_steps'] as $step): ?>
                    <li><?php echo h($step); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!empty($c['fix_action']['id'])): ?>
            <button type="button" class="standard-btn" style="margin-top:.75rem;min-width:auto;padding:6px 12px"
                    hx-post="actions/hosting-fix.php"
                    hx-vals='{"action":"<?php echo h($c['fix_action']['id']); ?>","csrf_token":"<?php echo h(Auth::csrf()); ?>"}'
                    hx-target="#settings-panel"
                    hx-swap="innerHTML">
                <i class="fas fa-wrench"></i> <?php echo h($c['fix_action']['label'] ?? 'Try fix'); ?>
            </button>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>
<script>
(function(){
  var s = document.getElementById('hosting-summary');
  var dot = document.getElementById('health-dot');
  if (!s || !dot) return;
  var fail = parseInt(s.dataset.fail||'0',10), warn = parseInt(s.dataset.warn||'0',10);
  if (fail > 0) { dot.style.display='block'; dot.style.background='#f87171'; dot.classList.add('pulse'); }
  else if (warn > 0) { dot.style.display='block'; dot.style.background='#fbbf24'; dot.classList.add('pulse'); }
  else { dot.style.display='none'; dot.classList.remove('pulse'); }
})();
</script>
