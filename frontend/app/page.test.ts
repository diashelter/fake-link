import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

describe('HomePage landing shell', () => {
  it('keeps pt-BR brand copy and light-theme markers', () => {
    const pageSource = readFileSync(path.join(__dirname, 'page.tsx'), 'utf8');
    const layoutSource = readFileSync(path.join(__dirname, 'layout.tsx'), 'utf8');
    const cssSource = readFileSync(path.join(__dirname, 'globals.css'), 'utf8');

    expect(layoutSource).toContain('lang="pt-BR"');
    expect(pageSource).toContain('Fake Link');
    expect(pageSource).toContain('Plataforma de encurtamento de URLs.');
    expect(pageSource).toContain('w-full');
    expect(pageSource).toContain('px-6');
    expect(cssSource).toContain('color-scheme: light');
    expect(cssSource).toContain("@import 'tailwindcss'");
    expect(cssSource).not.toContain('.dark');
    expect(pageSource).toMatch(/className=.*bg-accent|text-muted|font-display/);
  });
});
