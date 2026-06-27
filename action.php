<?php
/*
 * Server-side actions for the OLED Boot Logo plugin. Reached from plugin.php as:
 *   plugin.php?plugin=oledlogo&page=action.php&nopage=1   (POST)
 *
 * Operations (field "op"):
 *   preview  - convert (optional uploaded image + settings) and return a byte-
 *              accurate PNG of what the OLED will show. Does NOT save.
 *   save     - store source image (if uploaded) + settings, build the master
 *              XBM, deploy it. Optional apply=1 restarts fppoled to show it now.
 *   apply    - (re)deploy + restart fppoled.
 *   toggle   - enable/disable (enabled=0|1), deploy or clear accordingly.
 *   delete   - remove the saved logo and deployed copies.
 *   current  - return current settings + preview of the deployed logo.
 */
// Never let PHP notices/warnings/deprecations leak into the JSON body (they
// would corrupt the response). Errors still go to the PHP/FPP log.
@ini_set('display_errors', '0');
@header('Content-Type: application/json');

require_once __DIR__ . '/lib/common.php';
require_once __DIR__ . '/lib/convert.php';

function ol_fail($msg, $extra = []) { echo json_encode(array_merge(['ok'=>false,'msg'=>$msg], $extra)); exit; }
function ol_ok($extra = [])         { echo json_encode(array_merge(['ok'=>true], $extra)); exit; }

$op = $_REQUEST['op'] ?? '';

// Pull settings from the request, falling back to saved values.
function ol_settings_from_request($base) {
    $b = $base;
    $map = [
        'caption'      => 's', 'caption_pos' => 's', 'fit' => 's', 'mode' => 's',
        'caption_size' => 'i', 'threshold'   => 'i',
        'dither'       => 'b', 'invert'      => 'b', 'enabled' => 'b',
        'autocrop'     => 'b',
    ];
    foreach ($map as $k => $t) {
        if (!isset($_REQUEST[$k])) continue;
        $v = $_REQUEST[$k];
        if ($t === 'i') $b[$k] = (int)$v;
        elseif ($t === 'b') $b[$k] = ($v === '1' || $v === 'true' || $v === 1 || $v === true);
        else $b[$k] = (string)$v;
    }
    $b['width'] = 128; $b['height'] = 64;
    return $b;
}

// Resolve which source image to convert: a freshly uploaded file, or the stored
// one. Returns [path, ext, isUpload, isTmp].
function ol_resolve_source($settings) {
    if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $name = $_FILES['image']['name'] ?? 'upload';
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: 'png';
        return [$_FILES['image']['tmp_name'], $ext, true, true];
    }
    // stored source
    $glob = glob(ol_src_glob());
    if ($glob && is_file($glob[0])) {
        $ext = strtolower(pathinfo($glob[0], PATHINFO_EXTENSION));
        return [$glob[0], $ext, false, false];
    }
    return [null, '', false, false];
}

switch ($op) {

case 'preview': {
    $s = ol_settings_from_request(ol_load_settings());
    list($src, $ext) = ol_resolve_source($s);
    if (!$src && trim($s['caption'] ?? '') === '')
        ol_fail('Upload an image or enter a company name to preview.');
    list($grid, $err) = oledlogo_build_grid($src, $s);
    if ($grid === null) ol_fail($err ?: 'Could not render preview.');
    ol_ok(['preview' => oledlogo_grid_to_packed($grid)]);
}

case 'save': {
    $s = ol_settings_from_request(ol_load_settings());
    list($src, $ext, $isUpload) = ol_resolve_source($s);
    if (!$src && trim($s['caption'] ?? '') === '')
        ol_fail('Upload an image or enter a company name first.');

    // Persist a freshly uploaded source so it can be re-tuned later.
    if ($isUpload) {
        foreach (glob(ol_src_glob()) as $old) @unlink($old);
        $dest = ol_src_path($ext);
        if (!@copy($src, $dest)) ol_fail('Could not save the uploaded image.');
        @chmod($dest, 0664);
        $src = $dest; $s['src_ext'] = $ext;
    }

    list($grid, $err) = oledlogo_build_grid($src, $s);
    if ($grid === null) ol_fail($err ?: 'Could not convert the image.');
    $xbm  = oledlogo_grid_to_xbm($grid);
    if (@file_put_contents(ol_master_xbm(), $xbm) === false)
        ol_fail('Could not write the logo file.');
    @chmod(ol_master_xbm(), 0664);

    $s['updated'] = time();
    ol_save_settings($s);

    list($depOk, $depMsg) = ol_deploy();

    ol_ok(['msg' => 'Saved & deployed. The logo shows on the OLED at the next boot (and whenever the device is idle).',
           'preview' => oledlogo_grid_to_packed($grid),
           'deployed' => $depOk]);
}

case 'apply': {
    // Re-deploy only. We intentionally do NOT restart fppoled: stopping it sends
    // the panel a display-off, and on a device that is playing a playlist the OLED
    // shows playlist status (not the logo) anyway - so a restart only blanks the
    // screen. The logo is read by fppoled at boot, so use 'reboot' to see it now.
    list($depOk, $depMsg) = ol_deploy();
    ol_ok(['msg' => $depOk ? 'Deployed. It will appear on the next boot.' : $depMsg]);
}

case 'reboot': {
    list($depOk, $depMsg) = ol_deploy();
    // schedule a reboot shortly so this HTTP response can return first
    @shell_exec('sudo sh -c "sleep 2 && /sbin/reboot" >/dev/null 2>&1 &');
    ol_ok(['msg' => 'Rebooting the controller now - watch the OLED for your logo (about 30-60s).']);
}

case 'toggle': {
    $s = ol_load_settings();
    $s['enabled'] = (($_REQUEST['enabled'] ?? '1') === '1');
    ol_save_settings($s);
    if ($s['enabled']) {
        list($ok, $m) = ol_deploy();
        ol_ok(['enabled' => true, 'msg' => 'Enabled. ' . $m]);
    } else {
        ol_run_deploy('clear');
        ol_ok(['enabled' => false, 'msg' => 'Disabled. The OLED will show its default on next boot.']);
    }
}

case 'delete': {
    @unlink(ol_master_xbm());
    foreach (glob(ol_src_glob()) as $f) @unlink($f);
    ol_run_deploy('clear');
    ol_ok(['msg' => 'Logo removed.']);
}

case 'current': {
    $s = ol_load_settings();
    $resp = ['settings' => $s, 'hasLogo' => is_file(ol_master_xbm()),
             'hasSource' => (bool)glob(ol_src_glob())];
    if (is_file(ol_master_xbm())) {
        $packed = oledlogo_xbm_to_packed(@file_get_contents(ol_master_xbm()));
        if ($packed) $resp['preview'] = $packed;
    }
    ol_ok($resp);
}

default:
    ol_fail('Unknown action.');
}
