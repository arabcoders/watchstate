<style>
.markdown-alert {
  padding: 0 1em;
  margin-bottom: 16px;
  color: inherit;
  border-left: 0.25em solid #444c56;
}

.markdown-alert-title {
  display: inline-flex;
  align-items: center;
  font-weight: 500;
  text-transform: uppercase;
  user-select: none;
}

.markdown-alert-note {
  border-left-color: #539bf5;
}

.markdown-alert-tip {
  border-left-color: #57ab5a;
}

.markdown-alert-important {
  border-left-color: #986ee2;
}

.markdown-alert-warning {
  border-left-color: #c69026;
}

.markdown-alert-caution {
  border-left-color: #e5534b;
}

.markdown-alert-note > .markdown-alert-title {
  color: #539bf5;
}

.markdown-alert-tip > .markdown-alert-title {
  color: #57ab5a;
}

.markdown-alert-important > .markdown-alert-title {
  color: #986ee2;
}

.markdown-alert-warning > .markdown-alert-title {
  color: #c69026;
}

.markdown-alert-caution > .markdown-alert-title {
  color: #e5534b;
}

</style>

<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12">
        <Message v-if="isLoading" message_class="has-background-info-90 has-text-dark" title="Loading"
                 icon="fas fa-spinner fa-spin" message="Loading data. Please wait..."/>

        <Message v-if="error" message_class="has-background-warning-90 has-text-dark" title="Error"
                 icon="fas fa-exclamation" :message="error"/>

        <div class="content" v-html="content" v-else/>
      </div>
    </div>
  </div>
</template>

<script setup>
import {useStorage} from '@vueuse/core'
import {marked} from 'marked'
import {baseUrl} from 'marked-base-url'
import markedAlert from 'marked-alert'

import Message from "~/components/Message.vue";

const props = defineProps({
  file: {
    type: String,
    required: true,
  },
});

const content = ref('')
const api_url = useStorage('api_url', '')
const error = ref('')
const isLoading = ref(true)

const handleClick = async e => {

  const target = e.target.closest('a')
  if (!target) {
    return
  }

  const href = target.getAttribute('href')
  if (!href) {
    return
  }

  if (!href || false === href.includes('/help/')) {
    return
  }

  e.preventDefault()
  const url = new URL(href)
  await navigateTo(url.pathname)
}

const addListeners = () => {
  removeListeners()

  document.querySelectorAll('.content a').forEach(l => {
    const href = l.getAttribute('href')
    if (!href || false === href.includes('/help/')) {
      return
    }
    l.addEventListener('click', handleClick)
  })
}

const removeListeners = () => {
  document.querySelectorAll('.content a').forEach(l => {
    const href = l.getAttribute('href')
    if (!href || false === href.includes('/help/')) {
      return
    }
    l.removeEventListener('click', handleClick)
  })
}

onMounted(async () => {
  try {
    isLoading.value = true
    const response = await fetch(`${api_url.value}${props.file}?_=${Date.now()}`)
    if (!response.ok) {
      const err = await parse_api_response(response)
      console.log(err)
      error.value = err.error.message
      return
    }

    const text = await response.text()

    marked.use(baseUrl(api_url.value))
    marked.use(markedAlert());
    marked.use({
      gfm: true,
      hooks: {
        postprocess: (text) => {
          //   // -- replace GitHub [! with icon
          //   text = text.replace(/\[!IMPORTANT]/g, `
          // <span class="is-block title mb-2 is-5">
          //     <span class="icon-text">
          //         <span class="icon"><i class="fas fa-exclamation-triangle has-text-danger"></i></span>
          //         <span>IMPORTANT</span>
          //     </span>
          // </span>`)
          //
          //   text = text.replace(/\[!NOTE]/g, `
          // <span class="is-block title is-5">
          //     <span class="icon-text">
          //         <span class="icon"><i class="fas fa-info has-text-info-50"></i></span>
          //         <span>NOTE</span>
          //     </span>
          // </span>`)

          text = text.replace(
              /<!--\s*?i:([\w.-]+)\s*?-->/gi,
              (_, list) => `<span class="icon"><i class="fas ${list.split('.').map(n => n.trim()).join(' ')}"></i></span>`
          );

          return text
        }
      },
      walkTokens: token => {
        if ('link' !== token.type) {
          return;
        }

        if (true === token.href.startsWith('#')) {
          return;
        }
        const urls = ['/FAQ.md', '/README.md', '/NEWS.md'];
        const list = ['/guides/', ...urls];
        if (false === list.some(l => token.href.includes(l))) {
          return;
        }

        if (urls.some(l => token.href.includes(l))) {
          const url = new URL(token.href);
          url.pathname = `/guides${url.pathname}`;
          token.href = url.pathname;
        }

        token.href = token.href.replace('/guides/', '/help/').replace('.md', '');
      },
    });

    content.value = String(marked.parse(text))
  } catch (e) {
    console.error(e)
    error.value = e.message
  } finally {
    isLoading.value = false
  }
});

onUnmounted(() => removeListeners());
onUpdated(() => addListeners())
</script>
