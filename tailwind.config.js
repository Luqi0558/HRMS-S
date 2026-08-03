/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{vue,js}'],
  theme: {
    extend: {
      colors: {
        ink: '#12161C',
        paper: '#F3F4F6',
        line: '#E2E4E8',
        muted: '#6B7280',

        // Brand palette. `emerald` and `rose` are REDEFINED rather than renamed:
        // the app already uses those class names in ~600 places, so overriding
        // the scale shifts the whole product onto the Orbit palette without
        // touching a single component. The 500/600/700 steps are the ones the
        // UI actually leans on.
        emerald: {
          50: '#ECF8F4',
          100: '#D3EFE6',
          200: '#A9DFCE',
          300: '#6FC7AE',
          400: '#35A98C',
          500: '#12977E',
          600: '#0F8B76',   // teal DEFAULT — primary actions
          700: '#0B6B5B',
          800: '#085041',
          900: '#053A2F'
        },
        rose: {
          50: '#FDF1EE',
          100: '#FADFD8',
          200: '#F3C0B4',
          300: '#E89A88',
          400: '#DD6E5C',
          500: '#D14343',   // coral DEFAULT
          600: '#B93636',
          700: '#982C2C',
          800: '#6B2018',
          900: '#4A1B0C'
        },

        teal: {
          DEFAULT: '#0F8B76',
          bg: '#E1F5EE',
          dark: '#085041'
        },
        amber: {
          DEFAULT: '#C98A1B',
          bg: '#FAEEDA',
          dark: '#633806'
        },
        coral: {
          DEFAULT: '#D14343',
          bg: '#FAECE7',
          dark: '#4A1B0C'
        }
      },
      fontFamily: {
        display: ['"Space Grotesk"', 'sans-serif'],
        sans: ['Inter', 'sans-serif'],
        mono: ['"IBM Plex Mono"', 'monospace']
      }
    }
  },
  plugins: []
}
