import { hmac } from '@noble/hashes/hmac.js';
import { sha256 } from '@noble/hashes/sha2.js';

const textToBuffer = (value: string): ArrayBuffer => {
  const bytes = new TextEncoder().encode(value);
  return bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength);
};

const textToBytes = (value: string): Uint8Array => new TextEncoder().encode(value);

const toHex = (buffer: ArrayBuffer): string =>
  Array.from(new Uint8Array(buffer))
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('');

const bytesToHex = (bytes: Uint8Array): string =>
  Array.from(bytes)
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('');

const signBody = async (body: string, secret: string): Promise<string> => {
  const subtle = globalThis.crypto?.subtle;

  if (!subtle) {
    const signature = hmac(sha256, textToBytes(secret), textToBytes(body));
    return `sha256=${bytesToHex(signature)}`;
  }

  const key = await subtle.importKey(
    'raw',
    textToBuffer(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );

  const signature = await subtle.sign('HMAC', key, textToBuffer(body));

  return `sha256=${toHex(signature)}`;
};

export { signBody };
