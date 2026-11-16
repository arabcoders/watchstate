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
          :default-size="15"
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
                <p class="truncate text-xs text-muted">Play-state sync</p>
              </div>
            </div>
          </template>

          <template #default="{ collapsed }">
            <div class="flex h-full flex-col gap-5 px-1 py-1">
              <UNavigationMenu
                v-model="openNavigationGroups"
                orientation="vertical"
                :collapsed="collapsed"
                :items="sidebarItems"
                :tooltip="true"
                :popover="{ mode: 'click' }"
                :ui="navigationUi(collapsed)"
              />
            </div>
          </template>
        </UDashboardSidebar>

        <UDashboardPanel class="min-w-0 bg-transparent" :ui="dashboardPanelUi">
          <template #header>
            <UDashboardNavbar :toggle="false" :title="pageTitle" :ui="dashboardNavbarUi">
              <template #left>
                <div class="flex min-w-0 shrink-0 items-center gap-2">
                  <UDashboardSidebarToggle class="lg:hidden" />
                  <UDashboardSidebarCollapse class="hidden lg:inline-flex" />
                </div>
              </template>

              <template #right>
                <div class="flex min-w-0 shrink items-center gap-0.5 sm:gap-2">
                  <UTooltip
                    v-if="inContainer"
                    :text="scheduler.status ? 'Task scheduler running' : 'Task scheduler stopped'"
                  >
                    <UButton
                      color="neutral"
                      :variant="showScheduler ? 'soft' : 'outline'"
                      size="sm"
                      :color-mode="undefined"
                      class="hidden lg:inline-flex"
                      aria-label="Toggle scheduler status"
                      :aria-pressed="showScheduler"
                      @click="
                        () => {
                          showScheduler = !showScheduler;
                        }
                      "
                    >
                      <UIcon
                        name="i-lucide-cpu"
                        :class="['size-4', { 'animate-pulse': !scheduler.status }]"
                      />
                      <span class="hidden lg:inline">Scheduler</span>
                    </UButton>
                  </UTooltip>

                  <HeaderStatus
                    class="hidden xl:flex"
                    :stats="systemStats.stats.value"
                    :loading="systemStats.loading.value"
                    :error="systemStats.error.value"
                  />

                  <UTooltip text="Change Identity" placement="bottom">
                    <UButton
                      color="neutral"
                      variant="soft"
                      size="sm"
                      icon="i-lucide-users"
                      :aria-label="`Browsing as ${apiUser}. Change identity`"
                      :title="`Browsing as ${apiUser}`"
                      @click="
                        () => {
                          showIdentitySelection = true;
                        }
                      "
                    >
                      <span class="hidden text-xs font-normal text-muted sm:inline">Identity</span>
                      <span class="max-w-24 truncate sm:max-w-36">{{ apiUser }}</span>
                    </UButton>
                  </UTooltip>

                  <UTooltip text="Find a page or action">
                    <UButton
                      color="neutral"
                      variant="ghost"
                      icon="i-lucide-search"
                      aria-label="Find a page or action"
                      @click="showRouteSearch = true"
                    />
                  </UTooltip>

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

                  <UDropdownMenu :items="accountActions" :content="{ align: 'end' }">
                    <UTooltip text="Preferences and sign out">
                      <UButton
                        color="neutral"
                        variant="ghost"
                        size="sm"
                        icon="i-lucide-ellipsis"
                        aria-label="Preferences and sign out"
                      />
                    </UTooltip>
                  </UDropdownMenu>
                </div>
              </template>
            </UDashboardNavbar>
          </template>

          <template #body>
            <NuxtLoadingIndicator />

            <div
              class="relative isolate mx-auto flex w-full max-w-450 min-w-0 flex-1 flex-col px-4 py-5 sm:px-6 sm:py-7 lg:px-10"
            >
              <div
                v-if="effectiveBackgroundSrc"
                aria-hidden="true"
                class="ws-header-artwork pointer-events-none absolute inset-x-0 top-0 -z-10 h-80"
                :style="{ backgroundImage: `url(${effectiveBackgroundSrc})` }"
              />
              <NewVersion v-if="newVersionIsAvailable" />

              <TaskScheduler
                :forceShow="showScheduler"
                @update="(e) => (scheduler = e)"
                v-if="inContainer"
              />

              <div class="flex min-h-0 min-w-0 flex-1 flex-col">
                <NuxtPage />
              </div>

              <footer class="mt-8 border-t border-default pt-6 text-sm text-toned">
                <div class="grid gap-6 lg:grid-cols-2">
                  <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <NuxtLink
                      href="https://github.com/arabcoders/watchstate"
                      target="_blank"
                      rel="noopener noreferrer"
                      class="inline-flex items-center gap-2 font-semibold text-highlighted hover:underline underline-offset-2"
                    >
                      <UIcon name="i-lucide-github" class="size-4" aria-hidden="true" />
                      WatchState
                    </NuxtLink>
                    <span class="text-xs">{{ apiVersion || 'Loading version…' }}</span>
                  </div>
                  <dl
                    class="grid grid-cols-2 gap-4 border-t border-default pt-4 text-xs sm:grid-cols-3 lg:border-t-0 lg:border-s lg:ps-6 lg:pt-0"
                  >
                    <div class="min-w-0 space-y-1.5">
                      <dt class="text-muted">Build date</dt>
                      <dd class="wrap-anywhere text-default">{{ apiVersionDate || 'Unknown' }}</dd>
                    </div>
                    <div class="min-w-0 space-y-1.5">
                      <dt class="text-muted">Branch</dt>
                      <dd class="wrap-anywhere font-mono text-default">
                        {{ apiVersionBranch || 'Unknown' }}
                      </dd>
                    </div>
                    <div class="min-w-0 space-y-1.5">
                      <dt class="text-muted">Commit</dt>
                      <dd class="font-mono text-default">
                        <UTooltip v-if="apiVersionSha" :text="apiVersionSha">
                          <NuxtLink
                            :href="`https://github.com/arabcoders/watchstate/commit/${apiVersionSha}`"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="hover:text-primary hover:underline underline-offset-2"
                          >
                            {{ apiVersionSha.slice(0, 7) }}
                          </NuxtLink>
                        </UTooltip>
                        <span v-else>Unknown</span>
                      </dd>
                    </div>
                  </dl>
                </div>
              </footer>
            </div>

            <Dialog v-if="hasOpenedDialog" />

            <UModal
              v-model:open="showIdentitySelection"
              title="Change Identity"
              :ui="identitySelectionModalUi"
            >
              <template #body>
                <IdentitySelection
                  v-if="hasOpenedIdentitySelection"
                  :actions-target="`#${identitySelectionFooterId}`"
                  @close="() => (showIdentitySelection = false)"
                />
              </template>
              <template #footer>
                <div :id="identitySelectionFooterId" class="w-full" />
              </template>
            </UModal>

            <SettingsPanel
              v-if="hasOpenedSettings"
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
import {
  computed,
  defineAsyncComponent,
  nextTick,
  onBeforeUnmount,
  onMounted,
  readonly,
  ref,
  useId,
  watch,
} from 'vue';
import { useBreakpoints, useStorage } from '@vueuse/core';
import { navigateTo, useNuxtApp, useRoute } from '#app';
import { useColorMode, useToast } from '#imports';
import {
  NuxtLink,
  NuxtLoadingIndicator,
  NuxtPage,
  UApp,
  UButton,
  UDashboardGroup,
  UDashboardNavbar,
  UDashboardPanel,
  UDashboardSearch,
  UDashboardSidebar,
  UDashboardSidebarCollapse,
  UDashboardSidebarToggle,
  UDropdownMenu,
  UIcon,
  UModal,
  UNavigationMenu,
  UTooltip,
} from '#components';
import type { NavigationMenuItem } from '@nuxt/ui';
import type { VersionResponse } from '~/types';
import { useAuth } from '~/composables/useAuth';
import { useMediaQuery } from '~/composables/useMediaQuery';
import { usePageBackground } from '~/composables/usePageBackground';
import { useDialog } from '~/composables/useDialog';
import { getSidebarSwipeMode } from '~/utils/sidebarSwipe';
import {
  getTopLevelNavigationEntries,
  getTopLevelNavigationSections,
} from '~/utils/topLevelNavigation';
import { dEvent, parse_api_response, registerToastController, request, syncOpacity } from '~/utils';
import TaskScheduler from '~/components/TaskScheduler.vue';
import NewVersion from '~/components/NewVersion.vue';
import HeaderStatus from '~/components/HeaderStatus.vue';
import useSystemStats from '~/composables/useSystemStats';

const Dialog = defineAsyncComponent(() => import('~/components/Dialog.vue'));
const IdentitySelection = defineAsyncComponent(() => import('~/components/IdentitySelection.vue'));
const SettingsPanel = defineAsyncComponent(() => import('~/components/SettingsPanel.vue'));

type NavEntry = {
  id: string;
  label: string;
  icon: string;
  description?: string;
  matchPath?: string;
  additionalMatchPaths?: Array<string>;
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
const auth = useAuth();
const breakpoints = useBreakpoints({ mobile: 0, desktop: 640 });
const systemStats = useSystemStats();
const { pageBackgroundOverride, requestPageBackgroundReload } = usePageBackground();
const dialog = useDialog();

const bgEnable = useStorage<boolean>('bg_enable', true);
const bgOpacity = useStorage<number>('bg_opacity', 0.95);
const apiUser = useStorage<string>('api_user', 'main');
const isMobile = useMediaQuery({ query: '(max-width: 1023px)' });

const showIdentitySelection = ref(false);
const identitySelectionFooterId = useId();
const showSettings = ref(false);
const inContainer = ref(false);
const showScheduler = ref(false);
const showRouteSearch = ref(false);
const showSidebar = ref(false);
const openNavigationGroups = ref<Array<string>>([]);
const hasOpenedDialog = ref(false);
const hasOpenedIdentitySelection = ref(false);
const hasOpenedSettings = ref(false);

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
const apiVersionSha = ref<string>('');
const apiVersionDate = ref<string | undefined>();
const apiVersionBranch = ref<string | undefined>();
const bgImage = ref<{ src: string; type: string }>({ src: '', type: '' });
const loadedImages = ref<Record<string, string>>({ poster: '', background: '' });
let backgroundLoadId = 0;

const changelogUrl = computed(() => `/changelog?version=${apiVersion.value ?? ''}`);
const effectiveBackgroundSrc = computed(() => {
  if (false === bgEnable.value) {
    return '';
  }

  const src = pageBackgroundOverride.value?.src ?? bgImage.value.src;
  return 'failed' === src ? '' : src;
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

  if (isPathActive(entry.matchPath)) {
    return true;
  }

  if (entry.additionalMatchPaths?.some((path) => isPathActive(path))) {
    return true;
  }

  return false;
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
    description: entry.description,
    to: entry.to,
    href: entry.href,
    target: entry.target,
    matchPath: entry.matchPath,
    additionalMatchPaths: entry.additionalMatchPaths,
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
  'aria-label': entry.label,
  icon: entry.icon,
  to: entry.to,
  href: entry.href,
  target: entry.target,
  active: isNavigationEntryActive(entry),
  onSelect: () => {
    entry.onSelect?.();
    showSidebar.value = false;
  },
});

const sidebarItems = computed<Array<NavigationMenuItem>>(() =>
  sidebarDefinitions.value.map((section) => {
    const entries = section.items.flat();
    if (1 === entries.length && ('overview' === section.id || 'backends' === section.id)) {
      return { ...makeNavigationItem(entries[0]!), value: section.id };
    }

    const first = entries[0];
    return {
      value: section.id,
      label: section.label,
      'aria-label': section.label,
      icon:
        'configuration' === section.id
          ? 'i-lucide-settings-2'
          : 'activity' === section.id
            ? 'i-lucide-activity'
            : first?.icon,
      active: entries.some((entry) => isNavigationEntryActive(entry)),
      children: entries.map((entry) => ({
        ...makeNavigationItem(entry),
        label: 'history' === entry.id ? 'Records' : entry.label,
      })),
    };
  }),
);

watch(
  () => route.path,
  () => {
    const section = sidebarDefinitions.value.find((section) =>
      section.items.flat().some((entry) => isNavigationEntryActive(entry)),
    );
    if (section && false === openNavigationGroups.value.includes(section.id)) {
      openNavigationGroups.value = [...openNavigationGroups.value, section.id];
    }
    showSidebar.value = false;
  },
  { immediate: true },
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
  showSidebar.value = false;

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
        description: entry.description ?? entry.href ?? entry.to,
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

const accountActions = computed(() => [
  [
    {
      label: 'Preferences',
      icon: 'i-lucide-settings-2',
      onSelect: () => {
        showSettings.value = true;
      },
    },
    {
      label: true === showScheduler.value ? 'Hide scheduler status' : 'Show scheduler status',
      icon: 'i-lucide-cpu',
      disabled: false === inContainer.value,
      onSelect: () => {
        showScheduler.value = !showScheduler.value;
      },
    },
  ],
  [
    {
      label: 'Sign out',
      icon: 'i-lucide-log-out',
      onSelect: () => {
        void logout();
      },
    },
  ],
]);

const dashboardSidebarUi = {
  root: 'border-r border-default bg-muted',
  header: 'px-3 py-5',
  body: 'gap-3 px-2.5 py-3',
  footer: 'border-t border-default px-2.5 py-3',
};

const dashboardNavbarUi = {
  root: 'border-b border-default bg-default px-4 py-3 sm:px-6 lg:px-10',
  title: 'hidden text-sm font-medium text-muted md:flex',
  right: 'flex min-w-0 shrink items-center gap-0.5 sm:gap-1.5',
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
  childList: 'ms-4 border-s border-default ps-2',
  childLink: 'py-2',
});

const getVersion = async (): Promise<void> => {
  if (apiVersion.value) {
    return;
  }

  try {
    const response = await request('/system/version');
    const json = await parse_api_response<VersionResponse>(response);
    if ('error' in json) {
      throw new Error(json.error.message);
    }
    if (false === response.ok) {
      throw new Error(`Unable to load version (HTTP ${response.status}).`);
    }
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
  [bgOpacity, bgEnable],
  () => {
    syncOpacity();
  },
  { immediate: true },
);

watch(breakpoints.active(), async () => await loadImage());

watch(bgEnable, async (enabled) => {
  if (false === enabled) {
    backgroundLoadId++;
    return;
  }
  await loadImage();
});

watch(
  apiUser,
  () => {
    releaseBackgroundImages();
    void loadImage();
  },
  { flush: 'sync' },
);

const releaseBackgroundImages = (): void => {
  backgroundLoadId++;
  for (const src of Object.values(loadedImages.value)) {
    if (src) {
      URL.revokeObjectURL(src);
    }
  }
  loadedImages.value = { poster: '', background: '' };
  bgImage.value = { src: '', type: '' };
};

watch(isMobile, (v) => {
  if (true === v) {
    return;
  }

  showSidebar.value = false;
  resetSwipe();
});

watch(
  () => dialog.state.current,
  (current) => {
    if (null !== current) {
      hasOpenedDialog.value = true;
    }
  },
  { flush: 'sync', immediate: true },
);

watch(
  showIdentitySelection,
  (isOpen) => {
    if (true === isOpen) {
      hasOpenedIdentitySelection.value = true;
    }
  },
  { flush: 'sync' },
);

watch(
  showSettings,
  (isOpen) => {
    if (true === isOpen) {
      hasOpenedSettings.value = true;
    }
  },
  { flush: 'sync' },
);

const forceBackgroundReload = async (): Promise<void> => {
  if (pageBackgroundOverride.value?.id) {
    requestPageBackgroundReload(pageBackgroundOverride.value.id);
    return;
  }

  await loadImage(true);
};

const loadImage = async (force = false): Promise<void> => {
  if (false === bgEnable.value) {
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

  const loadId = ++backgroundLoadId;
  const selectedUser = apiUser.value;
  try {
    const imgRequest = await request(url, { headers: { 'X-User': selectedUser } });
    if (loadId !== backgroundLoadId || selectedUser !== apiUser.value) {
      return;
    }
    if (200 !== imgRequest.status) {
      bgImage.value = { src: 'failed', type: bgType };
      return;
    }
    const image = await imgRequest.blob();
    if (loadId !== backgroundLoadId || selectedUser !== apiUser.value) {
      return;
    }
    if (loadedImages.value[bgType]) {
      URL.revokeObjectURL(loadedImages.value[bgType]);
    }
    loadedImages.value[bgType] = URL.createObjectURL(image);
    bgImage.value = {
      src: loadedImages.value[bgType],
      type: bgType,
    };
  } catch {
    if (loadId === backgroundLoadId && selectedUser === apiUser.value) {
      bgImage.value = { src: 'failed', type: bgType };
    }
  }
};

const logout = async (): Promise<boolean> => {
  const { status } = await dialog.confirmDialog({
    title: 'Sign out?',
    message: 'End your current session?',
    confirmText: 'Sign out',
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
  systemStats.start();
  await getVersion();
  await loadImage();
});

onBeforeUnmount(() => {
  releaseBackgroundImages();
  document.removeEventListener('touchstart', handleSwipeStart, true);
  document.removeEventListener('touchmove', handleSwipeMove, true);
  document.removeEventListener('touchend', handleSwipeEnd, true);
  document.removeEventListener('touchcancel', handleSwipeCancel, true);
});
</script>
