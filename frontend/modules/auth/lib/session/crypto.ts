import { createCipheriv, createDecipheriv, randomBytes } from 'node:crypto';

import type { BffSessionConfig } from './config';
import type { SessionEnvelope } from './types';

const ALGORITHM = 'aes-256-gcm';
const NONCE_BYTES = 12;
const AUTH_TAG_BYTES = 16;

export class SessionDecryptError extends Error {
  constructor(message = 'Session decrypt failed') {
    super(message);
    this.name = 'SessionDecryptError';
  }
}

export function encryptBearer(plaintext: string, config: BffSessionConfig): SessionEnvelope {
  const nonce = randomBytes(NONCE_BYTES);
  const cipher = createCipheriv(ALGORITHM, config.aesKey, nonce);
  const ciphertext = Buffer.concat([cipher.update(plaintext, 'utf8'), cipher.final()]);
  const tag = cipher.getAuthTag();
  const sealed = Buffer.concat([ciphertext, tag]);

  return {
    kid: config.aesKeyId,
    nonce: nonce.toString('base64url'),
    ciphertext: sealed.toString('base64url'),
  };
}

export function decryptBearer(envelope: SessionEnvelope, config: BffSessionConfig): string {
  if (envelope.kid !== config.aesKeyId) {
    throw new SessionDecryptError('Unknown AES key id');
  }

  try {
    const nonce = Buffer.from(envelope.nonce, 'base64url');
    const sealed = Buffer.from(envelope.ciphertext, 'base64url');

    if (nonce.length !== NONCE_BYTES || sealed.length <= AUTH_TAG_BYTES) {
      throw new SessionDecryptError('Malformed envelope');
    }

    const tag = sealed.subarray(sealed.length - AUTH_TAG_BYTES);
    const ciphertext = sealed.subarray(0, sealed.length - AUTH_TAG_BYTES);
    const decipher = createDecipheriv(ALGORITHM, config.aesKey, nonce);
    decipher.setAuthTag(tag);
    const plaintext = Buffer.concat([decipher.update(ciphertext), decipher.final()]);
    return plaintext.toString('utf8');
  } catch (error) {
    if (error instanceof SessionDecryptError) {
      throw error;
    }
    throw new SessionDecryptError('GCM authentication failed');
  }
}
