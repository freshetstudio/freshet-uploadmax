// Top-crop a non-interlaced PNG to the first N rows, full width. Dependency-free.
// Usage: node pngcrop.js <in.png> <out.png> <targetHeight>
const fs = require('fs');
const zlib = require('zlib');

const [inPath, outPath, targetHStr] = process.argv.slice(2);
const targetH = parseInt(targetHStr, 10);
const buf = fs.readFileSync(inPath);

const SIG = Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]);
if (!buf.subarray(0, 8).equals(SIG)) throw new Error('not a PNG');

// CRC32 (Node >=20 exposes zlib.crc32; fall back to a table impl).
const crc32 = zlib.crc32
  ? (b) => zlib.crc32(b) >>> 0
  : (() => {
      const t = new Uint32Array(256);
      for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        t[n] = c >>> 0;
      }
      return (b) => {
        let c = 0xffffffff;
        for (let i = 0; i < b.length; i++) c = t[(c ^ b[i]) & 0xff] ^ (c >>> 8);
        return (c ^ 0xffffffff) >>> 0;
      };
    })();

// Parse chunks.
let off = 8;
let ihdr = null;
const idat = [];
while (off < buf.length) {
  const len = buf.readUInt32BE(off);
  const type = buf.toString('ascii', off + 4, off + 8);
  const data = buf.subarray(off + 8, off + 8 + len);
  if (type === 'IHDR') ihdr = Buffer.from(data);
  else if (type === 'IDAT') idat.push(data);
  off += 12 + len;
  if (type === 'IEND') break;
}
if (!ihdr) throw new Error('no IHDR');

const width = ihdr.readUInt32BE(0);
const height = ihdr.readUInt32BE(4);
const bitDepth = ihdr[8];
const colorType = ihdr[9];
const interlace = ihdr[12];
if (interlace !== 0) throw new Error('interlaced PNG unsupported');
if (bitDepth !== 8) throw new Error('expected 8-bit, got ' + bitDepth);

const channels = { 0: 1, 2: 3, 3: 1, 4: 2, 6: 4 }[colorType];
if (!channels) throw new Error('unsupported color type ' + colorType);
const bytesPerRow = 1 + width * channels; // 1 filter byte per scanline

if (targetH > height) throw new Error(`targetH ${targetH} > height ${height}`);

const raw = zlib.inflateSync(Buffer.concat(idat));
const croppedRaw = raw.subarray(0, targetH * bytesPerRow);
const newIdat = zlib.deflateSync(croppedRaw, { level: 9 });

// Rebuild IHDR with new height.
const newIhdr = Buffer.from(ihdr);
newIhdr.writeUInt32BE(targetH, 4);

function chunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length, 0);
  const typeBuf = Buffer.from(type, 'ascii');
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(Buffer.concat([typeBuf, data])), 0);
  return Buffer.concat([len, typeBuf, data, crc]);
}

const out = Buffer.concat([
  SIG,
  chunk('IHDR', newIhdr),
  chunk('IDAT', newIdat),
  chunk('IEND', Buffer.alloc(0)),
]);
fs.writeFileSync(outPath, out);
console.log(`${outPath}: ${width}x${targetH} (from ${width}x${height})`);
