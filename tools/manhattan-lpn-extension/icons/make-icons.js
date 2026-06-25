// Erzeugt icon16/48/128.png (coral Kachel + 3 weiße Label-Balken), ohne Abhängigkeiten.
// Aufruf: node make-icons.js
const fs = require('fs'), path = require('path'), zlib = require('zlib');

// --- PNG-Encoder (RGBA, 8-bit) ---
const CRC = (() => { const t = []; for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } return t; })();
function crc32(buf) { let c = 0xffffffff; for (let i = 0; i < buf.length; i++) c = CRC[(c ^ buf[i]) & 0xff] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; }
function chunk(type, data) { const len = Buffer.alloc(4); len.writeUInt32BE(data.length, 0); const t = Buffer.from(type, 'ascii'); const crc = Buffer.alloc(4); crc.writeUInt32BE(crc32(Buffer.concat([t, data])), 0); return Buffer.concat([len, t, data, crc]); }
function encodePNG(w, h, rgba) {
  const sig = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 6;
  const stride = w * 4, raw = Buffer.alloc((stride + 1) * h);
  for (let y = 0; y < h; y++) { raw[y * (stride + 1)] = 0; rgba.copy(raw, y * (stride + 1) + 1, y * stride, y * stride + stride); }
  return Buffer.concat([sig, chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0))]);
}

// --- Form (Einheitsquadrat 0..1) ---
function inRR(px, py, x0, y0, x1, y1, r) {
  if (px < x0 || px > x1 || py < y0 || py > y1) return false;
  const cx = px < x0 + r ? x0 + r : (px > x1 - r ? x1 - r : px);
  const cy = py < y0 + r ? y0 + r : (py > y1 - r ? y1 - r : py);
  const dx = px - cx, dy = py - cy; return dx * dx + dy * dy <= r * r;
}
const BARS = [[0.24, 0.78, 0.31], [0.24, 0.64, 0.50], [0.24, 0.72, 0.69]]; // x0,x1,yMitte
function sampleAt(u, v) {
  for (const [x0, x1, yc] of BARS) if (inRR(u, v, x0, yc - 0.072, x1, yc + 0.072, 0.055)) return [255, 255, 255, 255];
  if (inRR(u, v, 0.04, 0.04, 0.96, 0.96, 0.2)) return [235, 93, 79, 255]; // coral #eb5d4f
  return [0, 0, 0, 0];
}
function render(S) {
  const ss = 4, n = ss * ss, rgba = Buffer.alloc(S * S * 4);
  for (let y = 0; y < S; y++) for (let x = 0; x < S; x++) {
    let R = 0, G = 0, B = 0, A = 0;
    for (let sy = 0; sy < ss; sy++) for (let sx = 0; sx < ss; sx++) {
      const c = sampleAt((x + (sx + 0.5) / ss) / S, (y + (sy + 0.5) / ss) / S);
      R += c[0] * c[3] / 255; G += c[1] * c[3] / 255; B += c[2] * c[3] / 255; A += c[3];
    }
    const a = A / n, i = (y * S + x) * 4;
    rgba[i] = a > 0 ? Math.min(255, Math.round((R / n) / (a / 255))) : 0;
    rgba[i + 1] = a > 0 ? Math.min(255, Math.round((G / n) / (a / 255))) : 0;
    rgba[i + 2] = a > 0 ? Math.min(255, Math.round((B / n) / (a / 255))) : 0;
    rgba[i + 3] = Math.round(a);
  }
  return rgba;
}
for (const S of [16, 48, 128]) {
  fs.writeFileSync(path.join(__dirname, 'icon' + S + '.png'), encodePNG(S, S, render(S)));
  console.log('icon' + S + '.png');
}
