import path from 'node:path';

const toFrontendRelative = (files) =>
  files.map((file) => path.relative('frontend', file)).join(' ');

export default {
  'frontend/**/*.{ts,tsx,js,jsx,mjs,cjs}': (files) => {
    const relative = toFrontendRelative(files);
    return [
      `pnpm --dir frontend exec eslint --fix -- ${relative}`,
      `pnpm --dir frontend exec prettier --write -- ${relative}`,
    ];
  },
  'frontend/**/*.{css,json,md}': (files) => {
    const relative = toFrontendRelative(files);
    return [`pnpm --dir frontend exec prettier --write -- ${relative}`];
  },
};
