import { readFileSync, readdirSync, statSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

const frontendRoot = path.resolve(__dirname, '../../..');

function walkFiles(dir: string, predicate: (file: string) => boolean): string[] {
  const results: string[] = [];
  for (const entry of readdirSync(dir)) {
    const full = path.join(dir, entry);
    const stats = statSync(full);
    if (stats.isDirectory()) {
      results.push(...walkFiles(full, predicate));
    } else if (predicate(full)) {
      results.push(full);
    }
  }
  return results;
}

describe('foundation gates (FND-02, FND-07, FND-08)', () => {
  it('does not introduce Auth product Route Handlers beyond health and gated session probe', () => {
    const appDir = path.join(frontendRoot, 'app');
    const routes = walkFiles(appDir, (file) => file.endsWith(`${path.sep}route.ts`));
    const relative = routes.map((file) => path.relative(appDir, file)).sort();
    expect(relative).toEqual(['api/_test/session/route.ts', 'health/route.ts'].sort());

    const forbidden = ['login', 'register', 'verify', 'password', 'auth'];
    for (const segment of forbidden) {
      expect(relative.some((route) => route.includes(segment))).toBe(false);
    }
  });

  it('auth barrel exports types only (no session facade or bearer helpers)', () => {
    const authIndex = readFileSync(path.join(frontendRoot, 'modules/auth/index.ts'), 'utf8');
    expect(authIndex).toMatch(/export type \{/);
    expect(authIndex).not.toMatch(/export\s+(async\s+)?function/);
    expect(authIndex).not.toMatch(/export\s+\{[^}]*\b(createSession|getSession|encryptBearer|decryptBearer)\b/);
    expect(authIndex).not.toMatch(/from ['"].*\/(bff-session|crypto)['"]/);
  });

  it('does not list Radix as a dependency', () => {
    const packageJson = JSON.parse(
      readFileSync(path.join(frontendRoot, 'package.json'), 'utf8'),
    ) as {
      dependencies?: Record<string, string>;
      devDependencies?: Record<string, string>;
    };
    const names = [
      ...Object.keys(packageJson.dependencies ?? {}),
      ...Object.keys(packageJson.devDependencies ?? {}),
    ];
    expect(names.some((name) => /radix/i.test(name))).toBe(false);
  });

  it('keeps landing layout fluid for 360px viewports without fixed overflow width', () => {
    const pageSource = readFileSync(path.join(frontendRoot, 'app/page.tsx'), 'utf8');
    expect(pageSource).toContain('w-full');
    expect(pageSource).toContain('px-6');
    expect(pageSource).toMatch(/max-w-/);
    expect(pageSource).not.toMatch(/min-w-\[\d{3,}px\]/);
    expect(pageSource).not.toMatch(/w-\[\d{3,}px\]/);
  });
});
