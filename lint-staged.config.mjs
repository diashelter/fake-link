import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));
const frontendBin = path.join(root, 'frontend/node_modules/.bin');

const toFrontendRelative = (files) =>
  files.map((file) => path.relative('frontend', file)).join(' ');

const eslintBin = path.join(frontendBin, 'eslint');
const prettierBin = path.join(frontendBin, 'prettier');

export default {
  'frontend/**/*.{ts,tsx,js,jsx,mjs,cjs}': (files) => {
    const relative = toFrontendRelative(files);
    return [
      `cd frontend && ${eslintBin} --fix -- ${relative}`,
      `cd frontend && ${prettierBin} --write -- ${relative}`,
    ];
  },
  'frontend/**/*.{css,json,md}': (files) => {
    const relative = toFrontendRelative(files);
    return [`cd frontend && ${prettierBin} --write -- ${relative}`];
  },
};
