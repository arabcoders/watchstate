<template>
  <div class="space-y-4">
    <UAlert
      v-if="isLoading"
      color="info"
      variant="soft"
      title="Loading"
      icon="i-lucide-loader-circle"
      description="Loading data. Please wait..."
      :ui="{ icon: 'animate-spin' }"
    />

    <UAlert
      v-else-if="error"
      color="warning"
      variant="soft"
      title="Error"
      icon="i-lucide-triangle-alert"
      :description="error"
    />

    <UCard v-else class="border border-default/70 shadow-sm" :ui="{ body: 'p-0' }">
      <div ref="contentRoot" class="ws-markdown" v-html="content" />
    </UCard>
  </div>
</template>

<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { navigateTo } from '#app';
import { marked } from 'marked';
import type { MarkedExtension, Tokens } from 'marked';
import { baseUrl } from 'marked-base-url';
import markedAlert from 'marked-alert';
import { gfmHeadingId } from 'marked-gfm-heading-id';
import lucideCollection from '@iconify-json/lucide/icons.json';
import { parse_api_response } from '~/utils';
import type { GenericError } from '~/types';

type LucideIcon = {
  body?: string;
  width?: number;
  height?: number;
  parent?: string;
};

type LucideCollection = {
  icons: Record<string, LucideIcon>;
};

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

const resolveGuideIconName = (value: string): string => {
  const normalized = value.trim();
  const iconName = normalized.startsWith('i-lucide-') ? normalized : 'i-lucide-circle-help';

  return iconName.replace(/^i-lucide-/, '');
};

const resolveGuideIconData = (value: string): LucideIcon | null => {
  const collection = lucideCollection as LucideCollection;
  const icons = collection.icons;
  const fallbackName = 'circle-question-mark';
  const resolvedName = icons[resolveGuideIconName(value)]
    ? resolveGuideIconName(value)
    : fallbackName;

  let currentName: string | undefined = resolvedName;
  const visited = new Set<string>();

  while (currentName && !visited.has(currentName)) {
    visited.add(currentName);

    const current: LucideIcon | undefined = icons[currentName];
    if (!current) {
      break;
    }

    if (current.body) {
      return current;
    }

    currentName = current.parent;
  }

  return icons[fallbackName] ?? null;
};

const renderGuideIcon = (value: string): string => {
  const icon = resolveGuideIconData(value);
  if (!icon?.body) {
    return '';
  }

  const width = icon.width ?? 24;
  const height = icon.height ?? 24;

  return `<span class="ws-guide-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}">${icon.body}</svg></span>`;
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

onBeforeUnmount(() => removeListeners());
</script>

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
  padding: 1.25rem;
  color: inherit;
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
  font-weight: 700;
  line-height: 1.3;
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
  color: var(--ui-color-primary-500);
  text-decoration: underline;
  text-underline-offset: 0.2em;
}

.ws-markdown code {
  padding: 0.1rem 0.35rem;
  border-radius: 0.375rem;
  background: color-mix(in srgb, var(--ui-border) 30%, transparent);
  font-size: 0.875em;
}

.ws-markdown pre {
  overflow-x: auto;
  border-radius: 0.375rem;
  border: 1px solid color-mix(in srgb, var(--ui-border) 45%, transparent);
  background: color-mix(in srgb, var(--ui-bg) 22%, black);
  padding: 1rem;
  color: color-mix(in srgb, white 88%, var(--ui-text-highlighted) 12%);
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
  background: var(--ui-bg);
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
  background: var(--ui-bg-elevated);
}

.ws-markdown tbody tr:nth-child(even) {
  background: color-mix(in srgb, var(--ui-bg-elevated) 55%, transparent);
}

.ws-markdown img {
  display: inline-block;
  max-width: 100%;
  border-radius: 0.375rem;
}
</style>
