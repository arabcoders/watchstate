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

<script setup lang="ts">
import {ref, onMounted, onBeforeUnmount, onUpdated} from 'vue'
import {navigateTo} from '#app'
import {marked} from 'marked'
import type {MarkedExtension, Tokens} from 'marked'
import {baseUrl} from 'marked-base-url'
import markedAlert from 'marked-alert'
import {gfmHeadingId} from 'marked-gfm-heading-id'
import Message from '~/components/Message.vue'
import {parse_api_response} from '~/utils'
import type {GenericError} from '~/types'

const props = defineProps<{
  /** Path to the markdown file to load */
  file: string
}>()

const content = ref<string>('')
const error = ref<string>('')
const isLoading = ref<boolean>(true)

const handleClick = (e: MouseEvent): void => {
  const target = (e.target as HTMLElement)?.closest('a') as HTMLAnchorElement | null
  if (!target) {
    return
  }
  const href = target.getAttribute('href')
  if (!href) {
    return
  }
  if (!href.includes('/help/')) {
    return
  }
  e.preventDefault()
  const url = new URL(href, window.location.origin)
  navigateTo(url.pathname)
}

const addListeners = (): void => {
  removeListeners()
  document.querySelectorAll('.content a').forEach((l: Element): void => {
    const href = l.getAttribute('href')
    if (!href || !href.includes('/help/')) {
      return
    }

    (l as HTMLElement).addEventListener('click', handleClick)
  })
}

const removeListeners = (): void => {
  document.querySelectorAll('.content a').forEach((l: Element): void => {
    const href = l.getAttribute('href')
    if (!href || !href.includes('/help/')) {
      return
    }

    (l as HTMLElement).removeEventListener('click', handleClick)
  })
}

onMounted(async () => {
  try {
    isLoading.value = true
    const response = await fetch(`${props.file}?_=${Date.now()}`)
    if (!response.ok) {
      const err = await parse_api_response<GenericError>(response)
      console.log(err)
      error.value = err.error.message
      return
    }
    const text = await response.text()
    marked.use(gfmHeadingId())
    marked.use(baseUrl(window.origin))
    marked.use(markedAlert())
    const options = {
      gfm: true,
      hooks: {
        preprocess: (value: string) => value.replace(
            /<!--\s*?i:([\w.-]+)\s*?-->/gi,
            (_: string, list: string) => `<span class="icon"><i class="fas ${list.split('.').map((n: string) => n.trim()).join(' ')}"></i></span>`
        )
      },
      walkTokens: (token: Tokens.Generic) => {
        if (token.type !== 'link') {
          return
        }
        const linkToken = token as Tokens.Link
        if (linkToken.href.startsWith('#')) {
          return
        }
        const urls = ['FAQ.md', 'README.md', 'NEWS.md']
        const list = ['guides/', ...urls]
        if (!list.some(l => linkToken.href.includes(l))) {
          return
        }
        if (urls.some(l => linkToken.href.includes(l))) {
          if (!linkToken.href.startsWith('/')) {
            linkToken.href = '/' + linkToken.href
          }
          const url = new URL(window.origin + linkToken.href)
          url.pathname = `/guides${url.pathname}`
          linkToken.href = url.toString()
        }
        linkToken.href = linkToken.href.replace('/guides/', '/help/').replace('.md', '')
      },
    } as MarkedExtension
    marked.use(options)
    content.value = String(marked.parse(text))
  } catch (err) {
    console.error(err)
    error.value = err instanceof Error ? err.message : 'Unexpected error'
  } finally {
    isLoading.value = false
  }
})

onUpdated(() => addListeners())
onBeforeUnmount(() => removeListeners())
</script>
