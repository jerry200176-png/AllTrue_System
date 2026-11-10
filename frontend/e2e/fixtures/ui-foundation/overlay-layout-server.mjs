// Build the fixture with the existing Tailwind/Vue pipeline, then serve emitted CSS.
import { build, preview } from 'vite';
import { fileURLToPath } from 'node:url';
const configFile = fileURLToPath(new URL('../../../vite.ui-foundation.config.js', import.meta.url));
const outDir = fileURLToPath(new URL('../../../../work/overlay-layout-dist', import.meta.url));
await build({ configFile, build: { outDir, emptyOutDir: true, rollupOptions: {
  input: fileURLToPath(new URL('./overlay-layout.html', import.meta.url)),
} } });
await preview({ configFile, build: { outDir }, preview: { host: '127.0.0.1', port: 5189, strictPort: true } });
