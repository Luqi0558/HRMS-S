import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'

export default defineConfig({
  plugins: [vue()],
  test: {
    // happy-dom rather than jsdom: the views only need a DOM to mount into,
    // and it starts noticeably faster across 21 files.
    environment: 'happy-dom',
    include: ['tests/**/*.test.js'],
    // Views mount, fire their onMounted fetches and settle; nothing here waits
    // on real IO, so anything slower than this is a hang.
    testTimeout: 10000
  }
})
