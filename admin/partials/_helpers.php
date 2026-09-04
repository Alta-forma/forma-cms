<?php
if (!defined('ROOT_DIR')) {
    define('ROOT_DIR', dirname(__DIR__, 2));
    require_once ROOT_DIR . '/lib/bootstrap.php';
}
Auth::requireAdmin(false);

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fx_toast_oob(string $message = 'Saved'): string {
    return '<div id="fx-toast" class="toast show" data-show="1" hx-swap-oob="true">' . h($message) . '</div>';
}

/** Sticky security / hosting nags. Pass $oob on htmx action responses so the bar updates without a reload. */
function fx_admin_alerts_html(bool $oob = false): string {
    $alerts = HostingCheck::adminAlerts();
    $attr = $oob ? ' hx-swap-oob="true"' : '';
    $html = '<div id="fx-admin-alerts" class="fx-admin-alerts"' . $attr . '>';
    foreach ($alerts as $a) {
        $href = (string)($a['href'] ?? 'index.php?section=settings&sub=server');
        $cta = (string)($a['cta'] ?? 'Fix this');
        $partial = $href . (str_contains($href, '?') ? '&' : '?') . 'partial=1';
        $html .= '<div class="fx-admin-alert">'
            . '<div class="fx-admin-alert-copy">'
            . '<strong>' . h((string)($a['title'] ?? '')) . '</strong>'
            . '<span>' . h((string)($a['detail'] ?? '')) . '</span>'
            . '</div>'
            . '<a class="standard-btn fx-admin-alert-cta" href="' . h($href) . '"'
            . ' hx-get="' . h($partial) . '" hx-target="#main" hx-push-url="' . h($href) . '">'
            . h($cta) . '</a>'
            . '</div>';
    }
    return $html . '</div>';
}

/** Toggle switch row: label + optional hint on the left, switch on the right. */
function fx_switch(string $name, bool $checked, string $label, string $hint = ''): string {
    $id = 'sw-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
    return '<div class="switch-row">'
        . '<div class="sw-text"><strong>' . h($label) . '</strong>'
        . ($hint !== '' ? '<span class="hint">' . h($hint) . '</span>' : '')
        . '</div>'
        . '<label class="fx-switch" for="' . h($id) . '">'
        . '<input type="checkbox" id="' . h($id) . '" name="' . h($name) . '" value="1"' . ($checked ? ' checked' : '') . '>'
        . '<span class="track"></span>'
        . '</label></div>';
}

/** Panel header with gradient icon badge. */
function fx_panel_header(string $icon, string $title, string $sub = ''): string {
    return '<div class="settings-panel-header">'
        . '<div class="icon-badge"><i class="fas fa-' . h($icon) . '"></i></div>'
        . '<div><h2>' . h($title) . '</h2>'
        . ($sub !== '' ? '<p>' . h($sub) . '</p>' : '')
        . '</div></div>';
}

/** Open the scrolling body of a settings pane (footer sits outside this). */
function fx_settings_scroll_open(): void {
    echo '<div class="settings-scroll">';
}

function fx_settings_scroll_close(): void {
    echo '</div>';
}

/** Sticky 56px pane footer — same chrome as Pages / Posts / Uploads. */
function fx_settings_footer(string $formId, string $label = 'Save', string $icon = 'save'): string {
    return '<footer>'
        . '<div class="buttons"><div class="button-group">'
        . '<button type="submit" form="' . h($formId) . '" class="standard-btn">'
        . '<i class="small fas fa-' . h($icon) . '"></i> ' . h($label)
        . '</button></div></div>'
        . '</footer>';
}

/** Paste field with set/empty pill. */
function fx_seo_paste(string $name, string $value, string $label, string $hint, string $placeholder = ''): string {
    $set = trim($value) !== '';
    $id = 'seo-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
    return '<div class="seo-paste">'
        . '<div class="seo-paste-head"><label for="' . h($id) . '">' . h($label) . '</label>'
        . '<span class="status-badge ' . ($set ? 'ok' : 'off') . '">' . ($set ? 'set' : 'empty') . '</span></div>'
        . '<input type="text" id="' . h($id) . '" name="' . h($name) . '" value="' . h($value) . '"'
        . ' placeholder="' . h($placeholder) . '" autocomplete="off">'
        . ($hint !== '' ? '<span class="hint">' . h($hint) . '</span>' : '')
        . '</div>';
}

/** Styled file picker (hides the native Choose File control). */
function fx_file_pick(string $name, string $accept, string $button = 'Choose file'): string {
    $id = 'file-' . preg_replace('/[^a-z0-9_-]/i', '-', $name);
    return '<div class="fx-file-pick">'
        . '<input type="file" id="' . h($id) . '" name="' . h($name) . '" accept="' . h($accept) . '" class="fx-file-hidden" required>'
        . '<button type="button" class="standard-btn" data-file-trigger>'
        . '<i class="small fas fa-folder-open"></i> ' . h($button) . '</button>'
        . '<span class="fx-file-name" data-file-name>No file chosen</span>'
        . '</div>';
}

/** Copyable URL pill. Pass open:true for an external-link button. */
function fx_url_pill(string $url, array $opts = []): string {
    $open = !empty($opts['open']);
    $html = '<div class="feed-url-row"><span title="' . h($url) . '">' . h($url) . '</span>'
        . '<button type="button" title="Copy URL" aria-label="Copy URL" onclick="navigator.clipboard.writeText(' . h(json_encode($url)) . ').then(()=>{this.innerHTML=\'<i class=&quot;fas fa-check&quot;></i>\';setTimeout(()=>this.innerHTML=\'<i class=&quot;fas fa-copy&quot;></i>\',1200)})"><i class="fas fa-copy"></i></button>';
    if ($open) {
        $html .= '<a href="' . h($url) . '" target="_blank" rel="noopener" title="Open in new tab" aria-label="Open in new tab"><i class="fas fa-external-link-alt"></i></a>';
    }
    return $html . '</div>';
}

/**
 * Text path field with Browse (existing uploads) + Upload.
 *
 * @param array{
 *   label?:string, hint?:string, placeholder?:string, accept?:string,
 *   mode?:string, attrs?:string, preview?:bool, id?:string
 * } $opts
 *   accept: image|audio|any (default image)
 *   mode: path (/uploads/file) | basename (file only) — default path
 */
function fx_media_field(string $name, string $value, array $opts = []): string {
    $label = (string)($opts['label'] ?? '');
    $hint = (string)($opts['hint'] ?? '');
    $placeholder = (string)($opts['placeholder'] ?? '/uploads/…');
    $accept = (string)($opts['accept'] ?? 'image');
    if (!in_array($accept, ['image', 'audio', 'any'], true)) {
        $accept = 'image';
    }
    $mode = (($opts['mode'] ?? 'path') === 'basename') ? 'basename' : 'path';
    $attrs = (string)($opts['attrs'] ?? '');
    $preview = array_key_exists('preview', $opts) ? (bool)$opts['preview'] : ($accept === 'image');
    $id = (string)($opts['id'] ?? ('mf-' . preg_replace('/[^a-z0-9_-]/i', '-', $name)));

    $acceptAttr = match ($accept) {
        'image' => 'image/*,.ico,.svg',
        'audio' => 'audio/*,.mp3,.m4a,.wav,.ogg',
        default => '*/*',
    };

    $html = '<div class="fx-media-field" data-media-accept="' . h($accept) . '" data-media-mode="' . h($mode) . '">';
    if ($label !== '') {
        $html .= '<label for="' . h($id) . '">' . $label . '</label>';
    }
    $html .= '<div class="fx-media-row">'
        . ($preview ? '<div class="fx-media-thumb" data-media-thumb aria-hidden="true"></div>' : '')
        . '<input type="text" id="' . h($id) . '" name="' . h($name) . '" value="' . h($value) . '"'
        . ' placeholder="' . h($placeholder) . '" data-media-input'
        . ($attrs !== '' ? ' ' . $attrs : '') . '>'
        . '<div class="fx-media-actions">'
        . '<button type="button" class="fx-media-btn" data-media-browse title="Choose from Uploads">'
        . '<i class="fas fa-folder-open"></i><span>Browse</span></button>'
        . '<button type="button" class="fx-media-btn primary" data-media-upload title="Upload and use">'
        . '<i class="fas fa-upload"></i><span>Upload</span></button>'
        . '<input type="file" data-media-file accept="' . h($acceptAttr) . '" hidden>'
        . '</div></div>';
    if ($hint !== '') {
        $html .= '<span class="hint">' . $hint . '</span>';
    }
    $html .= '</div>';
    return $html;
}
