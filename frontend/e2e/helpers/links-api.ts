import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

import { type APIRequestContext } from '@playwright/test';

import { extractLinkToken, type MailpitMessage } from './mailpit';

const ARTIFACTS_DIR = join(__dirname, '..', '.artifacts');

const JSON_HEADERS = {
  'Content-Type': 'application/json',
  Accept: 'application/json',
} as const;

export const OWNER_EMAIL = 'e2e-links-owner@fake-link.test';
export const STRANGER_EMAIL = 'e2e-links-stranger@fake-link.test';
export const E2E_PASSWORD = 'E2E-P4ssw0rd!';
export const OWNER_DESTINATION = 'https://example.com/e2e-owner-secret-dest';

export async function writeArtefact(filename: string, content: string): Promise<void> {
  await mkdir(ARTIFACTS_DIR, { recursive: true });
  await writeFile(join(ARTIFACTS_DIR, filename), content, 'utf-8');
}

function bearerFromAuthBody(body: unknown): string {
  if (!body || typeof body !== 'object') {
    throw new Error('auth response is not an object');
  }

  const record = body as Record<string, unknown>;
  const data = record.data;
  const nested =
    data && typeof data === 'object' ? (data as Record<string, unknown>).token : undefined;
  const token = typeof nested === 'string' ? nested : record.token;

  if (typeof token !== 'string' || token === '') {
    throw new Error('auth response has no token');
  }

  return token;
}

async function readJson(response: { status: () => number; json: () => Promise<unknown> }): Promise<unknown> {
  try {
    return await response.json();
  } catch {
    throw new Error(`response is not JSON (status ${response.status()})`);
  }
}

function mailpitBaseUrl(): string {
  const url = process.env.E2E_MAILPIT_URL;
  if (!url) {
    throw new Error('E2E_MAILPIT_URL is not set');
  }

  return url.replace(/\/$/, '');
}

/**
 * Poll Mailpit's message list instead of /search.
 * Hyphenated local-parts make `to:` search queries parse as exclusion filters.
 */
async function waitForInboxMessage(
  to: string,
  opts: { timeoutMs?: number } = {},
): Promise<MailpitMessage> {
  const timeoutMs = opts.timeoutMs ?? 10_000;
  const deadline = Date.now() + timeoutMs;
  const base = mailpitBaseUrl();

  while (Date.now() < deadline) {
    const list = await fetch(`${base}/api/v1/messages`);
    if (!list.ok) {
      throw new Error(`mailpit list failed with status ${list.status}`);
    }

    const payload = (await list.json()) as { messages?: { ID: string; To?: { Address: string }[] }[] };
    const match = (payload.messages ?? []).find((message) =>
      (message.To ?? []).some((recipient) => recipient.Address === to),
    );

    if (match) {
      const full = await fetch(`${base}/api/v1/message/${match.ID}`);
      if (!full.ok) {
        throw new Error(`mailpit message fetch failed with status ${full.status}`);
      }

      return (await full.json()) as MailpitMessage;
    }

    await new Promise((resolve) => setTimeout(resolve, 500));
  }

  throw new Error(`mailpit timed out waiting for ${to}`);
}

export async function registerAndActivate(
  request: APIRequestContext,
  opts: { email: string; password: string; name: string },
): Promise<{ sessionToken: string }> {
  const register = await request.post('/api/v1/auth/register', {
    data: {
      name: opts.name,
      email: opts.email,
      password: opts.password,
      password_confirmation: opts.password,
      accept_terms: true,
    },
    headers: JSON_HEADERS,
  });

  if (register.status() !== 201) {
    throw new Error(`register failed with status ${register.status()}`);
  }

  const verificationToken = bearerFromAuthBody(await readJson(register));
  const message = await waitForInboxMessage(opts.email, { timeoutMs: 15_000 });
  const emailToken = extractLinkToken(message);

  const verify = await request.post('/api/v1/auth/email/verify', {
    data: { token: emailToken },
    headers: {
      ...JSON_HEADERS,
      Authorization: `Bearer ${verificationToken}`,
    },
  });

  if (verify.status() !== 204) {
    throw new Error(`email verify failed with status ${verify.status()}`);
  }

  const login = await request.post('/api/v1/auth/login', {
    data: { email: opts.email, password: opts.password },
    headers: JSON_HEADERS,
  });

  if (login.status() !== 200) {
    throw new Error(`login failed with status ${login.status()}`);
  }

  return { sessionToken: bearerFromAuthBody(await readJson(login)) };
}

export async function createLink(
  request: APIRequestContext,
  sessionToken: string,
  payload: { destination_url: string; custom_alias: string; title: string },
): Promise<{ id: string }> {
  const response = await request.post('/api/v1/links', {
    data: payload,
    headers: {
      ...JSON_HEADERS,
      Authorization: `Bearer ${sessionToken}`,
    },
  });

  if (response.status() !== 201) {
    throw new Error(`create link failed with status ${response.status()}`);
  }

  const body = (await readJson(response)) as { data?: { id?: unknown } };
  const id = body.data?.id;

  if (typeof id !== 'string' || id === '') {
    throw new Error('create link response has no id');
  }

  return { id };
}
