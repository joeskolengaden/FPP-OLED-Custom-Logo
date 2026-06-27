<?php
// common.php - shared paths + settings helpers for the OLED Boot Logo plugin.

// The plugin lives at <media>/plugins/oledlogo, so media is three dirs up.
function ol_media_dir() {
    if (defined('FPP_MEDIA_DIR') && is_dir(FPP_MEDIA_DIR)) return FPP_MEDIA_DIR;
    $g = getenv('MEDIADIR'); if ($g && is_dir($g)) return rtrim($g, '/');
    $up = realpath(__DIR__ . '/../../..');
    if ($up && is_dir($up)) return $up;
    return '/home/fpp/media';
}
function ol_plugin_dir()  { return realpath(__DIR__ . '/..') ?: dirname(__DIR__); }
function ol_config_dir()  { $d = ol_media_dir() . '/config'; if (!is_dir($d)) @mkdir($d, 0775, true); return $d; }

function ol_master_xbm()  { return ol_config_dir() . '/oledlogo.xbm'; }
function ol_settings_file(){ return ol_config_dir() . '/oledlogo.json'; }
function ol_src_glob()    { return ol_config_dir() . '/oledlogo-src.*'; }
function ol_src_path($ext){ return ol_config_dir() . '/oledlogo-src.' . preg_replace('/[^a-z0-9]/','',strtolower($ext)); }

// Deploy targets read by fppoled (see fpp/src/oled/OLEDPages.cpp).
function ol_deploy_targets() {
    return [ ol_media_dir() . '/tmp/cape-image.xbm', '/var/tmp/cape-image.xbm' ];
}

function ol_default_settings() {
    return [
        'enabled'      => true,
        'caption'      => '',
        'caption_pos'  => 'below',   // none|below|above|overlay
        'caption_size' => 14,
        'mode'         => 'logo',    // logo (background-distance) | photo (luma)
        'threshold'    => 96,
        'autocrop'     => true,      // trim surrounding whitespace so art fills the screen
        'dither'       => false,
        'invert'       => false,
        'fit'          => 'contain', // contain|cover|stretch
        'width'        => 128,
        'height'       => 64,
        'src_ext'      => '',
        'updated'      => 0,
    ];
}

function ol_load_settings() {
    $f = ol_settings_file();
    $s = ol_default_settings();
    if (is_file($f)) {
        $j = json_decode(@file_get_contents($f), true);
        if (is_array($j)) $s = array_merge($s, $j);
    }
    return $s;
}

function ol_save_settings($s) {
    $f = ol_settings_file();
    return @file_put_contents($f, json_encode($s, JSON_PRETTY_PRINT)) !== false;
}

// Run the deploy script as root (sudo) so it can write/overwrite the cape-image
// files regardless of who created them. Falls back to a direct PHP copy if sudo
// is unavailable. $action is 'apply' (deploy) or 'clear' (remove). Returns [ok,msg].
function ol_run_deploy($action = 'apply') {
    $script = ol_plugin_dir() . '/scripts/deploy.sh';
    $media  = ol_media_dir();
    if (is_file($script)) {
        $cmd = 'sudo MEDIADIR=' . escapeshellarg($media) . ' bash '
             . escapeshellarg($script) . ' ' . escapeshellarg($action) . ' 2>&1';
        $out = @shell_exec($cmd);
        if ($out !== null) {
            $ok = ($action === 'clear') || (strpos((string)$out, 'deployed ->') !== false);
            return [$ok, $action === 'clear' ? 'Cleared.' : ($ok ? 'Deployed.' : trim((string)$out))];
        }
    }
    // fallback: direct copy (may fail on root-owned targets)
    if ($action === 'clear') { foreach (ol_deploy_targets() as $t) @unlink($t); return [true, 'Cleared.']; }
    $master = ol_master_xbm();
    if (!is_file($master)) return [false, 'No logo saved yet.'];
    $okAny = false;
    foreach (ol_deploy_targets() as $t) {
        if (!is_dir(dirname($t))) @mkdir(dirname($t), 0775, true);
        if (@copy($master, $t)) { @chmod($t, 0666); $okAny = true; }
    }
    return [$okAny, $okAny ? 'Deployed.' : 'Could not write deploy targets.'];
}

// Copy the master XBM to the fppoled-read locations. Returns [ok, messages].
function ol_deploy() {
    $s = ol_load_settings();
    if (empty($s['enabled'])) return [false, 'Plugin disabled - not deploying.'];
    if (!is_file(ol_master_xbm())) return [false, 'No logo has been saved yet.'];
    return ol_run_deploy('apply');
}
