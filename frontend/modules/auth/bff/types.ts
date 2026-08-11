import type { NextResponse } from 'next/server';

export type HttpMethod = 'GET' | 'POST' | 'PATCH' | 'DELETE';

export type AllowlistEntry = {
  method: HttpMethod;
  bffPath: string;
  upstreamMethod: HttpMethod;
  upstreamPath: string;
  requireSession: boolean;
  requireCsrf: boolean;
};

export type BffSessionRecord = {
  sessionId: string;
  bearerPlaintext: string;
};

export type SessionLoader = (request: Request) => Promise<BffSessionRecord | null>;

export type CsrfContext =
  { mode: 'session'; sessionId: string } | { mode: 'pre-auth'; csrfSid: string };

export type GuardResult =
  { ok: true; session: BffSessionRecord | null } | { ok: false; response: NextResponse };

export type UpstreamResult = {
  response: NextResponse;
  status: number;
};
