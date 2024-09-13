/** @type {import('tailwindcss').Config} */
// DaisyUI from here: https://daisyui.com/components/
module.exports = {
  daisyui: {
    themes: [
      {
        mytheme: {

          "primary": "#002244",
          "secondary": "#c60c30",
          "accent": "#c60c30",
          "neutral": "#8B8982",
          "base-100": "#ffffff",
          "info": "#0000ff",
          "success": "#52B788",
          "warning": "#BB6B00",
          "error": "#c60c30",
        },
      },
    ],
  },
  content: [
    './**/*.{js,twig,theme, svg}',
    '../../../modules/custom/**/*.{js,twig,theme}',
    '../../../../config/**/*.{js,twig,theme}'
  ],
  theme: {
    extend: {},
  },
  plugins: [
    require('daisyui'),
  ],
}

