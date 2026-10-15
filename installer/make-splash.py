#!/usr/bin/env python3
# Kurulum sihirbazı splash resimlerini (BMP) logodan üretir.
# Kullanım: python3 installer/make-splash.py
import os
from PIL import Image, ImageDraw, ImageFont
HERE = os.path.dirname(os.path.abspath(__file__))
logo = Image.open(os.path.join(HERE, '../theme/brand/logo-eposta.png')).convert('RGBA')

def splash(dest, sub):
    W, H = 480, 300
    canvas = Image.new('RGB', (W, H), (255, 255, 255))
    scale = (W - 80) / logo.width
    lw, lh = int(logo.width * scale), int(logo.height * scale)
    lg = logo.resize((lw, lh), Image.LANCZOS)
    bg = Image.new('RGBA', (lw, lh), (255, 255, 255, 255)); bg.alpha_composite(lg)
    canvas.paste(bg.convert('RGB'), ((W - lw) // 2, 90))
    d = ImageDraw.Draw(canvas)
    try:
        font = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 18)
    except Exception:
        font = ImageFont.load_default()
    tw = d.textlength(sub, font=font)
    d.text(((W - tw) // 2, 180), sub, fill=(91, 102, 120), font=font)
    d.rectangle([0, H - 4, W, H], fill=(31, 99, 214))
    canvas.save(os.path.join(HERE, dest))

def header(dest):
    # Kurulum penceresinin üst şeridi (MUI başlık resmi, 150x57)
    W, H = 150, 57
    canvas = Image.new('RGB', (W, H), (255, 255, 255))
    scale = min((W - 12) / logo.width, (H - 14) / logo.height)
    lw, lh = int(logo.width * scale), int(logo.height * scale)
    lg = logo.resize((lw, lh), Image.LANCZOS)
    bg = Image.new('RGBA', (lw, lh), (255, 255, 255, 255)); bg.alpha_composite(lg)
    canvas.paste(bg.convert('RGB'), ((W - lw) // 2, (H - lh) // 2))
    canvas.save(os.path.join(HERE, dest))

splash('splash.bmp', 'Akıllı Tahta Yönetimi')
header('baslik.bmp')
splash('splash-bitti.bmp', 'Kurulum tamamlandı')
print('splash üretildi')

# --- Ajanın çizdiği çerçevesiz kurulum ekranı (2x çözünürlük; ajan ölçekler) ---
# Mantıksal boyut 480x260; yükleme çubuğu yolu x=60..420, y=214..220 (splash.go ile aynı)
def frame(dest, status, color):
    S = 2
    W, H = 480 * S, 260 * S
    c = Image.new('RGB', (W, H), (255, 255, 255))
    scale = (W - 160 * S) / logo.width
    lw, lh = int(logo.width * scale), int(logo.height * scale)
    lg = logo.resize((lw, lh), Image.LANCZOS)
    bg = Image.new('RGBA', (lw, lh), (255, 255, 255, 255)); bg.alpha_composite(lg)
    c.paste(bg.convert('RGB'), ((W - lw) // 2, 70 * S))
    d = ImageDraw.Draw(c)
    try:
        f1 = ImageFont.truetype('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', 15 * S)
    except Exception:
        f1 = ImageFont.load_default()
    tw = d.textlength(status, font=f1)
    d.text(((W - tw) // 2, 180 * S), status, fill=color, font=f1)
    d.rounded_rectangle([60 * S, 214 * S, 420 * S, 220 * S], radius=3 * S, fill=(229, 233, 240))
    d.rectangle([0, 0, W - 1, H - 1], outline=(214, 220, 230), width=S)
    c.save(os.path.join(HERE, '../agent/cmd/aci-ajan/assets', dest), optimize=True)

frame('kurulum.png', 'BilgeceLabs Tahta kuruluyor…', (91, 102, 120))
frame('kurulum-tamam.png', 'Kurulum tamamlandı ✓', (40, 44, 52))
print('kurulum ekranı üretildi')
