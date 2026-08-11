import type { SessionRecord } from '../types';

type StoredEntry = {
  record: SessionRecord;
  exSeconds: number;
};

/**
 * In-memory SessionStore double for facade unit tests (no Redis).
 */
export class FakeSessionStore {
  private readonly entries = new Map<string, StoredEntry>();

  async get(key: string): Promise<SessionRecord | null> {
    const entry = this.entries.get(key);
    return entry ? structuredClone(entry.record) : null;
  }

  async set(key: string, record: SessionRecord, exSeconds: number): Promise<void> {
    this.entries.set(key, { record: structuredClone(record), exSeconds });
  }

  async del(key: string): Promise<void> {
    this.entries.delete(key);
  }

  /** Test helper: inspect TTL written with the last SET. */
  getExSeconds(key: string): number | null {
    return this.entries.get(key)?.exSeconds ?? null;
  }
}
