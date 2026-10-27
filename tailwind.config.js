import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            // Green-tinted neutrals and a deep-green accent, replacing Tailwind's slate/gray/indigo app-wide.
            colors: {
                slate: {
                    50: '#f5faf7', 100: '#eaf3ee', 200: '#d3e6de', 300: '#5aa0a6', 400: '#8aa89f',
                    500: '#5f7f76', 600: '#46665d', 700: '#2f4d45', 800: '#1d3a33', 900: '#0f2a24', 950: '#071a16',
                },
                gray: {
                    50: '#f5faf7', 100: '#eaf3ee', 200: '#d3e6de', 300: '#5aa0a6', 400: '#8aa89f',
                    500: '#5f7f76', 600: '#46665d', 700: '#2f4d45', 800: '#1d3a33', 900: '#0f2a24', 950: '#071a16',
                },
                indigo: {
                    50: '#eaf5f1', 100: '#d3ebe2', 200: '#a9d6c6', 300: '#7dbfa6', 400: '#3f9d7f',
                    500: '#1d7a5f', 600: '#0f5642', 700: '#0b4a3a', 800: '#083a2e', 900: '#062c23', 950: '#041d17',
                },
            },
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
