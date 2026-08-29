import { readdir, readFile, stat } from 'node:fs/promises';
import { join } from 'node:path';
import { type Page } from '@playwright/test';

export interface ClientState {
  cookies: string[];
  localStorage: string[];
  sessionStorage: string[];
  indexedDbNames: string[];
  html: string;
  rscPayload: string;
}

/** Collect all observable client-side state for leak scanning. */
export async function collectClientState(page: Page): Promise<ClientState> {
  const cookies = await page.context().cookies();
  const cookieStrings = cookies.map((c) => `${c.name}=${c.value}`);

  const { localStorageItems, sessionStorageItems, indexedDbNames } = await page.evaluate(() => {
    const ls: string[] = [];
    for (let i = 0; i < window.localStorage.length; i++) {
      const key = window.localStorage.key(i);
      if (key !== null) {
        ls.push(`${key}=${window.localStorage.getItem(key) ?? ''}`);
      }
    }

    const ss: string[] = [];
    for (let i = 0; i < window.sessionStorage.length; i++) {
      const key = window.sessionStorage.key(i);
      if (key !== null) {
        ss.push(`${key}=${window.sessionStorage.getItem(key) ?? ''}`);
      }
    }

    // IndexedDB database names (async, best-effort)
    const idbNames: string[] = [];

    return { localStorageItems: ls, sessionStorageItems: ss, indexedDbNames: idbNames };
  });

  const html = await page.content();

  // RSC payload is often embedded in <script> tags with type application/json or as __NEXT_DATA__
  const rscPayload = await page.evaluate(() => {
    const scripts = Array.from(document.querySelectorAll('script'));
    return scripts
      .filter(
        (s) =>
          s.type === 'application/json' ||
          s.id === '__NEXT_DATA__' ||
          (s.textContent ?? '').startsWith('self.__next_f'),
      )
      .map((s) => s.textContent ?? '')
      .join('\n');
  });

  return {
    cookies: cookieStrings,
    localStorage: localStorageItems,
    sessionStorage: sessionStorageItems,
    indexedDbNames,
    html,
    rscPayload,
  };
}

interface ScanHit {
  file: string;
  needle: string;
  line: number;
}

/** Recursively read text files from dir and return lines containing any of the needles. */
export async function scanArtifacts(dir: string, needles: string[]): Promise<ScanHit[]> {
  const hits: ScanHit[] = [];
  if (needles.length === 0) return hits;

  async function walk(current: string): Promise<void> {
    let entries: string[];
    try {
      entries = await readdir(current);
    } catch {
      return;
    }

    for (const entry of entries) {
      const full = join(current, entry);
      let info;
      try {
        info = await stat(full);
      } catch {
        continue;
      }

      if (info.isDirectory()) {
        await walk(full);
      } else {
        let content: string;
        try {
          content = await readFile(full, 'utf-8');
        } catch {
          continue; // skip binary or unreadable files
        }

        const lines = content.split('\n');
        lines.forEach((lineText, idx) => {
          for (const needle of needles) {
            if (lineText.includes(needle)) {
              hits.push({ file: full, needle, line: idx + 1 });
            }
          }
        });
      }
    }
  }

  await walk(dir);
  return hits;
}
