/**
 * Link query E2E — list + detail over the real Docker stack.
 *
 * There is no Links UI/BFF yet. Auth happens through APIRequestContext
 * in the Node runner. The browser is used only to prove tokens never
 * appear in page HTML or web storage.
 */
import { expect, test } from '@playwright/test';

import {
  createLink,
  E2E_PASSWORD,
  OWNER_DESTINATION,
  OWNER_EMAIL,
  registerAndActivate,
  STRANGER_EMAIL,
  writeArtefact,
} from './helpers/links-api';
import { clearMailbox } from './helpers/mailpit';
import { collectClientState } from './helpers/leak-scan';
import { assertAbsent } from './helpers/sentinel';

const SUMMARY_FORBIDDEN_KEYS = ['destination_url', 'etag', 'version', 'blocked_at', 'user_id'];

test.describe.configure({ mode: 'serial' });

let ownerToken = '';
let strangerToken = '';
let ownerLinkIds: string[] = [];

test('authenticated owner paginates list and opens own detail', async ({ request }) => {
  await clearMailbox();

  const owner = await registerAndActivate(request, {
    email: OWNER_EMAIL,
    password: E2E_PASSWORD,
    name: 'Links Owner',
  });
  ownerToken = owner.sessionToken;

  const stranger = await registerAndActivate(request, {
    email: STRANGER_EMAIL,
    password: E2E_PASSWORD,
    name: 'Links Stranger',
  });
  strangerToken = stranger.sessionToken;

  expect(ownerToken.length).toBeGreaterThan(10);
  expect(strangerToken.length).toBeGreaterThan(10);
  expect(ownerToken).not.toEqual(strangerToken);

  await writeArtefact('sentinel.txt', ownerToken);

  const created = [];
  for (const alias of ['e2e-lnq-a', 'e2e-lnq-b', 'e2e-lnq-c'] as const) {
    created.push(
      await createLink(request, ownerToken, {
        destination_url: OWNER_DESTINATION,
        custom_alias: alias,
        title: `Owner ${alias}`,
      }),
    );
  }
  ownerLinkIds = created.map((link) => link.id);
  expect(ownerLinkIds).toHaveLength(3);

  const seenIds: string[] = [];
  let cursor: string | null = null;
  let pages = 0;

  do {
    const query = new URLSearchParams({ per_page: '1' });
    if (cursor !== null) {
      query.set('cursor', cursor);
    }

    const list = await request.get(`/api/v1/links?${query.toString()}`, {
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${ownerToken}`,
      },
    });

    expect(list.status()).toBe(200);
    expect(list.headers()['cache-control'] ?? '').toContain('private');
    expect(list.headers()['cache-control'] ?? '').toContain('no-store');
    expect(list.headers()['x-request-id']).toBeTruthy();

    const body = (await list.json()) as {
      data?: Record<string, unknown>[];
      meta?: { next_cursor?: string | null; per_page?: number };
    };

    expect(Array.isArray(body.data)).toBe(true);
    expect(body.data).toHaveLength(1);
    expect(body.meta?.per_page).toBe(1);
    expect(Object.keys(body.meta ?? {}).sort()).toEqual(['next_cursor', 'per_page']);

    const summary = body.data![0];
    expect(typeof summary.id).toBe('string');
    for (const key of SUMMARY_FORBIDDEN_KEYS) {
      expect(summary).not.toHaveProperty(key);
    }
    expect(JSON.stringify(summary)).not.toContain(OWNER_DESTINATION);

    seenIds.push(summary.id as string);
    cursor = body.meta?.next_cursor ?? null;
    pages += 1;
  } while (cursor !== null && pages < 5);

  expect(pages).toBe(3);
  expect(cursor).toBeNull();
  expect(new Set(seenIds).size).toBe(3);
  expect(seenIds.toSorted()).toEqual(ownerLinkIds.toSorted());

  const detail = await request.get(`/api/v1/links/${ownerLinkIds[0]}`, {
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${ownerToken}`,
    },
  });

  expect(detail.status()).toBe(200);
  expect(detail.headers()['cache-control'] ?? '').toContain('private');
  expect(detail.headers()['cache-control'] ?? '').toContain('no-store');
  expect(detail.headers()['x-request-id']).toBeTruthy();
  expect(detail.headers()['etag'] ?? '').toMatch(/^"[0-9a-f]{64}"$/);

  const detailBody = (await detail.json()) as { data?: Record<string, unknown> };
  expect(detailBody.data?.id).toBe(ownerLinkIds[0]);
  expect(detailBody.data?.destination_url).toBe(OWNER_DESTINATION);
  expect(detailBody.data).not.toHaveProperty('version');
  expect(detailBody.data).not.toHaveProperty('blocked_at');
  expect(detailBody.data).not.toHaveProperty('user_id');
});

test('cross-account access does not leak link content', async ({ request }) => {
  expect(ownerLinkIds.length).toBeGreaterThan(0);

  const foreign = await request.get(`/api/v1/links/${ownerLinkIds[0]}`, {
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${strangerToken}`,
    },
  });

  expect(foreign.status()).toBe(404);
  const body = (await foreign.json()) as Record<string, unknown>;
  expect(body.code).toBe('RESOURCE_NOT_FOUND');
  expect(body).not.toHaveProperty('data');
  expect(JSON.stringify(body)).not.toContain(OWNER_DESTINATION);
  expect(JSON.stringify(body)).not.toContain('e2e-lnq-a');
  expect(JSON.stringify(body)).not.toContain(ownerLinkIds[0]);

  const strangerList = await request.get('/api/v1/links?per_page=20', {
    headers: {
      Accept: 'application/json',
      Authorization: `Bearer ${strangerToken}`,
    },
  });

  expect(strangerList.status()).toBe(200);
  const listBody = (await strangerList.json()) as {
    data?: Record<string, unknown>[];
    meta?: { next_cursor?: string | null };
  };
  expect(listBody.data).toEqual([]);
  expect(listBody.meta?.next_cursor).toBeNull();
  expect(JSON.stringify(listBody)).not.toContain(OWNER_DESTINATION);
  for (const id of ownerLinkIds) {
    expect(JSON.stringify(listBody)).not.toContain(id);
  }
});

test('session tokens stay out of page HTML and web storage', async ({ page }) => {
  expect(ownerToken.length).toBeGreaterThan(10);

  await page.goto('/');
  const state = await collectClientState(page);

  assertAbsent(ownerToken, [
    state.html,
    state.rscPayload,
    ...state.cookies,
    ...state.localStorage,
    ...state.sessionStorage,
    ...state.indexedDbEntries,
  ]);
  assertAbsent(strangerToken, [
    state.html,
    state.rscPayload,
    ...state.cookies,
    ...state.localStorage,
    ...state.sessionStorage,
    ...state.indexedDbEntries,
  ]);
  expect(state.html).not.toContain(OWNER_DESTINATION);
});
