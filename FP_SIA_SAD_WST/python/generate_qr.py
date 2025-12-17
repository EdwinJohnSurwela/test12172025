#!/usr/bin/env python
# generate_qr.py
# Usage: python generate_qr.py "data to encode" "/absolute/or/relative/out.png"
# Requires: qrcode[pil] (pip install qrcode[pil]) and Pillow

import sys

try:
    import qrcode
except Exception as e:
    print('Missing qrcode module:', e, file=sys.stderr)
    sys.exit(2)

if len(sys.argv) < 3:
    print('Usage: generate_qr.py <data> <out_path>', file=sys.stderr)
    sys.exit(2)

data = sys.argv[1]
out_path = sys.argv[2]

try:
    qr = qrcode.QRCode(
        version=None,
        error_correction=qrcode.constants.ERROR_CORRECT_M,
        box_size=6,
        border=4,
    )
    qr.add_data(data)
    qr.make(fit=True)
    img = qr.make_image(fill_color='black', back_color='white')
    img.save(out_path)
    sys.exit(0)
except Exception as e:
    print('Failed to generate QR:', e, file=sys.stderr)
    sys.exit(1)
