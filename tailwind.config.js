import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/*
 * Token desain mengikuti Frappe/ERPNext v15 ("espresso"):
 * abu-abu netral, teks 13px, kontrol 28px, sudut 8px, bayangan tipis.
 *
 * Palet `ink` tetap dipakai di seluruh view, jadi cukup nilainya yang
 * diganti ke skala abu-abu Frappe. Hijau, oranye, merah, dan biru juga
 * ditimpa supaya lencana status memakai pasangan warna yang sama dengan
 * indikator ERPNext.
 */
const gray = {
    50: '#f8f8f8',
    100: '#ededed',
    200: '#e2e2e2',
    300: '#c7c7c7',
    400: '#999999',
    500: '#7c7c7c',
    600: '#525252',
    700: '#383838',
    800: '#2b2b2b',
    900: '#1f1f1f',
    950: '#171717',
};

const green = {
    50: '#f3fcf5',
    100: '#e4f5e9',
    200: '#daf0e1',
    300: '#cae5d4',
    400: '#b6dec5',
    500: '#59ba8b',
    600: '#30a66d',
    700: '#278f5e',
    800: '#16794c',
    900: '#173b2c',
    950: '#0f2a1f',
};

const red = {
    50: '#fff7f7',
    100: '#fff0f0',
    200: '#fcd7d7',
    300: '#f9c6c6',
    400: '#eb9091',
    500: '#e03636',
    600: '#cc2929',
    700: '#b52a2a',
    800: '#941f1f',
    900: '#6b1515',
    950: '#4a0f0f',
};

const orange = {
    50: '#fff9f5',
    100: '#fff1e7',
    200: '#fce6d5',
    300: '#f7d6bd',
    400: '#f0b58b',
    500: '#e86c13',
    600: '#d45a08',
    700: '#bd3e0c',
    800: '#9e3513',
    900: '#6b2711',
    950: '#4a1b0c',
};

const yellow = {
    50: '#fffcef',
    100: '#fff7d3',
    200: '#f7e9a8',
    300: '#f5e171',
    400: '#f2d14b',
    500: '#edba13',
    600: '#d1930d',
    700: '#ab6e05',
    800: '#8c5600',
    900: '#733f12',
    950: '#4f2b0c',
};

const blue = {
    50: '#f7fbfd',
    100: '#edf6fd',
    200: '#e3f1fd',
    300: '#c9e7fc',
    400: '#70b6f0',
    500: '#0289f7',
    600: '#007be0',
    700: '#0070cc',
    800: '#005ca3',
    900: '#004880',
    950: '#00325a',
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
            fontSize: {
                xs: ['12px', '16px'],
                sm: ['13px', '20px'],
                base: ['14px', '22px'],
                lg: ['16px', '24px'],
                xl: ['18px', '26px'],
                '2xl': ['20px', '28px'],
                '3xl': ['24px', '30px'],
                '4xl': ['28px', '34px'],
            },
            colors: {
                ink: gray,
                gray,
                emerald: green,
                green,
                red,
                rose: red,
                amber: orange,
                orange,
                yellow,
                blue,
                sky: blue,
                control: '#f3f3f3',
            },
            borderRadius: {
                sm: '4px',
                DEFAULT: '6px',
                md: '6px',
                lg: '8px',
                xl: '8px',
                '2xl': '10px',
                '3xl': '12px',
            },
            boxShadow: {
                xs: 'rgba(0, 0, 0, 0.05) 0px 0.5px 0px 0px, rgba(0, 0, 0, 0.08) 0px 0px 0px 1px, rgba(0, 0, 0, 0.05) 0px 2px 4px 0px',
                sm: '0px 1px 2px rgba(0, 0, 0, 0.1)',
                soft: 'none',
                card: 'none',
                md: '0px 0px 1px rgba(0, 0, 0, 0.12), 0px 0.5px 2px rgba(0, 0, 0, 0.15), 0px 2px 3px rgba(0, 0, 0, 0.16)',
                lift: '0px 0px 1px rgba(0, 0, 0, 0.2), 0px 1px 3px rgba(0, 0, 0, 0.05), 0px 10px 24px -3px rgba(0, 0, 0, 0.1)',
                '2xl': '0px 0px 1px rgba(0, 0, 0, 0.2), 0px 1px 3px rgba(0, 0, 0, 0.05), 0px 10px 24px -3px rgba(0, 0, 0, 0.1)',
            },
            spacing: {
                navbar: '48px',
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

    plugins: [forms],
};
