import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import plugin from 'tailwindcss/plugin';

/*
 * Token desain mengikuti panel admin wmsxshopee: abu-abu netral tanpa rona,
 * teks 14px, kontrol 32px, sudut 8px, kartu bergaris tepi tipis tanpa
 * bayangan. Warna status memakai palet bawaan Tailwind (emerald, rose,
 * amber, sky) seperti wmsxshopee.
 *
 * Palet `ink` tetap dipakai di seluruh view, jadi cukup nilainya yang
 * diganti. `brand` diambil dari emas logo CheeryChic dan dipakai hemat:
 * garis tab aktif, bar progres, dan pilihan filter yang aktif.
 */
const gray = {
    50: '#f8f8f8',
    100: '#efefef',
    200: '#e2e2e2',
    300: '#c7c7c7',
    400: '#999999',
    500: '#7c7c7c',
    600: '#636363',
    700: '#525252',
    800: '#383838',
    900: '#262626',
    950: '#171717',
};

const brand = {
    50: '#fdf9ec',
    100: '#faf0cc',
    200: '#f4e09a',
    300: '#ebcb63',
    400: '#e0b93f',
    500: '#d6ae32',
    600: '#b98f1f',
    700: '#946d1b',
    800: '#78571c',
    900: '#65481c',
    950: '#3a270b',
};

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/View/**/*.php',
        './app/Support/**/*.php',
        './app/Models/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter Variable', 'InterVariable', 'Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                ink: gray,
                gray,
                brand,
                control: '#efefef',
            },
            borderRadius: {
                xl: '12px',
                '2xl': '12px',
                '3xl': '12px',
            },
            boxShadow: {
                soft: 'none',
                card: 'none',
                lift: '0 10px 24px -6px rgb(0 0 0 / 0.12), 0 2px 6px -2px rgb(0 0 0 / 0.06)',
            },
            spacing: {
                navbar: '48px',
                sidebar: '240px',
            },
            keyframes: {
                'fade-in': {
                    '0%': { opacity: '0' },
                    '100%': { opacity: '1' },
                },
                'slide-in': {
                    '0%': { opacity: '0', transform: 'translateX(8px)' },
                    '100%': { opacity: '1', transform: 'translateX(0)' },
                },
            },
            animation: {
                'fade-in': 'fade-in .15s ease-out both',
                'slide-in': 'slide-in .2s ease-out both',
            },
        },
    },

    plugins: [
        forms,
        // Sidebar diciutkan jadi ikon saja: kelas `.sidebar-collapsed` di <html>, dipakai bersama lg:.
        plugin(({ addVariant }) => addVariant('collapsed', ':where(.sidebar-collapsed) &')),
    ],
};
