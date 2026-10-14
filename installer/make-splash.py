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

splash('splash.bmp', 'Akıllı Tahta Yönetimi')
splash('splash-bitti.bmp', 'Kurulum tamamlandı')
print('splash üretildi')
