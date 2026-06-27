<?php
// Settings page for the OLED Boot Logo plugin. Upload an image + company name,
// tune the monochrome conversion with a live preview, then save & deploy. All
// conversion/deploy work happens server-side in action.php.
require_once __DIR__ . '/lib/common.php';
$ol = ol_load_settings();
?>
<style>
#ol{max-width:840px;margin:0 auto;color:#1f2733;font-size:14px}
#ol .intro{color:#6b7280;font-size:13px;margin:0 0 16px}
#ol .cols{display:grid;grid-template-columns:1fr 320px;gap:16px;align-items:start}
@media(max-width:760px){#ol .cols{grid-template-columns:1fr}}
#ol .card{border:1px solid #e4e7ec;border-radius:12px;background:#fff;margin:0 0 14px;overflow:hidden}
#ol .head{display:flex;align-items:center;gap:10px;padding:12px 16px;background:#f6f8fa;border-bottom:1px solid #eceef2}
#ol .head .t{font-size:15px;font-weight:600;flex:1}
#ol .body{padding:16px}
#ol .grid{display:grid;grid-template-columns:130px 1fr;gap:12px 14px;align-items:center}
#ol .lab{font-weight:500;color:#374151}
#ol .help{color:#6b7280;font-size:12.5px;margin-top:3px}
#ol input[type=text],#ol input[type=number],#ol select{padding:8px 10px;border:1px solid #cdd3dc;border-radius:7px;background:#fff;font-size:14px;width:100%;box-sizing:border-box}
#ol input[type=range]{width:100%}
#ol button{padding:9px 15px;border:0;border-radius:7px;background:#2f6fed;color:#fff;font-size:14px;font-weight:600;cursor:pointer}
#ol button.sec{background:#eceef2;color:#374151}
#ol button.danger{background:#fdecec;color:#c0392b}
#ol button:disabled{opacity:.5;cursor:default}
#ol .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
#ol .drop{border:2px dashed #cdd3dc;border-radius:10px;padding:20px;text-align:center;color:#6b7280;cursor:pointer;transition:.15s;background:#fafbfc}
#ol .drop.hover{border-color:#2f6fed;background:#f0f6ff;color:#2f6fed}
#ol .drop b{color:#374151}
#ol .preview-wrap{text-align:center}
#ol .screen{display:inline-block;padding:10px;background:#0b1020;border-radius:10px;border:1px solid #1b2540}
#ol .screen canvas{display:block;image-rendering:pixelated;width:256px;height:128px;border-radius:2px;background:#080a14}
#ol .screen .cap{color:#6b7c9c;font-size:11px;margin-top:6px;letter-spacing:.04em}
#ol .sw{position:relative;display:inline-block;width:46px;height:25px}
#ol .sw input{opacity:0;width:0;height:0}
#ol .sw .sl2{position:absolute;cursor:pointer;inset:0;background:#cbd1da;border-radius:25px;transition:.18s}
#ol .sw .sl2:before{content:"";position:absolute;height:19px;width:19px;left:3px;top:3px;background:#fff;border-radius:50%;transition:.18s}
#ol .sw input:checked + .sl2{background:#2f9e6f}#ol .sw input:checked + .sl2:before{transform:translateX(21px)}
#ol .msg{font-size:13px;margin-top:10px;min-height:18px;color:#374151}
#ol .msg.err{color:#c0392b}#ol .msg.ok{color:#1d8a5b}
#ol .muted{color:#6b7280;font-size:12.5px}
</style>

<div id="ol">
  <p class="intro">Put your company logo and name on the device's 0.96&quot; (128&times;64) OLED. Upload any image, tune it for a monochrome display, and it appears on the OLED at boot and while the device is idle &mdash; no hardware cape required.</p>

  <div class="cols">
    <div>
      <div class="card">
        <div class="head"><span class="t">1 &middot; Image</span></div>
        <div class="body">
          <div class="drop" id="ol-drop">
            <div><b>Click to choose</b> or drop an image here</div>
            <div class="help">PNG, JPG, GIF, BMP or WEBP. High-contrast logos work best.</div>
          </div>
          <input type="file" id="ol-file" accept="image/*" style="display:none">
          <div class="muted" id="ol-filename" style="margin-top:8px"></div>
        </div>
      </div>

      <div class="card">
        <div class="head"><span class="t">2 &middot; Company name &amp; look</span></div>
        <div class="body">
          <div class="grid">
            <div class="lab">Company name</div>
            <div><input type="text" id="ol-caption" maxlength="40" placeholder="e.g. ACME LIGHTS" value="<?=htmlspecialchars($ol['caption'])?>">
              <div class="help">Drawn as text on the screen. Leave blank for image only.</div></div>

            <div class="lab">Text position</div>
            <div><select id="ol-caption_pos">
              <?php foreach(['below'=>'Below the image','above'=>'Above the image','overlay'=>'Over the image (bottom)','none'=>'No text'] as $k=>$v){ $sel=$ol['caption_pos']==$k?'selected':''; echo "<option value=\"$k\" $sel>$v</option>";} ?>
            </select></div>

            <div class="lab">Text size</div>
            <div><input type="number" id="ol-caption_size" min="7" max="28" value="<?=(int)$ol['caption_size']?>">
              <div class="help">Auto-shrinks to fit the 128px width.</div></div>

            <div class="lab">Auto-crop</div>
            <div class="row"><label class="sw"><input type="checkbox" id="ol-autocrop" <?=(($ol['autocrop']??true)?'checked':'')?>><span class="sl2"></span></label>
              <span class="muted">Trim surrounding whitespace so the art fills the screen. Big quality win for logos with margins.</span></div>

            <div class="lab">Fit image</div>
            <div><select id="ol-fit">
              <?php foreach(['contain'=>'Contain (whole logo, letterbox)','cover'=>'Cover (fill, may crop)','stretch'=>'Stretch'] as $k=>$v){ $sel=$ol['fit']==$k?'selected':''; echo "<option value=\"$k\" $sel>$v</option>";} ?>
            </select></div>

            <div class="lab">Conversion</div>
            <div><select id="ol-mode">
              <?php foreach(['logo'=>'Logo / graphic (color silhouette)','photo'=>'Photo / grayscale'] as $k=>$v){ $sel=($ol['mode']??'logo')==$k?'selected':''; echo "<option value=\"$k\" $sel>$v</option>";} ?>
            </select>
              <div class="help"><b>Logo</b>: lights any colour that differs from the background &mdash; keeps bright colours (yellow!) that brightness mode would drop. <b>Photo</b>: classic brightness threshold.</div></div>

            <div class="lab"><span id="ol-thr-lab">Sensitivity</span></div>
            <div><input type="range" id="ol-threshold" min="20" max="235" value="<?=(int)$ol['threshold']?>">
              <div class="help" id="ol-thr-help">How different from the background a pixel must be to light up. Lower = more of the logo lights.</div></div>

            <div class="lab" id="ol-dither-row">Dither</div>
            <div class="row" id="ol-dither-ctl"><label class="sw"><input type="checkbox" id="ol-dither" <?=$ol['dither']?'checked':''?>><span class="sl2"></span></label>
              <span class="muted">Floyd&ndash;Steinberg &mdash; photo mode only.</span></div>

            <div class="lab">Invert</div>
            <div class="row"><label class="sw"><input type="checkbox" id="ol-invert" <?=$ol['invert']?'checked':''?>><span class="sl2"></span></label>
              <span class="muted">Swap lit and dark pixels.</span></div>
          </div>
        </div>
      </div>
    </div>

    <div>
      <div class="card">
        <div class="head"><span class="t">Live preview</span></div>
        <div class="body preview-wrap">
          <div class="screen">
            <canvas id="ol-preview" width="128" height="64"></canvas>
            <div class="cap">128 &times; 64 &middot; SSD1306</div>
          </div>
          <div class="msg" id="ol-msg"></div>
        </div>
      </div>

      <div class="card">
        <div class="head"><span class="t">3 &middot; Save</span></div>
        <div class="body">
          <div class="row" style="margin-bottom:10px">
            <label class="sw"><input type="checkbox" id="ol-enabled" <?=$ol['enabled']?'checked':''?>><span class="sl2"></span></label>
            <span class="lab">Show logo on the OLED</span>
          </div>
          <div class="row">
            <button id="ol-save">Save &amp; deploy</button>
            <button class="sec" id="ol-reboot" title="Reboot the controller so you can watch the logo come up on the OLED">Reboot to show now</button>
          </div>
          <div class="row" style="margin-top:10px">
            <button class="danger sec" id="ol-delete">Remove logo</button>
          </div>
          <div class="help" style="margin-top:10px"><b>Save &amp; deploy</b> writes the logo; it appears on the OLED <b>at boot</b> and whenever the device is idle. (While a playlist is playing, the OLED shows playback status &mdash; standard FPP behaviour.) <b>Reboot to show now</b> restarts the controller so you can watch it come up.</div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var base = 'plugin.php?plugin=oledlogo&page=';
  var $ = function(id){return document.getElementById(id);};
  var pendingFile = null, t = null;

  // Render packed lit-bits {w,h,bits(base64, MSB-first, 1=lit)} onto the canvas
  // exactly as the OLED shows it (lit pixel = cyan, background = dark).
  function drawPacked(p){
    var cv=$('ol-preview'); if(!p||!p.bits){ cv.getContext('2d').clearRect(0,0,cv.width,cv.height); return; }
    cv.width=p.w; cv.height=p.h;
    var ctx=cv.getContext('2d');
    var bin=atob(p.bits), bpr=Math.ceil(p.w/8);
    var img=ctx.createImageData(p.w,p.h);
    for(var y=0;y<p.h;y++){
      for(var x=0;x<p.w;x++){
        var byte=bin.charCodeAt(y*bpr+(x>>3));
        var lit=(byte>>(7-(x&7)))&1;
        var o=(y*p.w+x)*4;
        img.data[o]=lit?180:8; img.data[o+1]=lit?220:10; img.data[o+2]=lit?255:20; img.data[o+3]=255;
      }
    }
    ctx.putImageData(img,0,0);
  }

  function settingsFD(){
    var fd = new FormData();
    fd.append('caption', $('ol-caption').value);
    fd.append('caption_pos', $('ol-caption_pos').value);
    fd.append('caption_size', $('ol-caption_size').value);
    fd.append('fit', $('ol-fit').value);
    fd.append('mode', $('ol-mode').value);
    fd.append('threshold', $('ol-threshold').value);
    fd.append('dither', $('ol-dither').checked?'1':'0');
    fd.append('autocrop', $('ol-autocrop').checked?'1':'0');
    fd.append('invert', $('ol-invert').checked?'1':'0');
    fd.append('enabled', $('ol-enabled').checked?'1':'0');
    if (pendingFile) fd.append('image', pendingFile);
    return fd;
  }
  function msg(text, cls){ var m=$('ol-msg'); m.textContent=text||''; m.className='msg'+(cls?(' '+cls):''); }

  function preview(){
    var fd = settingsFD(); fd.append('op','preview');
    msg('Rendering…');
    fetch(base+'action.php&nopage=1',{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(j){
        if(j.ok && j.preview){ drawPacked(j.preview); msg(''); }
        else msg(j.msg||'Nothing to preview yet.', j.ok?'':'err');
      }).catch(function(){ msg('Preview failed.','err'); });
  }
  function debouncedPreview(){ clearTimeout(t); t=setTimeout(preview, 250); }

  // file picking / drag-drop
  var drop=$('ol-drop'), file=$('ol-file');
  drop.addEventListener('click', function(){ file.click(); });
  file.addEventListener('change', function(){ if(file.files[0]) setFile(file.files[0]); });
  ['dragenter','dragover'].forEach(function(e){ drop.addEventListener(e,function(ev){ev.preventDefault();drop.classList.add('hover');});});
  ['dragleave','drop'].forEach(function(e){ drop.addEventListener(e,function(ev){ev.preventDefault();drop.classList.remove('hover');});});
  drop.addEventListener('drop', function(ev){ var f=ev.dataTransfer.files[0]; if(f) setFile(f); });
  function setFile(f){ pendingFile=f; $('ol-filename').textContent='Selected: '+f.name; preview(); }

  // reflect the selected mode in the threshold label + dither availability
  function syncMode(){
    var photo = $('ol-mode').value === 'photo';
    $('ol-thr-lab').textContent = photo ? 'Brightness cutoff' : 'Sensitivity';
    $('ol-thr-help').textContent = photo
      ? 'Pixels darker than this become lit. Ignored when dithering is on.'
      : 'How different from the background a pixel must be to light up. Lower = more of the logo lights.';
    $('ol-dither-row').style.opacity = photo ? '1' : '.4';
    $('ol-dither-ctl').style.opacity = photo ? '1' : '.4';
    $('ol-dither').disabled = !photo;
  }
  syncMode();

  // tune controls -> live preview
  ['ol-caption','ol-caption_size','ol-threshold'].forEach(function(id){ $(id).addEventListener('input', debouncedPreview); });
  ['ol-caption_pos','ol-fit','ol-dither','ol-invert','ol-mode','ol-autocrop'].forEach(function(id){ var e=$(id); if(e) e.addEventListener('change', preview); });
  $('ol-mode').addEventListener('change', function(){ syncMode(); preview(); });

  $('ol-save').addEventListener('click', function(){
    var fd=settingsFD(); fd.append('op','save');
    msg('Saving…');
    fetch(base+'action.php&nopage=1',{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();}).then(function(j){
        if(j.ok){ if(j.preview)drawPacked(j.preview); pendingFile=null; msg(j.msg||'Saved.','ok'); }
        else msg(j.msg||'Save failed.','err');
      }).catch(function(){ msg('Save failed.','err'); });
  });

  $('ol-reboot').addEventListener('click', function(){
    if(!confirm('Reboot the controller now? Any running playlist will stop for ~30-60s while it restarts, then your logo shows on the OLED at boot.')) return;
    var fd=settingsFD(); fd.append('op','save');
    msg('Saving…');
    fetch(base+'action.php&nopage=1',{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();}).then(function(j){
        if(j.preview)drawPacked(j.preview); pendingFile=null;
        var rf=new FormData(); rf.append('op','reboot');
        msg('Rebooting…');
        return fetch(base+'action.php&nopage=1',{method:'POST',body:rf,credentials:'same-origin'});
      }).then(function(r){return r.json();}).then(function(j){ msg(j.msg||'Rebooting…','ok'); })
      .catch(function(){ msg('Reboot request sent (the controller may already be going down).','ok'); });
  });

  $('ol-delete').addEventListener('click', function(){
    if(!confirm('Remove the saved logo? The OLED will show its default.')) return;
    var fd=new FormData(); fd.append('op','delete');
    fetch(base+'action.php&nopage=1',{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();}).then(function(j){
        msg(j.msg||'Removed.', j.ok?'ok':'err');
        if(j.ok){ drawPacked(null); }
      });
  });

  $('ol-enabled').addEventListener('change', function(){
    var fd=new FormData(); fd.append('op','toggle'); fd.append('enabled', $('ol-enabled').checked?'1':'0');
    fetch(base+'action.php&nopage=1',{method:'POST',body:fd,credentials:'same-origin'})
      .then(function(r){return r.json();}).then(function(j){ msg(j.msg||'', j.ok?'ok':'err'); });
  });

  // load current deployed logo (if any) on first paint
  fetch(base+'action.php&nopage=1',{method:'POST',body:(function(){var f=new FormData();f.append('op','current');return f;})(),credentials:'same-origin'})
    .then(function(r){return r.json();}).then(function(j){
      if(j.ok && j.preview){ drawPacked(j.preview); }
    });
})();
</script>
