export type SessionKind = 'session' | 'verification';

export interface SessionEnvelope {
  kid: string;
  /** base64url, 12 bytes decoded */
  nonce: string;
  /** base64url, includes GCM tag */
  ciphertext: string;
}

export interface SessionRecord {
  schemaVersion: 1;
  kind: SessionKind;
  /** UUID v7 */
  userId: string;
  /** ISO-8601 UTC */
  createdAt: string;
  /** ISO-8601 UTC */
  lastActivityAt: string;
  envelope: SessionEnvelope;
}

/** Server-only — MUST NOT JSON.stringify to client */
export interface SessionContext {
  sessionId: string;
  kind: SessionKind;
  userId: string;
  bearer: string;
  createdAt: Date;
  lastActivityAt: Date;
}

export interface CreateSessionInput {
  bearer: string;
  kind: SessionKind;
  userId: string;
}

export interface CreateSessionResult {
  sessionId: string;
  expiresAt: Date;
}

export type GetSessionResult =
  { context: SessionContext; clearCookie?: false } | { context: null; clearCookie: true };
