<?php
// convert.php - turn any uploaded image (+ optional caption) into an XBM that
// FPP's fppoled draws on the 0.96" SSD1306 OLED as the "cape" / boot logo.
//
// FPP rendering path (verified against fpp/src/oled/OLEDPages.cpp + SSD1306_OLED.c):
//   readCapeImage() reads the XBM bytes, then per byte does  b = reverse(~b)
//   drawBitmap() then walks MSB-first and lights pixels whose bit is set.
// Net polarity in the *file*:  bit 0 => LIT pixel (logo),  bit 1 => dark.
// That is why FPP's own sample cape-image.xbm files use 0xff (all-1) for the
// empty background. We emit the same convention here.
//
// The device-side rasterising (decode any format, scale/pad, render the company
// name) is done with ffmpeg, which ships with every FPP install. We deliberately
// do NOT depend on PHP-GD / GraphicsMagick / ImageMagick / PIL, none of which are
// present on a stock FPP 5.4 image. ffmpeg outputs 8-bit greyscale; this file
// does the 1-bit reduction (threshold or Floyd-Steinberg) and XBM packing in
// pure PHP.

// ---- low level: 4-bit nibble reverse, matching OLEDPages.cpp ----------------
function oledlogo_reverse_byte($n) {
    static $lut = [0x0,0x8,0x4,0xc,0x2,0xa,0x6,0xe,0x1,0x9,0x5,0xd,0x3,0xb,0x7,0xf];
    $n &= 0xff;
    return (($lut[$n & 0xf] << 4) | $lut[$n >> 4]) & 0xff;
}

function oledlogo_ffmpeg_bin() {
    foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/fpp/bin/ffmpeg'] as $p)
        if (is_file($p) && is_executable($p)) return $p;
    return 'ffmpeg';
}

function oledlogo_find_font() {
    $candidates = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
        '/opt/fpp/www/fonts/Roboto-Bold.ttf',
        '/Library/Fonts/Arial.ttf',
        '/System/Library/Fonts/Supplemental/Arial Bold.ttf',
        __DIR__ . '/font.ttf',
    ];
    foreach ($candidates as $f) if (is_file($f)) return $f;
    return null;
}

// escape a value for use inside an ffmpeg filtergraph option
function oledlogo_ff_esc($s) {
    // backslash, then the filtergraph specials
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace([':', "'", ',', '[', ']', ';'],
                     ['\\:', "\\'", '\\,', '\\[', '\\]', '\\;'], $s);
    return $s;
}

// ---- auto-crop: find the content bounding box so the art fills the frame ----
// Detailed logos usually sit in a lot of whitespace; without trimming it the art
// is tiny on a 128x64 screen and detail is lost. We stretch the source to a fixed
// NxN analysis square over white, read it as grey, and find the bbox of non-white
// pixels. Because the stretch is linear per-axis, bbox fractions in the NxN square
// map directly to source fractions - so we can crop the original with ffmpeg
// crop=iw*fw:ih*fh:iw*fx:ih*fy without knowing the source dimensions.
// Returns [fx,fy,fw,fh] (0..1) or null (nothing to crop / all white).
function oledlogo_autocrop_fractions($srcPath, $bgThreshold = 245, $marginPct = 0.04) {
    $ff = oledlogo_ffmpeg_bin();
    $N  = 256;
    $tmp = tempnam(sys_get_temp_dir(), 'olc') . '.gray';
    $chain = "[0:v]scale={$N}:{$N}[fg];[1:v][fg]overlay=0:0[b];[b]format=gray[out]";
    $cmd = $ff . " -hide_banner -loglevel error -y -i " . escapeshellarg($srcPath)
         . " -f lavfi -i " . escapeshellarg("color=c=white:s={$N}x{$N}")
         . " -filter_complex " . escapeshellarg($chain)
         . " -map " . escapeshellarg("[out]") . " -frames:v 1 -pix_fmt gray -f rawvideo "
         . escapeshellarg($tmp) . " 2>&1";
    shell_exec($cmd);
    $raw = @file_get_contents($tmp); @unlink($tmp);
    if ($raw === false || strlen($raw) < $N*$N) return null;

    $minx = $N; $miny = $N; $maxx = -1; $maxy = -1;
    for ($y = 0; $y < $N; $y++) {
        $row = $y * $N;
        for ($x = 0; $x < $N; $x++) {
            if (ord($raw[$row + $x]) < $bgThreshold) {
                if ($x < $minx) $minx = $x; if ($x > $maxx) $maxx = $x;
                if ($y < $miny) $miny = $y; if ($y > $maxy) $maxy = $y;
            }
        }
    }
    if ($maxx < 0) return null; // all white
    $cw = $maxx - $minx + 1; $ch = $maxy - $miny + 1;
    $mx = (int)round($cw * $marginPct); $my = (int)round($ch * $marginPct);
    $minx = max(0, $minx - $mx); $miny = max(0, $miny - $my);
    $maxx = min($N - 1, $maxx + $mx); $maxy = min($N - 1, $maxy + $my);
    $fx = $minx / $N; $fy = $miny / $N;
    $fw = ($maxx - $minx + 1) / $N; $fh = ($maxy - $miny + 1) / $N;
    if ($fw > 0.98 && $fh > 0.98) return null; // already fills the frame
    return [$fx, $fy, $fw, $fh];
}

// ---- rasterise to RGB (W*H*3) with ffmpeg -----------------------------------
// The logo (which may be a transparent PNG) is composited onto a WHITE canvas so
// transparency and padding both become white, then the caption is drawn, then we
// read the opaque RGB. Returns [binary(W*H*3), errString|null].
function oledlogo_raster_rgb($srcPath, $opts) {
    $W = (int)$opts['width'];  $H = (int)$opts['height'];
    $caption = isset($opts['caption']) ? trim($opts['caption']) : '';
    $cpos    = $opts['caption_pos'] ?? ($caption !== '' ? 'below' : 'none');
    if ($caption === '') $cpos = 'none';
    $fit     = $opts['fit'] ?? 'contain';

    // Reserve a band for the caption (below/above). 'overlay' draws on top.
    $csize = max(7, (int)($opts['caption_size'] ?? 13));
    if ($cpos !== 'none' && $caption !== '') {
        // crude width-fit: DejaVu Bold avg advance ~0.62em; shrink to fit width.
        $maxFs = (int)floor(($W - 2) / (0.62 * max(1, strlen($caption))));
        if ($maxFs >= 7 && $maxFs < $csize) $csize = $maxFs;
    }
    $cpad  = (int)($opts['caption_pad'] ?? 2);
    $bandH = ($cpos === 'below' || $cpos === 'above') ? $csize + 2*$cpad : 0;
    $imgAreaH = max(1, $H - $bandH);
    $imgAreaY = ($cpos === 'above') ? $bandH : 0;

    $ff = oledlogo_ffmpeg_bin();
    $tmpOut = tempnam(sys_get_temp_dir(), 'olg') . '.rgb';

    // -- build a labelled filtergraph that composites onto white --
    $inputs = '';
    $chain  = '';
    if ($srcPath && is_file($srcPath)) {
        $inputs = '-i ' . escapeshellarg($srcPath)
                . ' -f lavfi -i ' . escapeshellarg("color=c=white:s={$W}x{$H}");
        // auto-crop the surrounding whitespace so the art fills the screen
        $cropf = '';
        if (($opts['autocrop'] ?? true)) {
            $rect = oledlogo_autocrop_fractions($srcPath);
            if ($rect) {
                $cropf = sprintf("crop=iw*%.4f:ih*%.4f:iw*%.4f:ih*%.4f,",
                                 $rect[2], $rect[3], $rect[0], $rect[1]);
            }
        }
        // Quality chain for detailed line art:
        //  - lanczos downscale (crisper than default bilinear)
        //  - unsharp to pop edges so thin strokes survive the 1-bit threshold
        // Applied to a 2x-supersampled intermediate, then down to target, which
        // keeps far more fine detail than a single big downscale.
        $sharp = ($opts['sharpen'] ?? true) ? ",unsharp=5:5:1.2:5:5:0.0" : "";
        $ss = 2; // supersample factor
        if ($fit === 'stretch') {
            $scale = "{$cropf}scale=".($W*$ss).":".($imgAreaH*$ss).":flags=lanczos{$sharp}"
                   . ",scale={$W}:{$imgAreaH}:flags=lanczos";
            $ovx = "0"; $ovy = "{$imgAreaY}";
        } else if ($fit === 'cover') {
            $scale = "{$cropf}scale=".($W*$ss).":".($imgAreaH*$ss).":force_original_aspect_ratio=increase:flags=lanczos"
                   . ",crop=".($W*$ss).":".($imgAreaH*$ss)."{$sharp},scale={$W}:{$imgAreaH}:flags=lanczos";
            $ovx = "0"; $ovy = "{$imgAreaY}";
        } else { // contain
            $scale = "{$cropf}scale=".($W*$ss).":".($imgAreaH*$ss).":force_original_aspect_ratio=decrease:flags=lanczos{$sharp}"
                   . ",scale=iw/{$ss}:ih/{$ss}:flags=lanczos";
            $ovx = "(W-w)/2"; $ovy = "{$imgAreaY}+({$imgAreaH}-h)/2";
        }
        // [0]=logo scaled, [1]=white canvas; overlay logo (with its alpha) on white
        $chain = "[0:v]{$scale}[fg];[1:v][fg]overlay={$ovx}:{$ovy}[base]";
        $last  = "[base]";
    } else {
        $inputs = '-f lavfi -i ' . escapeshellarg("color=c=white:s={$W}x{$H}");
        $chain  = "[0:v]null[base]";
        $last   = "[base]";
    }

    // caption
    $textfile = null;
    if ($cpos !== 'none' && $caption !== '') {
        $font = oledlogo_find_font();
        if ($font) {
            $textfile = tempnam(sys_get_temp_dir(), 'olt');
            file_put_contents($textfile, $caption);
            if ($cpos === 'above')       $ty = "{$cpad}";
            elseif ($cpos === 'overlay') $ty = "{$H}-th-{$cpad}";
            else                         $ty = "{$imgAreaH}+(({$bandH}-th)/2)"; // below
            $dt = "drawtext=fontfile=" . oledlogo_ff_esc($font)
                . ":textfile=" . oledlogo_ff_esc($textfile)
                . ":fontcolor=black:fontsize={$csize}"
                . ":x=(w-tw)/2:y={$ty}";
            $chain .= ";{$last}{$dt}[txt]";
            $last = "[txt]";
        }
    }
    $chain .= ";{$last}format=rgb24[out]";

    $cmd = $ff . " -hide_banner -loglevel error -y " . $inputs
         . " -filter_complex " . escapeshellarg($chain)
         . " -map " . escapeshellarg("[out]") . " -frames:v 1"
         . " -pix_fmt rgb24 -f rawvideo " . escapeshellarg($tmpOut) . " 2>&1";
    $err = shell_exec($cmd);

    $raw = @file_get_contents($tmpOut);
    @unlink($tmpOut);
    if ($textfile) @unlink($textfile);

    if ($raw === false || strlen($raw) < $W*$H*3)
        return [null, "ffmpeg failed: " . trim((string)$err)];
    return [substr($raw, 0, $W*$H*3), null];
}

// ---- RGB -> 1-bit "lit" grid (true = logo pixel = lit) ----------------------
// Two conversion modes:
//   'logo'  (default) - background-distance keying. A pixel is lit when its
//                        colour differs from the (corner-detected) background by
//                        more than the threshold. Captures bright AND dark inks
//                        (yellow, blue, black) equally -> solid silhouette. This
//                        is what makes a multicolour logo survive monochrome.
//   'photo'           - luminance threshold (dark = lit), optional Floyd-Steinberg
//                        dithering. Best for photographs / greyscale art.
function oledlogo_build_grid($srcPath, $opts) {
    $W = (int)$opts['width'];  $H = (int)$opts['height'];
    list($raw, $err) = oledlogo_raster_rgb($srcPath, $opts);
    if ($raw === null) return [null, $err];

    // unpack RGB
    $R = []; $G = []; $B = [];
    $i = 0;
    for ($y = 0; $y < $H; $y++) {
        for ($x = 0; $x < $W; $x++) {
            $R[$y][$x] = ord($raw[$i]); $G[$y][$x] = ord($raw[$i+1]); $B[$y][$x] = ord($raw[$i+2]);
            $i += 3;
        }
    }

    $mode = $opts['mode'] ?? 'logo';
    $thr  = (int)($opts['threshold'] ?? ($mode === 'photo' ? 128 : 96));
    $lit  = [];

    if ($mode === 'photo') {
        // luminance, optionally dithered
        $grey = [];
        for ($y = 0; $y < $H; $y++)
            for ($x = 0; $x < $W; $x++)
                $grey[$y][$x] = (int)round(0.299*$R[$y][$x] + 0.587*$G[$y][$x] + 0.114*$B[$y][$x]);
        if (!empty($opts['dither'])) {
            for ($y = 0; $y < $H; $y++) for ($x = 0; $x < $W; $x++) {
                $old = $grey[$y][$x]; $new = ($old < 128) ? 0 : 255; $grey[$y][$x] = $new; $e = $old - $new;
                if ($x+1 < $W)              $grey[$y][$x+1]   += $e * 7/16;
                if ($y+1 < $H) { if ($x-1 >= 0) $grey[$y+1][$x-1] += $e * 3/16;
                                            $grey[$y+1][$x]   += $e * 5/16;
                    if ($x+1 < $W)          $grey[$y+1][$x+1] += $e * 1/16; }
            }
            for ($y = 0; $y < $H; $y++) for ($x = 0; $x < $W; $x++) $lit[$y][$x] = ($grey[$y][$x] < 128);
        } else {
            for ($y = 0; $y < $H; $y++) for ($x = 0; $x < $W; $x++) $lit[$y][$x] = ($grey[$y][$x] < $thr);
        }
    } else {
        // logo / background-distance keying
        // detect background from the four corners (median per channel)
        $cs = [];
        foreach ([[0,0],[$W-1,0],[0,$H-1],[$W-1,$H-1]] as $c) { $cs[] = [$R[$c[1]][$c[0]],$G[$c[1]][$c[0]],$B[$c[1]][$c[0]]]; }
        $med = function($arr,$k){ $v=array_map(function($p)use($k){return $p[$k];},$arr); sort($v); return $v[intdiv(count($v),2)]; };
        $br = $med($cs,0); $bg = $med($cs,1); $bb = $med($cs,2);
        for ($y = 0; $y < $H; $y++) for ($x = 0; $x < $W; $x++) {
            $d = max(abs($R[$y][$x]-$br), abs($G[$y][$x]-$bg), abs($B[$y][$x]-$bb));
            $lit[$y][$x] = ($d > $thr);
        }
    }

    if (!empty($opts['invert'])) {
        for ($y = 0; $y < $H; $y++) for ($x = 0; $x < $W; $x++) $lit[$y][$x] = !$lit[$y][$x];
    }

    return [['w' => $W, 'h' => $H, 'lit' => $lit], null];
}

// ---- emit XBM (FPP polarity: bit 0 = lit) -----------------------------------
function oledlogo_grid_to_xbm($grid, $name = 'cape_image') {
    $W = $grid['w']; $H = $grid['h']; $lit = $grid['lit'];
    $bytesPerRow = intdiv($W + 7, 8);
    $bytes = [];
    for ($y = 0; $y < $H; $y++) {
        for ($bx = 0; $bx < $bytesPerRow; $bx++) {
            $b = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $x = $bx*8 + $bit;
                $val = 1; // background => dark
                if ($x < $W && !empty($lit[$y][$x])) $val = 0; // logo => lit
                if ($val) $b |= (1 << $bit); // XBM is LSB-first
            }
            $bytes[] = $b;
        }
    }
    $out  = "#define {$name}_width {$W}\n";
    $out .= "#define {$name}_height {$H}\n";
    $out .= "static unsigned char {$name}_bits[] = {\n";
    $lines = [];
    for ($i = 0; $i < count($bytes); $i += 12) {
        $chunk = array_slice($bytes, $i, 12);
        $lines[] = "   " . implode(", ", array_map(function($v){ return sprintf("0x%02x", $v); }, $chunk));
    }
    $out .= implode(",\n", $lines) . "\n};\n";
    return $out;
}

// ---- packed lit-bits for the browser canvas preview -------------------------
// Format: rows of ceil(W/8) bytes, MSB-first, bit 1 = LIT. Base64-encoded.
function oledlogo_grid_to_packed($grid) {
    $W = $grid['w']; $H = $grid['h']; $lit = $grid['lit'];
    $bpr = intdiv($W + 7, 8);
    $buf = '';
    for ($y = 0; $y < $H; $y++) {
        for ($bx = 0; $bx < $bpr; $bx++) {
            $b = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $x = $bx*8 + $bit;
                if ($x < $W && !empty($lit[$y][$x])) $b |= (1 << (7 - $bit)); // MSB-first
            }
            $buf .= chr($b);
        }
    }
    return ['w' => $W, 'h' => $H, 'bits' => base64_encode($buf)];
}

// ---- parse an XBM back to a packed preview (byte-accurate to fppoled) --------
function oledlogo_parse_xbm($txt) {
    $w = 0; $h = 0;
    if (preg_match('/_width\s+(\d+)/', $txt, $m)) $w = (int)$m[1];
    if (preg_match('/_height\s+(\d+)/', $txt, $m)) $h = (int)$m[1];
    preg_match_all('/0x([0-9a-fA-F]{2})/', $txt, $mm);
    $bytes = array_map('hexdec', $mm[1]);
    return ['w' => $w, 'h' => $h, 'bytes' => $bytes];
}

function oledlogo_xbm_to_packed($xbmText) {
    $p = oledlogo_parse_xbm($xbmText);
    $W = $p['w']; $H = $p['h']; $bytes = $p['bytes'];
    if ($W <= 0 || $H <= 0) return null;
    // Apply fppoled's transform, then re-pack as MSB-first lit bits.
    $store = array_map(function($b){ return oledlogo_reverse_byte((~$b) & 0xff); }, $bytes);
    $srcBpr = intdiv($W + 7, 8);
    $lit = [];
    for ($y = 0; $y < $H; $y++) {
        for ($x = 0; $x < $W; $x++) {
            $byte = $store[$y*$srcBpr + intdiv($x,8)] ?? 0;
            $lit[$y][$x] = ((($byte << ($x & 7)) & 0x80) != 0);
        }
    }
    return oledlogo_grid_to_packed(['w'=>$W,'h'=>$H,'lit'=>$lit]);
}
