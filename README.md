# FPP OLED Custom Logo

Show a **custom company logo and name** on the device's 0.96″ (128×64 SSD1306)
OLED at boot and while the device is idle. Upload any image, type your company
name, tune the monochrome conversion with a live preview, and save. **No hardware
cape required.** Works on **FPP 5.4 → 9.x** (Pi & BeagleBone).

![preview](images/default-logo.png)

The plugin ships with a built-in default logo, so it shows something out of the
box before you configure your own.

## How it works

FPP's OLED status driver (`fppoled`) draws a "cape/vendor logo" from
`/home/fpp/media/tmp/cape-image.xbm` (falling back to `/var/tmp/cape-image.xbm`)
at boot and on the idle status screen. This plugin converts your image to a
compatible **XBM** and puts it where `fppoled` looks for it.

For the best result it also installs an optional small patch to `fppoled`
(`scripts/patch-fppoled-bootlogo.sh`) that:

- reads the logo from the **persistent** `config/oledlogo.xbm` first (so it
  survives the boot-time wipe of `media/tmp`), and
- draws it **full-screen and centered** on the boot/idle screen, and
- if a second frame `config/oledlogo2.xbm` exists, **alternates** the two every
  ~3 s (so a busy logo can show full artwork on one frame and a big readable
  wordmark on the other).

The patch is idempotent, backs up the original, and can be reverted
(`patch-fppoled-bootlogo.sh --revert`). Re-run it after an FPP update/rebuild.

## The monochrome conversion

A 128×64 OLED is 1 bit/pixel. Conversion is done with **ffmpeg** (which ships
with every FPP image) plus pure PHP — no PHP-GD, GraphicsMagick, ImageMagick or
Python required:

1. **Auto-crop** the surrounding whitespace so the artwork fills the screen.
2. Decode + scale (lanczos) and composite onto white via ffmpeg.
3. Reduce to 1-bit by **logo mode** (background-distance keying — keeps bright
   colours like yellow that a brightness threshold would drop) or **photo mode**
   (luminance + optional Floyd–Steinberg dithering).
4. Render the company name as crisp text and emit an **XBM** in FPP's exact
   polarity (`reverse(~b)`, so a `0` bit = lit pixel).

The web-UI preview is byte-accurate — it renders the generated XBM through the
same transform `fppoled` uses.

> A 128×64 panel can't show a highly-detailed logo *and* large readable text at
> once — it's a resolution limit. For busy logos, either accept smaller text,
> simplify to a wordmark, or use the alternating two-frame mode.

## Boot-time deployment

`detect_cape` wipes `media/tmp/*` on every boot, so installation adds a small
systemd unit that re-deploys the logo each boot:

```
oledlogo-boot.service   After=local-fs.target home-fpp-media.mount   Before=fppoled.service
```

It deploys to both `media/tmp/cape-image.xbm` and `/var/tmp/cape-image.xbm`
(the latter isn't wiped by `detect_cape`, so it's the copy that survives). A
`lifecycle` callback (`callbacks.sh`) re-deploys on `fppd` startup too. If no
custom logo is saved, the bundled `default-logo.xbm` is deployed.

## Using it

1. Install the plugin in FPP, or clone into `/home/fpp/media/plugins/oledlogo`.
2. (Recommended) run the boot patch:
   `sudo bash scripts/patch-fppoled-bootlogo.sh`
3. Open **OLED Boot Logo** from the plugin menu.
4. Drop in an image, set the company name, tune mode / threshold / auto-crop with
   the live preview.
5. **Save & deploy** — shows on next boot and while idle.
6. **Reboot to show now** — optional, to watch it come up on the boot screen.

The **Show logo on the OLED** toggle disables it without uninstalling.

## Display type

Targets the common **128×64** SSD1306 (the 0.96″ panel; `LEDDisplayType`
1/2/5/6/7/8/32/33). 128×32 also works. Text-only character LCDs (1602/2004) can't
show bitmaps and aren't supported.

## Files

| File | Purpose |
|------|---------|
| `plugin.php` | Web UI: upload, tune, live preview, save |
| `action.php` | Server actions: convert / save / deploy / reboot |
| `lib/convert.php` | Image → XBM conversion (ffmpeg) + byte-accurate preview |
| `lib/common.php` | Paths + settings + deploy helpers |
| `default-logo.xbm` | Built-in default logo (used when none configured) |
| `callbacks.sh` | `lifecycle` callback → re-deploy on `fppd` startup |
| `scripts/deploy.sh` | Copy the logo into `fppoled`'s read locations |
| `scripts/fpp_install.sh` / `fpp_uninstall.sh` | Install/remove the boot unit |
| `scripts/patch-fppoled-bootlogo.sh` | Optional fppoled patch (persistent read, full-screen, alternation) |

Saved data lives in `/home/fpp/media/config/`: `oledlogo.xbm` (the bitmap),
`oledlogo2.xbm` (optional 2nd frame), `oledlogo.json` (settings), and
`oledlogo-src.*` (your original upload).

## No external dependencies

Conversion uses ffmpeg (bundled with FPP) + pure PHP. Nothing else to install.
