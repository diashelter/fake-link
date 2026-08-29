import type { SessionKind, SessionRecord } from './types';

export const ABSOLUTE_TTL_SECONDS: Record<SessionKind, number> = {
  session: 604_800,
  verification: 86_400,
};

export const IDLE_TTL_SECONDS: Record<SessionKind, number> = {
  session: 86_400,
  verification: 3_600,
};

export const TOUCH_THROTTLE_SECONDS = 900;

type TtlTable = Record<SessionKind, number>;

function createdAtMs(record: SessionRecord): number {
  return Date.parse(record.createdAt);
}

function lastActivityAtMs(record: SessionRecord): number {
  return Date.parse(record.lastActivityAt);
}

export function isAbsoluteExpired(
  record: SessionRecord,
  now: Date,
  absolute: TtlTable = ABSOLUTE_TTL_SECONDS,
): boolean {
  const limitMs = absolute[record.kind] * 1000;
  return now.getTime() > createdAtMs(record) + limitMs;
}

export function isIdleExpired(
  record: SessionRecord,
  now: Date,
  idle: TtlTable = IDLE_TTL_SECONDS,
): boolean {
  const limitMs = idle[record.kind] * 1000;
  return now.getTime() > lastActivityAtMs(record) + limitMs;
}

export function remainingAbsoluteSeconds(
  record: SessionRecord,
  now: Date,
  absolute: TtlTable = ABSOLUTE_TTL_SECONDS,
): number {
  const expiresAt = createdAtMs(record) + absolute[record.kind] * 1000;
  const remainingMs = expiresAt - now.getTime();
  if (remainingMs <= 0) {
    return 0;
  }
  return Math.ceil(remainingMs / 1000);
}

export function shouldTouch(lastActivityAt: Date, now: Date): boolean {
  const elapsedSeconds = (now.getTime() - lastActivityAt.getTime()) / 1000;
  return elapsedSeconds >= TOUCH_THROTTLE_SECONDS;
}
