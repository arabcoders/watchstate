// eslint.config.js
// @ts-check
import withNuxt from './.nuxt/eslint.config.mjs'
import vueParser from 'vue-eslint-parser'
import tsParser from '@typescript-eslint/parser'

export default withNuxt(
    {
        languageOptions: {
            parser: vueParser,
            parserOptions: {
                parser: tsParser,
                ecmaVersion: 'latest',
                sourceType: 'module',
                extraFileExtensions: ['.vue']
            }
        },
        rules: {
            'vue/valid-template-root': 'off',
            'vue/no-multiple-template-root': 'off',
            'vue/multi-word-component-names': 'off',

            'vue/no-undef-components': 'off',
            'vue/no-undef-properties': 'off',
            'vue/script-setup-uses-vars': 'off',


            'vue/mustache-interpolation-spacing': ['warn', 'always'],
            'vue/v-bind-style': ['warn', 'shorthand'],
            'vue/v-on-style': ['warn', 'shorthand'],
            'vue/attributes-order': 'off',
            'vue/html-self-closing': 'off',
            'vue/first-attribute-linebreak': 'off',
            'vue/attribute-hyphenation': 'off',
            'vue/v-on-event-hyphenation': 'off',
            'vue/block-order': 'off',
            'vue/prop-name-casing': 'off',
            'vue/no-v-html': 'off',
            'vue/html-quotes': 'off',

            'no-empty': ['error', { allowEmptyCatch: true }],
            'no-console': 'off',
            'no-debugger': 'warn',
            'no-alert': 'warn',

            'no-undef': 'off',
            'no-unused-vars': 'off',
            'vue/no-unused-vars': 'off',
            "@typescript-eslint/no-explicit-any": 'off',
        }
    },
    {
        files: ['**/*.vue'],
        rules: {}
    })
