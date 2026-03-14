import { defineConfig } from 'vite';

export default defineConfig({
  build: {
    outDir: './Resources/Public/JavaScript',
    emptyOutDir: false,
    rollupOptions: {
      input:'./Resources/Private/JavaScript/find.js',
      output: {
             entryFileNames: 'find.js',       // output is bundle.js
             assetFileNames: '[name][extname]'  // avoid hashing for static extension assets
           },
    }
  }
});
