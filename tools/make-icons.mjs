// Draws the LabMan logo and writes public/favicon.svg plus the PNG icons the installable app needs.
// No dependencies: shapes are rasterised here (4x4 supersampling) and encoded with node's zlib.
// Run from the repository root:  node tools/make-icons.mjs
import { deflateSync } from 'node:zlib';
import { mkdirSync, writeFileSync } from 'node:fs';

const BG_FROM = [0x3b, 0x6c, 0xf6];
const BG_TO = [0x0b, 0x1f, 0x55];
const hex = (c) => '#' + c.map((v) => v.toString(16).padStart(2, '0')).join('');

// The mark, in a 512 x 512 space: an "L" built from rack units, with status lights on the foot.
const SHAPES = [];
const rr = (x, y, w, h, r, color, a = 1) => SHAPES.push({ t: 'r', x, y, w, h, r, color, a });
const dot = (x, y, r, color, a = 1) => SHAPES.push({ t: 'c', x, y, r, color, a });
const WHITE = [255, 255, 255], SLOT = [0x1e, 0x40, 0xaf];
[103, 183, 263].forEach((y) => {
  rr(148, y, 90, 66, 16, WHITE);
  rr(168, y + 29, 40, 8, 4, SLOT, 0.38);
});
rr(148, 343, 216, 66, 16, WHITE);
rr(168, 372, 40, 8, 4, SLOT, 0.38);
dot(296, 376, 11, [0xf5, 0x9e, 0x0b]);
dot(334, 376, 14, [0x16, 0xa3, 0x4a]);

function svg() {
  const parts = SHAPES.map((s) => s.t === 'r'
    ? `<rect x="${s.x}" y="${s.y}" width="${s.w}" height="${s.h}" rx="${s.r}" fill="${hex(s.color)}"${s.a < 1 ? ` opacity="${s.a}"` : ''}/>`
    : `<circle cx="${s.x}" cy="${s.y}" r="${s.r}" fill="${hex(s.color)}"/>`);
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${hex(BG_FROM)}"/><stop offset="1" stop-color="${hex(BG_TO)}"/></linearGradient></defs><rect width="512" height="512" rx="112" fill="url(#g)"/>${parts.join('')}</svg>\n`;
}

const inRR = (s, x, y) => {
  const cx = Math.max(s.x + s.r, Math.min(x, s.x + s.w - s.r));
  const cy = Math.max(s.y + s.r, Math.min(y, s.y + s.h - s.r));
  return (x - cx) ** 2 + (y - cy) ** 2 <= s.r ** 2;
};

// size: output pixels; corner: background corner radius in 512 space (0 = full bleed, for maskable and Apple icons).
function png(size, corner) {
  const k = 512 / size, N = 4, raw = Buffer.alloc((size * 4 + 1) * size);
  for (let py = 0; py < size; py++) {
    raw[py * (size * 4 + 1)] = 0;
    for (let px = 0; px < size; px++) {
      let r = 0, g = 0, b = 0, a = 0;
      for (let sy = 0; sy < N; sy++) for (let sx = 0; sx < N; sx++) {
        const x = (px + (sx + 0.5) / N) * k, y = (py + (sy + 0.5) / N) * k;
        if (corner && !inRR({ x: 0, y: 0, w: 512, h: 512, r: corner }, x, y)) continue;
        const t = (x + y) / 1024;
        let c = BG_FROM.map((v, i) => v + (BG_TO[i] - v) * t);
        for (const s of SHAPES) {
          if (s.t === 'r' ? inRR(s, x, y) : (x - s.x) ** 2 + (y - s.y) ** 2 <= s.r ** 2) c = c.map((v, i) => v + (s.color[i] - v) * s.a);
        }
        r += c[0]; g += c[1]; b += c[2]; a += 255;
      }
      const o = py * (size * 4 + 1) + 1 + px * 4, n = N * N;
      raw[o] = r / Math.max(a / 255, 1); raw[o + 1] = g / Math.max(a / 255, 1); raw[o + 2] = b / Math.max(a / 255, 1); raw[o + 3] = a / n;
    }
  }
  const crcTable = Array.from({ length: 256 }, (_, n) => { let c = n; for (let i = 0; i < 8; i++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0; });
  const crc = (buf) => { let c = 0xffffffff; for (const v of buf) c = crcTable[(c ^ v) & 255] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0; };
  const chunk = (type, data) => {
    const head = Buffer.alloc(8); head.writeUInt32BE(data.length); head.write(type, 4);
    const tail = Buffer.alloc(4); tail.writeUInt32BE(crc(Buffer.concat([head.subarray(4), data])));
    return Buffer.concat([head, data, tail]);
  };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(size, 0); ihdr.writeUInt32BE(size, 4); ihdr[8] = 8; ihdr[9] = 6;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', deflateSync(raw, { level: 9 })), chunk('IEND', Buffer.alloc(0))]);
}

mkdirSync('public/app-icons', { recursive: true });
writeFileSync('public/favicon.svg', svg());
writeFileSync('public/app-icons/icon-192.png', png(192, 112));
writeFileSync('public/app-icons/icon-512.png', png(512, 112));
writeFileSync('public/app-icons/maskable-512.png', png(512, 0));
writeFileSync('public/app-icons/apple-touch-icon.png', png(180, 0));
console.log('icons written');
