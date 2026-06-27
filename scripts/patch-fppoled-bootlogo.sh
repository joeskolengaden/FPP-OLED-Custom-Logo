#!/bin/bash
# patch-fppoled-bootlogo.sh
#
# Patches FPP's OLED status driver so the custom company logo:
#   (1) is read from the persistent /home/fpp/media/config/oledlogo.xbm (preferred),
#       falling back to the cape image - survives the boot-time wipe of media/tmp.
#   (2) is drawn FULL-SCREEN and centered when FPPD is not running (boot / idle).
#   (3) if a second frame /home/fpp/media/config/oledlogo2.xbm exists, ALTERNATES
#       between the two every ~3s - so on a 128x64 panel you can show full artwork
#       on one frame and a big readable wordmark on the other.
#
# Recompiles fppoled. Re-run after any FPP update/rebuild. Idempotent. Backs up.
# Usage:  sudo bash patch-fppoled-bootlogo.sh           # patch + build + restart
#         sudo bash patch-fppoled-bootlogo.sh --revert  # restore the backup

set -e
SRC=/opt/fpp/src/oled/FPPStatusOLEDPage.cpp
BIN=/opt/fpp/src/fppoled

if [ "$1" = "--revert" ]; then
    [ -f "${SRC}.oledlogo.orig" ] && cp -f "${SRC}.oledlogo.orig" "$SRC" && echo "reverted source"
    [ -f "${BIN}.bak" ] && cp -f "${BIN}.bak" "$BIN" && echo "reverted binary"
    systemctl restart fppoled; echo "done (reverted)"; exit 0
fi

[ -f "${SRC}.oledlogo.orig" ] || cp -f "$SRC" "${SRC}.oledlogo.orig"

python3 - "$SRC" <<'PY'
import sys
f = sys.argv[1]; src = open(f).read(); changed = False

# --- Patch 1: read the persistent custom logo first ---
old1 = '''    if (FileExists("/home/fpp/media/tmp/cape-image.xbm")) {
        std::ifstream file("/home/fpp/media/tmp/cape-image.xbm");'''
new1 = '''    // [oledlogo plugin] Prefer a persistent custom logo (survives the boot-time
    // wipe of media/tmp); fall back to a cape-provided image if present.
    std::string imgPath;
    if (FileExists("/home/fpp/media/config/oledlogo.xbm"))
        imgPath = "/home/fpp/media/config/oledlogo.xbm";
    else if (FileExists("/home/fpp/media/tmp/cape-image.xbm"))
        imgPath = "/home/fpp/media/tmp/cape-image.xbm";
    if (!imgPath.empty()) {
        std::ifstream file(imgPath);'''
if "oledlogo plugin] Prefer a persistent" in src: print("patch 1: already applied")
elif old1 in src: src = src.replace(old1, new1, 1); changed = True; print("patch 1: applied")
else: print("patch 1: TARGET NOT FOUND"); sys.exit(1)

# --- Patch 3a: forward-declare the 2nd-frame loader before doIteration ---
old3a = "bool FPPStatusOLEDPage::doIteration(bool& displayOn) {"
new3a = '''// [oledlogo plugin] forward decl; defined below after trim/splitAndTrim/reverse.
static bool oledlogo_load_frame(const std::string& path, std::vector<uint8_t>& out, int& w, int& h);

bool FPPStatusOLEDPage::doIteration(bool& displayOn) {'''
if "oledlogo plugin] forward decl" in src: print("patch 3a: already applied")
elif old3a in src: src = src.replace(old3a, new3a, 1); changed = True; print("patch 3a: applied")
else: print("patch 3a: TARGET NOT FOUND"); sys.exit(1)

# --- Patch 3b: define the loader just before readImage (trim/reverse in scope) ---
old3b = "void FPPStatusOLEDPage::readImage() {"
new3b = '''// [oledlogo plugin] load a second XBM frame, applying the same per-byte transform
// (~b then bit-reverse) that readImage() uses, so it draws identically.
static bool oledlogo_load_frame(const std::string& path, std::vector<uint8_t>& out, int& w, int& h) {
    w = 0; h = 0; out.clear();
    if (!FileExists(path)) return false;
    std::ifstream file(path);
    if (!file.is_open()) return false;
    std::string line; bool readingBytes = false;
    while (std::getline(file, line)) {
        trim(line);
        if (!readingBytes) {
            if (line.find("_width") != std::string::npos) { std::vector<std::string> v = splitAndTrim(line, ' '); w = std::atoi(v[2].c_str()); }
            else if (line.find("_height") != std::string::npos) { std::vector<std::string> v = splitAndTrim(line, ' '); h = std::atoi(v[2].c_str()); }
            else if (line.find("{") != std::string::npos) { readingBytes = true; line = line.substr(line.find("{") + 1); }
        }
        if (readingBytes) {
            std::vector<std::string> v = splitAndTrim(line, ',');
            for (auto& s : v) {
                if (s.find("}") != std::string::npos) { readingBytes = false; break; }
                int i = std::stoi(s, 0, 16);
                uint8_t t8 = (uint8_t)i; t8 = ~t8; t8 = reverse(t8);
                out.push_back(t8);
            }
        }
    }
    file.close();
    return w > 0 && h > 0 && !out.empty();
}

void FPPStatusOLEDPage::readImage() {'''
if "load a second XBM frame" in src: print("patch 3b: already applied")
elif old3b in src: src = src.replace(old3b, new3b, 1); changed = True; print("patch 3b: applied")
else: print("patch 3b: TARGET NOT FOUND"); sys.exit(1)

# --- Patch 2: full-screen splash, alternating two frames if oledlogo2.xbm exists ---
old2 = '''    if (displayOn) {
        clearDisplay();
        int startY = 0;'''
new2 = '''    if (displayOn) {
        clearDisplay();
        // [oledlogo plugin] When FPPD is not running (booting / idle) and a custom
        // logo is loaded, show it full-screen + centered. If a 2nd frame
        // (oledlogo2.xbm) exists, alternate every ~3s so full artwork AND a
        // readable wordmark can both be shown on the tiny panel.
        if (!statusValid && _imageWidth && oledType != OLEDType::TEXT_ONLY
            && FileExists("/home/fpp/media/config/oledlogo.xbm")) {
            static std::vector<uint8_t> _img2; static int _w2 = -1, _h2 = 0;
            if (_w2 == -1) { if (!oledlogo_load_frame("/home/fpp/media/config/oledlogo2.xbm", _img2, _w2, _h2)) _w2 = 0; }
            const std::vector<uint8_t>* im = &_image; int iw = _imageWidth, ih = _imageHeight;
            if (_w2 > 0 && ((_iterationCount / 3) % 2) == 1) { im = &_img2; iw = _w2; ih = _h2; }
            int lx = (GetLEDDisplayWidth()  - iw) / 2; if (lx < 0) lx = 0;
            int ly = (GetLEDDisplayHeight() - ih) / 2; if (ly < 0) ly = 0;
            drawBitmap(lx, ly, &(*im)[0], iw, ih);
            flushDisplay();
            return true;
        }
        int startY = 0;'''
if "oledlogo plugin] When FPPD is not running" in src: print("patch 2: already applied")
elif old2 in src: src = src.replace(old2, new2, 1); changed = True; print("patch 2: applied")
else: print("patch 2: TARGET NOT FOUND"); sys.exit(1)

if changed: open(f, "w").write(src); print("source updated")
else: print("no changes needed")
PY

[ -f "${BIN}.bak" ] || cp -f "$BIN" "${BIN}.bak" 2>/dev/null || true
echo "building fppoled..."
cd /opt/fpp/src && make fppoled
systemctl restart fppoled
echo "done - fppoled patched, built and restarted"
