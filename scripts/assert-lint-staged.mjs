#!/usr/bin/env node
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const root = process.cwd();
const configPath = path.join(root, 'lint-staged.config.mjs');
const configModule = await import(pathToFileURL(configPath).href);
const config = configModule.default;
const globs = Object.keys(config);

if (!globs.every((glob) => glob.startsWith('frontend/'))) {
  console.error('lint-staged globs must be limited to frontend/**');
  process.exit(1);
}

const tsGlob = 'frontend/**/*.{ts,tsx,js,jsx,mjs,cjs}';
const factory = config[tsGlob];
if (typeof factory !== 'function') {
  console.error(`Missing lint-staged factory for ${tsGlob}`);
  process.exit(1);
}

const commands = factory([path.join(root, 'frontend/modules/shared/schemas/email.ts')]);
const hasEslintFix = commands.some(
  (command) => command.includes('eslint') && command.includes('--fix'),
);
const hasPrettierWrite = commands.some(
  (command) => command.includes('prettier') && command.includes('--write'),
);

if (!hasEslintFix || !hasPrettierWrite) {
  console.error('lint-staged must run eslint --fix and prettier --write for frontend TS/JS');
  process.exit(1);
}

console.log('lint-staged contract OK (frontend-only autofix)');
