<template>
  <div class="space-y-4">
    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      title="Loading guide"
      icon="i-lucide-loader-circle"
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="error"
      color="error"
      variant="soft"
      title="Unable to load guide"
      icon="i-lucide-triangle-alert"
      :description="error"
    />

    <article v-else ref="contentRoot" class="ws-markdown" v-html="content" />
  </div>
</template>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { navigateTo } from '#app';
import { UAlert } from '#components';
import { marked } from 'marked';
import type { MarkedExtension, Tokens } from 'marked';
import { baseUrl } from 'marked-base-url';
import markedAlert from 'marked-alert';
import { gfmHeadingId } from 'marked-gfm-heading-id';
import { parse_api_response } from '~/utils';
import { GUIDE_ICONS_UPDATED_EVENT, renderGuideIcon } from '~/utils/guideIcons';
import type { GenericError } from '~/types';

const props = defineProps<{
  /** Path to the markdown file to load */
  file: string;
}>();

const content = ref<string>('');
const error = ref<string>('');
const isLoading = ref<boolean>(true);
const contentRoot = ref<HTMLElement | null>(null);

const toStaticAssetUrl = (href: string, prefix: string): string => {
  const url = new URL(href, window.location.origin);
  const normalizedPrefix = `/${prefix}`;

  if (!url.pathname.startsWith(normalizedPrefix)) {
    url.pathname = `${normalizedPrefix}/${url.pathname.replace(/^\/+/, '')}`;
  }

  url.pathname = `/v1/api/system/static${url.pathname}`;

  return url.toString();
};

const rewriteGuideHref = (href: string): string => {
  const normalized = href.trim();

  if (normalized.startsWith('/screenshots/') || normalized.startsWith('screenshots/')) {
    return toStaticAssetUrl(normalized, 'screenshots');
  }

  if (normalized.startsWith('/guides/') || normalized.startsWith('guides/')) {
    return normalized.replace('/guides/', '/help/').replace('guides/', '/help/').replace('.md', '');
  }

  const guideFiles = ['API.md', 'FAQ.md', 'README.md', 'NEWS.md'];
  if (!guideFiles.some((entry) => normalized.includes(entry))) {
    return href;
  }

  const path = normalized.startsWith('/') ? normalized : `/${normalized}`;
  const url = new URL(window.origin + path);
  url.pathname = `/guides${url.pathname}`;

  return url.toString().replace('/guides/', '/help/').replace('.md', '');
};

const handleClick = (e: MouseEvent): void => {
  const target = (e.target as HTMLElement)?.closest('a') as HTMLAnchorElement | null;
  if (!target) {
    return;
  }

  const href = target.getAttribute('href');
  if (!href) {
    return;
  }

  if (!href.includes('/help/')) {
    return;
  }

  e.preventDefault();
  const url = new URL(href, window.location.origin);
  navigateTo(url.pathname);
};

const addListeners = (): void => {
  removeListeners();

  contentRoot.value?.querySelectorAll('a').forEach((link: Element): void => {
    const href = link.getAttribute('href');
    if (!href || !href.includes('/help/')) {
      return;
    }

    (link as HTMLElement).addEventListener('click', handleClick);
  });
};

const removeListeners = (): void => {
  contentRoot.value?.querySelectorAll('a').forEach((link: Element): void => {
    const href = link.getAttribute('href');
    if (!href || !href.includes('/help/')) {
      return;
    }

    (link as HTMLElement).removeEventListener('click', handleClick);
  });
};

const loadContent = async (): Promise<void> => {
  removeListeners();

  try {
    isLoading.value = true;
    content.value = '';
    error.value = '';

    const response = await fetch(`${props.file}?_=${Date.now()}`);
    if (!response.ok) {
      const err = await parse_api_response<GenericError>(response);
      error.value = err.error.message;
      return;
    }

    const text = await response.text();
    marked.use(gfmHeadingId());
    marked.use(baseUrl(window.origin));
    marked.use(markedAlert());

    const options = {
      gfm: true,
      hooks: {
        preprocess: (value: string) =>
          value.replace(/<!--\s*?i:([\w.-]+)\s*?-->/gi, (_: string, icon: string) =>
            renderGuideIcon(icon),
          ),
      },
      walkTokens: (token: Tokens.Generic) => {
        if (token.type === 'image') {
          const imageToken = token as Tokens.Image;
          imageToken.href = rewriteGuideHref(imageToken.href);
          return;
        }

        if (token.type !== 'link') {
          return;
        }

        const linkToken = token as Tokens.Link;
        if (linkToken.href.startsWith('#')) {
          return;
        }

        linkToken.href = rewriteGuideHref(linkToken.href);
      },
    } as MarkedExtension;

    marked.use(options);
    const parsed = String(marked.parse(text));
    content.value = parsed
      .replace(/<table>/g, '<div class="ws-markdown-table"><table>')
      .replace(/<\/table>/g, '</table></div>');
    await nextTick();
    addListeners();
  } catch (caughtError) {
    console.error(caughtError);
    error.value = caughtError instanceof Error ? caughtError.message : 'Unexpected error';
  } finally {
    isLoading.value = false;
  }
};

watch(
  () => props.file,
  async () => await loadContent(),
  { immediate: true },
);

const handleGuideIconsUpdate = (): void => {
  void loadContent();
};

if (import.meta.hot) {
  window.addEventListener(GUIDE_ICONS_UPDATED_EVENT, handleGuideIconsUpdate);
}

onBeforeUnmount(() => {
  removeListeners();
  window.removeEventListener(GUIDE_ICONS_UPDATED_EVENT, handleGuideIconsUpdate);
});
</script>

<style>
.ws-markdown .markdown-alert {
  padding: 0.75rem 1rem;
  color: var(--ui-text);
  border: 1px solid var(--ui-border);
  border-left: 0.25rem solid var(--ui-border-accented);
  border-radius: var(--ui-radius);
  background: color-mix(in srgb, var(--ui-bg-elevated) 40%, transparent);
}

.ws-markdown .markdown-alert > :first-child {
  margin-top: 0;
}

.ws-markdown .markdown-alert > :last-child {
  margin-bottom: 0;
}

.markdown-alert-title {
  display: inline-flex;
  align-items: center;
  gap: 0.375rem;
  font-weight: 500;
  text-transform: uppercase;
  user-select: none;
}

.markdown-alert-title svg {
  width: 1rem;
  height: 1rem;
  flex: 0 0 1rem;
  color: currentColor;
  fill: currentColor;
  stroke: currentColor;
}

.ws-markdown .markdown-alert-note {
  border-left-color: var(--ui-info);
}

.ws-markdown .markdown-alert-tip {
  border-left-color: var(--ui-success);
}

.ws-markdown .markdown-alert-important {
  border-left-color: var(--ui-primary);
}

.ws-markdown .markdown-alert-warning {
  border-left-color: var(--ui-warning);
}

.ws-markdown .markdown-alert-caution {
  border-left-color: var(--ui-error);
}

.markdown-alert-note > .markdown-alert-title {
  color: var(--ui-info);
}

.markdown-alert-tip > .markdown-alert-title {
  color: var(--ui-success);
}

.markdown-alert-important > .markdown-alert-title {
  color: var(--ui-primary);
}

.markdown-alert-warning > .markdown-alert-title {
  color: var(--ui-warning);
}

.markdown-alert-caution > .markdown-alert-title {
  color: var(--ui-error);
}

.ws-guide-icon {
  display: inline-flex;
  width: 1rem;
  height: 1rem;
  margin-right: 0.25rem;
  vertical-align: -0.125em;
}

.ws-guide-icon svg {
  width: 100%;
  height: 100%;
}

.ws-markdown {
  min-width: 0;
  overflow-wrap: anywhere;
  color: var(--ui-text);
  line-height: 1.75;
}

.ws-markdown > *:first-child {
  margin-top: 0;
}

.ws-markdown > *:last-child {
  margin-bottom: 0;
}

.ws-markdown h1,
.ws-markdown h2,
.ws-markdown h3,
.ws-markdown h4,
.ws-markdown h5,
.ws-markdown h6 {
  margin: 1.5rem 0 0.75rem;
  color: var(--ui-text-highlighted);
  font-weight: 600;
  line-height: 1.3;
  scroll-margin-top: calc(var(--ui-header-height) + 1.5rem);
}

.ws-markdown h1 {
  font-size: 1.875rem;
}

.ws-markdown h2 {
  font-size: 1.5rem;
}

.ws-markdown h3 {
  font-size: 1.25rem;
}

.ws-markdown h4,
.ws-markdown h5,
.ws-markdown h6 {
  font-size: 1.125rem;
}

.ws-markdown p,
.ws-markdown ul,
.ws-markdown ol,
.ws-markdown pre,
.ws-markdown table,
.ws-markdown blockquote,
.ws-markdown hr {
  margin: 1rem 0;
}

.ws-markdown ul,
.ws-markdown ol {
  padding-left: 1.5rem;
}

.ws-markdown ul {
  list-style: disc;
}

.ws-markdown ol {
  list-style: decimal;
}

.ws-markdown li + li {
  margin-top: 0.35rem;
}

.ws-markdown a {
  color: var(--ui-primary);
  text-decoration: underline;
  text-underline-offset: 0.2em;
}

.ws-markdown code {
  padding: 0.1rem 0.35rem;
  border-radius: 0.375rem;
  background: color-mix(in srgb, var(--ui-bg-accented) 40%, transparent);
  font-size: 0.875em;
}

.ws-markdown pre {
  overflow-x: auto;
  border-radius: 0.375rem;
  border: 1px solid var(--ui-border);
  background: color-mix(in srgb, var(--ui-bg-elevated) 40%, transparent);
  padding: 1rem;
  color: var(--ui-text);
}

.ws-markdown pre code {
  padding: 0;
  background: transparent;
}

.ws-markdown blockquote {
  border-left: 0.25rem solid color-mix(in srgb, var(--ui-border) 70%, transparent);
  padding-left: 1rem;
  color: color-mix(in srgb, var(--ui-text-highlighted) 65%, transparent);
}

.ws-markdown hr {
  border: 0;
  border-top: 1px solid color-mix(in srgb, var(--ui-border) 50%, transparent);
}

.ws-markdown table {
  width: 100%;
  border-collapse: collapse;
  overflow: hidden;
  border-radius: 0.375rem;
  border: 1px solid var(--ui-border);
  background: color-mix(in srgb, var(--ui-bg-elevated) 20%, transparent);
}

.ws-markdown-table {
  max-width: 100%;
  margin: 1rem 0;
  overflow-x: auto;
  border: 1px solid var(--ui-border);
  border-radius: 0.375rem;
}

.ws-markdown .ws-markdown-table table {
  width: max-content;
  min-width: 100%;
  margin: 0;
  border: 0;
  border-radius: 0;
}

.ws-markdown .ws-markdown-table th,
.ws-markdown .ws-markdown-table td {
  white-space: nowrap;
}

.ws-markdown th,
.ws-markdown td {
  border: 1px solid var(--ui-border);
  padding: 0.65rem 0.75rem;
  text-align: left;
  vertical-align: top;
}

.ws-markdown th {
  font-weight: 600;
  background: color-mix(in srgb, var(--ui-bg-elevated) 40%, transparent);
}

.ws-markdown tbody tr:nth-child(even) {
  background: color-mix(in srgb, var(--ui-bg-elevated) 55%, transparent);
}

.ws-markdown img {
  display: inline-block;
  max-width: 100%;
  border-radius: 0.375rem;
}

.ws-markdown details summary {
  cursor: pointer;
  user-select: none;
}
</style>
