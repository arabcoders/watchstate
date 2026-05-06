<template>
  <UApp>
    <div class="ws-shell ws-shell-surface flex min-h-screen flex-col">
      <UDashboardGroup
        storage="local"
        storage-key="watchstate-shell"
        class="min-h-screen"
        :ui="{ base: 'relative flex min-h-screen overflow-visible' }"
      >
        <UDashboardSidebar
          v-model:open="showSidebar"
          side="left"
          collapsible
          resizable
          :default-size="17"
          :min-size="11"
          :max-size="22"
          :collapsed-size="4"
          :ui="dashboardSidebarUi"
        >
          <template #header="{ collapsed }">
            <div
              class="flex w-full min-w-0 items-center gap-3"
              :class="collapsed ? 'justify-center' : ''"
            >
              <NuxtLink
                to="/"
                class="inline-flex size-10 shrink-0 items-center justify-center rounded-md border border-default bg-elevated/80 shadow-sm"
              >
                <img src="/images/logo_nobg.png" alt="WatchState" class="size-7 object-contain" />
              </NuxtLink>

              <div v-if="!collapsed" class="min-w-0">
                <p class="truncate text-sm font-semibold text-highlighted">WatchState</p>
                <p class="truncate text-xs text-toned">Control Panel Dashboard</p>
              </div>
            </div>
          </template>

          <template #default="{ collapsed }">
            <div class="flex h-full flex-col gap-6 px-1 py-1">
              <div v-for="section in sidebarSections" :key="section.id" class="space-y-2">
                <p
                  v-if="!collapsed && section.label"
                  class="px-2 text-[11px] font-semibold uppercase tracking-[0.22em] text-toned"
                >
                  {{ section.label }}
                </p>

                <UNavigationMenu
                  orientation="vertical"
                  :collapsed="collapsed"
                  :items="section.items"
                  :tooltip="true"
                  :ui="navigationUi(collapsed)"
                />
              </div>
            </div>
          </template>

          <template #footer="{ collapsed }">
            <div class="flex w-full flex-col gap-3">
              <div
                v-if="!collapsed"
                class="rounded-md border border-default bg-elevated/70 px-3 py-2 text-xs text-toned"
              >
                <span v-if="apiVersion">v{{ apiVersion }}</span>
                <span v-else>Loading version...</span>
              </div>
            </div>
          </template>
        </UDashboardSidebar>

        <UDashboardPanel class="min-w-0 bg-transparent" :ui="dashboardPanelUi">
          <template #header>
            <UDashboardNavbar :toggle="false" :title="pageTitle" :ui="dashboardNavbarUi">
              <template #left>
                <div class="flex min-w-0 items-center gap-2">
                  <UDashboardSidebarToggle class="lg:hidden" />
                  <UButton
                    to="/"
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    icon="i-lucide-house"
                    class="lg:hidden"
                  >
                    Home
                  </UButton>
                  <UDashboardSidebarCollapse class="hidden lg:inline-flex" />
                </div>
              </template>

              <template #right>
                <div class="flex items-center gap-1.5 sm:gap-2">
                  <UTooltip v-if="inContainer" text="Task Scheduler Status">
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="sm"
                      :color-mode="undefined"
                      class="hidden sm:inline-flex"
                      @click="showScheduler = !showScheduler"
                    >
                      <UIcon
                        name="i-lucide-cpu"
                        :class="[
                          'size-4',
                          scheduler.status ? 'text-primary' : 'animate-pulse text-error',
                        ]"
                      />
                      <span class="hidden lg:inline">Scheduler</span>
                    </UButton>
                  </UTooltip>

                  <UButton
                    to="/events"
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    icon="i-lucide-calendar-days"
                  >
                    <span class="hidden xl:inline">Events</span>
                    <StatusDots
                      :stats="eventsStats.stats.value"
                      v-if="!eventsStats.loading.value"
                    />
                    <UIcon v-else name="i-lucide-loader-circle" class="size-4 animate-spin" />
                  </UButton>

                  <UTooltip text="Change Identity" placement="bottom">
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="sm"
                      icon="i-lucide-users"
                      @click="showIdentitySelection = true"
                    >
                      <span class="hidden xl:inline">{{ apiUser }}</span>
                    </UButton>
                  </UTooltip>

                  <UDashboardSearchButton class="hidden shrink-0 sm:inline-flex" />

                  <UTooltip :text="colorModeButtonTitle" placement="bottom">
                    <UButton
                      color="neutral"
                      variant="ghost"
                      size="sm"
                      :icon="colorModeButtonIcon"
                      :aria-label="colorModeButtonAriaLabel"
                      @click="cycleColorMode"
                    />
                  </UTooltip>

                  <UButton
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    icon="i-lucide-settings-2"
                    @click="showSettings = true"
                  >
                    <span class="hidden xl:inline">Settings</span>
                  </UButton>

                  <UButton
                    color="neutral"
                    variant="ghost"
                    size="sm"
                    icon="i-lucide-log-out"
                    @click="() => void logout()"
                  >
                    <span class="hidden xl:inline">Logout</span>
                  </UButton>
                </div>
              </template>
            </UDashboardNavbar>
          </template>

          <template #body>
            <NuxtLoadingIndicator />

            <div
              class="mx-auto flex w-full max-w-450 min-w-0 flex-1 flex-col px-4 py-4 sm:px-5 lg:px-6"
            >
              <NewVersion v-if="newVersionIsAvailable" />

              <TaskScheduler
                :forceShow="showScheduler"
                @update="(e) => (scheduler = e)"
                v-if="inContainer"
              />

              <div
                class="ws-shell-panel flex min-h-0 flex-1 flex-col p-3 sm:p-4 lg:p-5 ws-blur-text"
              >
                <NuxtPage />
              </div>
            </div>

            <ClientOnly>
              <Dialog />
            </ClientOnly>

            <UModal
              v-model:open="showIdentitySelection"
              title="Change Identity"
              :ui="identitySelectionModalUi"
            >
              <template #body>
                <IdentitySelection @close="() => (showIdentitySelection = false)" />
              </template>
            </UModal>

            <SettingsPanel
              :isOpen="showSettings"
              @close="showSettings = false"
              @force_bg_reload="() => void forceBackgroundReload()"
            />
          </template>
        </UDashboardPanel>

        <UDashboardSearch
          v-model:open="showRouteSearch"
          :groups="routeSearchGroups"
          shortcut="meta_k"
          placeholder="Search routes and actions"
          :ui="{ modal: 'sm:max-w-3xl h-full sm:h-[28rem]' }"
        />
      </UDashboardGroup>
    </div>
  </UApp>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, readonly, ref, watch } from 'vue';
import { useBreakpoints, useStorage } from '@vueuse/core';
import { navigateTo } from '#app';
import { useAuthStore } from '~/store/auth';
import { useMediaQuery } from '~/composables/useMediaQuery';
import { usePageBackground } from '~/composables/usePageBackground';
import { useDialog } from '~/composables/useDialog';
import { getSidebarSwipeMode } from '~/utils/sidebarSwipe';
import {
  getTopLevelNavigationEntries,
  getTopLevelNavigationSections,
} from '~/utils/topLevelNavigation';
import { dEvent, registerToastController, request, syncOpacity } from '~/utils';
import TaskScheduler from '~/components/TaskScheduler.vue';
import NewVersion from '~/components/NewVersion.vue';
import Dialog from '~/components/Dialog.vue';
import SettingsPanel from '~/components/SettingsPanel.vue';
import StatusDots from '~/components/StatusDots.vue';
import IdentitySelection from '~/components/IdentitySelection.vue';
import useEventsStats from '~/composables/useEventsStats';

type NavEntry = {
  id: string;
  label: string;
  icon: string;
  matchPath?: string;
  exactMatch?: boolean;
  excludeMatchPaths?: Array<string>;
  to?: string;
  href?: string;
  target?: string;
  children?: Array<NavEntry>;
  onSelect?: () => void;
};

type SidebarSection = {
  id: string;
  label: string;
  items: Array<Array<NavEntry>>;
};

type SearchItem = {
  label: string;
  description?: string;
  icon: string;
  suffix?: string;
  onSelect?: () => void;
};

type SearchGroup = {
  id: string;
  label: string;
  items: Array<SearchItem>;
};

type ColorModePreference = 'system' | 'light' | 'dark';
type MobileSidebarSwipeMode = 'open' | 'close';

const MOBILE_SIDEBAR_MIN_SWIPE_DISTANCE = 64;

const useVersionUpdate = () => {
  const newVersionIsAvailable = ref(false);
  const nuxtApp = useNuxtApp();

  nuxtApp.hooks.addHooks({
    'app:manifest:update': () => {
      newVersionIsAvailable.value = true;
    },
  });

  return {
    newVersionIsAvailable: readonly(newVersionIsAvailable),
  };
};

const route = useRoute();
const colorMode = useColorMode();
registerToastController(useToast());
const { newVersionIsAvailable } = useVersionUpdate();
const auth = useAuthStore();
const breakpoints = useBreakpoints({ mobile: 0, desktop: 640 });
const eventsStats = useEventsStats(['pending']);
const { pageBackgroundOverride, requestPageBackgroundReload } = usePageBackground();

const bgEnable = useStorage<boolean>('bg_enable', true);
const bgOpacity = useStorage<number>('bg_opacity', 0.95);
const apiUser = useStorage<string>('api_user', 'main');
const isMobile = useMediaQuery({ query: '(max-width: 1023px)' });

const showIdentitySelection = ref(false);
const showSettings = ref(false);
const inContainer = ref(false);
const showScheduler = ref(false);
const showRouteSearch = ref(false);
const showSidebar = ref(false);

const swipeState = {
  mode: null as MobileSidebarSwipeMode | null,
  tracking: false,
  startX: 0,
  startY: 0,
  endX: 0,
  endY: 0,
};

const colorModePreferences: Array<ColorModePreference> = ['system', 'light', 'dark'];

const colorModePreference = computed<ColorModePreference>(() => {
  const preference = colorMode.preference;
  return colorModePreferences.includes(preference as ColorModePreference)
    ? (preference as ColorModePreference)
    : 'system';
});

const colorModeButtonIcon = computed(() => {
  switch (colorModePreference.value) {
    case 'light':
      return 'i-lucide-sun';
    case 'dark':
      return 'i-lucide-moon';
    default:
      return 'i-lucide-monitor';
  }
});

const nextColorModePreference = computed<ColorModePreference>(() => {
  const currentIndex = colorModePreferences.indexOf(colorModePreference.value);
  return colorModePreferences[(currentIndex + 1) % colorModePreferences.length] ?? 'system';
});

const colorModeButtonTitle = computed(() => {
  switch (colorModePreference.value) {
    case 'light':
      return 'Theme: Light';
    case 'dark':
      return 'Theme: Dark';
    default:
      return 'Theme: System';
  }
});

const colorModeButtonAriaLabel = computed(() => {
  switch (nextColorModePreference.value) {
    case 'light':
      return 'Switch theme to light';
    case 'dark':
      return 'Switch theme to dark';
    default:
      return 'Switch theme to system';
  }
});

const cycleColorMode = (): void => {
  colorMode.preference = nextColorModePreference.value;
};

const resetSwipe = (): void => {
  swipeState.mode = null;
  swipeState.tracking = false;
  swipeState.startX = 0;
  swipeState.startY = 0;
  swipeState.endX = 0;
  swipeState.endY = 0;
};

const updateSwipePosition = (touch?: Touch): void => {
  if (!touch) {
    return;
  }

  swipeState.endX = touch.clientX;
  swipeState.endY = touch.clientY;
};

const handleSwipeStart = (event: TouchEvent): void => {
  if (false === isMobile.value || 1 !== event.touches.length) {
    resetSwipe();
    return;
  }

  const touch = event.touches[0];

  if (!touch) {
    resetSwipe();
    return;
  }

  const swipeMode: MobileSidebarSwipeMode | null = getSidebarSwipeMode(
    showSidebar.value,
    touch.clientX,
    navigator,
  );

  if (!swipeMode) {
    resetSwipe();
    return;
  }

  swipeState.mode = swipeMode;
  swipeState.tracking = true;
  swipeState.startX = touch.clientX;
  swipeState.startY = touch.clientY;
  updateSwipePosition(touch);
};

const handleSwipeMove = (event: TouchEvent): void => {
  if (false === swipeState.tracking || 1 !== event.touches.length) {
    return;
  }

  updateSwipePosition(event.touches[0]);
};

const completeSwipe = (): void => {
  if (false === swipeState.tracking) {
    return;
  }

  const swipeMode = swipeState.mode;
  const deltaX = swipeState.endX - swipeState.startX;
  const deltaY = swipeState.endY - swipeState.startY;
  const isHorizontalOpenSwipe =
    'open' === swipeMode &&
    deltaX >= MOBILE_SIDEBAR_MIN_SWIPE_DISTANCE &&
    deltaX > Math.abs(deltaY);
  const isHorizontalCloseSwipe =
    'close' === swipeMode &&
    deltaX <= -MOBILE_SIDEBAR_MIN_SWIPE_DISTANCE &&
    Math.abs(deltaX) > Math.abs(deltaY);

  resetSwipe();

  if (true === isHorizontalOpenSwipe) {
    showSidebar.value = true;
  }

  if (true === isHorizontalCloseSwipe) {
    showSidebar.value = false;
  }
};

const handleSwipeEnd = (event: TouchEvent): void => {
  updateSwipePosition(event.changedTouches[0]);
  completeSwipe();
};

const handleSwipeCancel = (): void => {
  resetSwipe();
};

const identitySelectionModalUi = {
  content: 'sm:max-w-lg',
  body: 'p-4 sm:p-5',
};

const scheduler = ref<{ status: boolean; message: string; restartable: boolean }>({
  status: false,
  message: 'Loading...',
  restartable: false,
});

const apiVersion = ref<string | undefined>();
const apiVersionSha = ref<string | undefined>();
const apiVersionDate = ref<string | undefined>();
const apiVersionBranch = ref<string | undefined>();
const bgImage = ref<{ src: string; type: string }>({ src: '', type: '' });
const loadedImages = ref<Record<string, string>>({ poster: '', background: '' });

const changelogUrl = computed(() => `/changelog?version=${apiVersion.value ?? ''}`);
const effectiveBackgroundSrc = computed(() => {
  if (false === bgEnable.value) {
    return '';
  }

  return pageBackgroundOverride.value?.src ?? bgImage.value.src;
});

const normalizePath = (value?: string | null) => {
  if (!value || '/' === value) {
    return '/';
  }

  const trimmed = value.replace(/\/+$/, '');
  return '' === trimmed ? '/' : trimmed;
};

const isPathActive = (matchPath?: string) => {
  if (!matchPath) {
    return false;
  }

  const current = normalizePath(route.path);
  const target = normalizePath(matchPath);

  if ('/' === target) {
    return current === '/';
  }

  return current === target || current.startsWith(`${target}/`);
};

const isNavigationEntryActive = (entry: NavEntry) => {
  if (!entry.matchPath) {
    return false;
  }

  const current = normalizePath(route.path);
  const target = normalizePath(entry.matchPath);

  if (entry.excludeMatchPaths?.some((path) => isPathActive(path))) {
    return false;
  }

  if (true === entry.exactMatch) {
    return current === target;
  }

  return isPathActive(entry.matchPath);
};

const topLevelNavigationEntries = computed(() =>
  getTopLevelNavigationEntries({
    apiUser: apiUser.value,
    changelogUrl: changelogUrl.value,
  }),
);

const topLevelNavEntries = computed<Array<NavEntry>>(() =>
  topLevelNavigationEntries.value.map((entry) => ({
    id: entry.id,
    label: entry.label,
    icon: entry.icon,
    to: entry.to,
    href: entry.href,
    target: entry.target,
    matchPath: entry.matchPath,
    exactMatch: entry.exactMatch,
    excludeMatchPaths: entry.excludeMatchPaths,
    onSelect:
      'history' === entry.id
        ? () => dEvent('history_main_link_clicked', { clear: true })
        : undefined,
  })),
);

const sidebarDefinitions = computed<Array<SidebarSection>>(() => {
  const resolvedEntries = topLevelNavigationEntries.value;
  const entries = topLevelNavEntries.value;

  return getTopLevelNavigationSections()
    .map((section) => ({
      id: section.id,
      label: section.label,
      items: [
        entries.filter((entry) =>
          resolvedEntries.some(
            (resolved) => resolved.id === entry.id && resolved.section === section.id,
          ),
        ),
      ],
    }))
    .filter((section) => section.items.some((group) => group.length > 0));
});

const makeNavigationItem = (entry: NavEntry) => ({
  label: entry.label,
  icon: entry.icon,
  to: entry.to,
  href: entry.href,
  target: entry.target,
  active: isNavigationEntryActive(entry),
  onSelect: entry.onSelect,
});

const sidebarSections = computed(() =>
  sidebarDefinitions.value.map((section) => ({
    ...section,
    items: section.items.map((group) => group.map((entry) => makeNavigationItem(entry))),
  })),
);

const allNavEntries = computed(() =>
  sidebarDefinitions.value.flatMap((section) => section.items.flat()),
);

const closeRouteSearch = async (): Promise<void> => {
  if (false === showRouteSearch.value) {
    return;
  }

  showRouteSearch.value = false;
  await nextTick();
};

const handleRouteSelect = async (entry: {
  to?: string;
  href?: string;
  target?: string;
  onSelect?: () => void;
}): Promise<void> => {
  await closeRouteSearch();

  entry.onSelect?.();

  if (entry.href) {
    if ('_blank' === entry.target) {
      window.open(entry.href, '_blank', 'noopener,noreferrer');
      return;
    }

    window.location.assign(entry.href);
    return;
  }

  if (entry.to) {
    await navigateTo(entry.to);
  }
};

const routeSearchGroups = computed<Array<SearchGroup>>(() => {
  const navigationGroups: Array<SearchGroup> = sidebarDefinitions.value
    .map((section) => ({
      id: section.id,
      label: section.label,
      items: section.items.flat().map((entry) => ({
        label: entry.label,
        description: entry.href ?? entry.to,
        icon: entry.icon,
        onSelect: () => void handleRouteSelect(entry),
      })),
    }))
    .filter((section) => section.items.length > 0);

  const actionItems: Array<SearchItem> = [
    {
      label: 'Change Identity',
      description: 'Open the identity switcher.',
      icon: 'i-lucide-users',
      suffix: 'Action',
      onSelect: async () => {
        await closeRouteSearch();
        showIdentitySelection.value = true;
      },
    },
    {
      label: 'WebUI Settings',
      description: 'Open the WebUI settings drawer.',
      icon: 'i-lucide-settings-2',
      suffix: 'Preferences',
      onSelect: async () => {
        await closeRouteSearch();
        showSettings.value = true;
      },
    },
    {
      label: 'Logout',
      description: 'Sign out of the current session.',
      icon: 'i-lucide-log-out',
      suffix: 'Action',
      onSelect: async () => {
        await closeRouteSearch();
        void logout();
      },
    },
  ];

  if (true === inContainer.value) {
    actionItems.splice(2, 0, {
      label: true === showScheduler.value ? 'Hide Scheduler Status' : 'Show Scheduler Status',
      description: 'Toggle the scheduler status panel.',
      icon: true === scheduler.value.status ? 'i-lucide-cpu' : 'i-lucide-triangle-alert',
      suffix: 'Action',
      onSelect: async () => {
        await closeRouteSearch();
        showScheduler.value = !showScheduler.value;
      },
    });
  }

  return [...navigationGroups, { id: 'actions', label: 'Actions', items: actionItems }];
});

const pageTitle = computed(() => {
  const match = allNavEntries.value
    .filter((entry) => isNavigationEntryActive(entry))
    .sort(
      (left, right) => normalizePath(right.matchPath).length - normalizePath(left.matchPath).length,
    )[0];

  return match?.label ?? 'WatchState';
});

const dashboardSidebarUi = {
  root: 'ws-shell-surface border-r border-default bg-default/95 backdrop-blur-sm',
  header: 'border-b border-default px-2.5 py-3',
  body: 'gap-3 px-2.5 py-3',
  footer: 'border-t border-default px-2.5 py-3',
};

const dashboardNavbarUi = {
  root: 'border-b border-default bg-transparent px-4 py-3 sm:px-5 lg:px-6',
  title: 'text-sm font-semibold text-highlighted',
  right: 'flex items-center shrink-0 gap-1.5',
};

const dashboardPanelUi = {
  root: 'min-w-0 min-h-screen max-w-full flex flex-1 flex-col bg-transparent',
  body: 'flex min-h-0 min-w-0 max-w-full flex-1 flex-col overflow-y-visible p-0',
};

const navigationUi = (collapsed: boolean) => ({
  root: 'w-full',
  list: 'gap-1.5',
  link: collapsed
    ? 'justify-center rounded-md px-2 py-2'
    : 'rounded-md px-2.5 py-2 text-sm font-medium text-default transition-colors',
  linkLeadingIcon: collapsed ? 'size-5' : 'size-4',
  linkLabel: collapsed ? 'hidden' : 'truncate',
});

const getVersion = async (): Promise<void> => {
  if (apiVersion.value) {
    return;
  }

  try {
    const response = await request('/system/version');
    const json = await response.json();
    apiVersion.value = json.version;
    apiVersionSha.value = json.sha;
    apiVersionDate.value = json.build;
    apiVersionBranch.value = json.branch;
    inContainer.value = Boolean(json.container);
  } catch {
    apiVersion.value = 'Unknown';
  }
};

watch(
  effectiveBackgroundSrc,
  async (src) => {
    const html = document.documentElement;

    if ('' === src || 'failed' === src) {
      if (html.getAttribute('style')) {
        html.removeAttribute('style');
      }

      html.classList.remove('bg-fanart');
      return;
    }

    const style: Record<string, string> = {
      'background-color': 'unset',
      display: 'block',
      'min-height': '100%',
      'min-width': '100%',
      'background-image': `url(${src})`,
    };

    html.setAttribute(
      'style',
      Object.keys(style)
        .map((key) => `${key}: ${style[key]}`)
        .join('; ')
        .trim(),
    );

    html.classList.add('bg-fanart');
    syncOpacity();
  },
  { immediate: true },
);

watch(bgOpacity, () => {
  if (false === bgEnable.value) {
    return;
  }

  syncOpacity();
});

watch(breakpoints.active(), async () => await loadImage());

watch(bgEnable, async (value) => {
  if (true === value) {
    if (pageBackgroundOverride.value?.src) {
      syncOpacity();
      return;
    }

    await loadImage();
    return;
  }

  loadedImages.value = { poster: '', background: '' };
  bgImage.value = { src: '', type: '' };

  const html = document.documentElement;
  html.removeAttribute('style');
  html.classList.remove('bg-fanart');
  const body = document.querySelector('body');
  if (body) {
    body.style.removeProperty('opacity');
  }
});

watch(isMobile, (v) => {
  if (true === v) {
    return;
  }

  showSidebar.value = false;
  resetSwipe();
});

const forceBackgroundReload = async (): Promise<void> => {
  if (pageBackgroundOverride.value?.id) {
    requestPageBackgroundReload(pageBackgroundOverride.value.id);
    return;
  }

  await loadImage(true);
};

const loadImage = async (force = false): Promise<void> => {
  if (!bgEnable.value) {
    return;
  }

  const bgType = 'mobile' === breakpoints.active().value ? 'poster' : 'background';

  if (false === force && bgImage.value && bgImage.value.type === bgType) {
    return;
  }

  if (false === force && loadedImages.value[bgType]) {
    bgImage.value = { src: loadedImages.value[bgType], type: bgType };
    return;
  }

  let url = `/system/images/${bgType}`;
  if (force) {
    url += `?force=1&t=${Date.now()}`;
  }

  const imgRequest = await request(url);
  if (200 !== imgRequest.status) {
    bgImage.value = { src: 'failed', type: bgType };
    return;
  }

  try {
    loadedImages.value[bgType] = URL.createObjectURL(await imgRequest.blob());
    bgImage.value = {
      src: loadedImages.value[bgType],
      type: bgType,
    };
  } catch {
    bgImage.value = { src: 'failed', type: bgType };
  }
};

const logout = async (): Promise<boolean> => {
  const { status } = await useDialog().confirmDialog({
    title: 'Logout',
    message: 'Are you sure you want to logout?',
    confirmColor: 'error',
  });

  if (true !== status) {
    return false;
  }

  await auth.logout();
  await navigateTo('/auth');
  return true;
};

onMounted(async () => {
  document.addEventListener('touchstart', handleSwipeStart, {
    passive: true,
    capture: true,
  });
  document.addEventListener('touchmove', handleSwipeMove, {
    passive: true,
    capture: true,
  });
  document.addEventListener('touchend', handleSwipeEnd, {
    passive: true,
    capture: true,
  });
  document.addEventListener('touchcancel', handleSwipeCancel, {
    passive: true,
    capture: true,
  });
  await getVersion();
  await loadImage();
  eventsStats.start();
});

onBeforeUnmount(() => {
  document.removeEventListener('touchstart', handleSwipeStart, true);
  document.removeEventListener('touchmove', handleSwipeMove, true);
  document.removeEventListener('touchend', handleSwipeEnd, true);
  document.removeEventListener('touchcancel', handleSwipeCancel, true);
});
</script>
