<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <span class="icon"><i class="fas fa-database"></i></span>
          Data Parity
        </span>
        <div class="is-pulled-right">
          <div class="field is-grouped">
            <div class="control has-icons-left" v-if="showFilter">
              <input
                type="search"
                v-model.lazy="filter"
                class="input"
                id="filter"
                placeholder="Filter displayed results."
              />
              <span class="icon is-left">
                <i class="fas fa-filter"></i>
              </span>
            </div>

            <div class="control">
              <button class="button is-danger is-light" @click="toggleFilter">
                <span class="icon"><i class="fas fa-filter"></i></span>
              </button>
            </div>

            <div class="control" v-if="min && max" v-tooltip.bottom="'Minimum number of backends'">
              <div class="select">
                <select v-model="min" :disabled="isDeleting || isLoading">
                  <option v-for="i in numberRange(1, max + 1)" :key="`min-${i}`" :value="i">
                    {{ i }}
                  </option>
                </select>
              </div>
            </div>
            <p class="control">
              <button
                class="button is-danger"
                @click="deleteData"
                v-tooltip.bottom="'Delete The reported records'"
                :disabled="isDeleting || isLoading || items.length < 1"
                :class="{ 'is-loading': isDeleting }"
              >
                <span class="icon"><i class="fas fa-trash"></i></span>
              </button>
            </p>

            <div class="control">
              <button
                class="button is-info is-light"
                @click="selectAll = !selectAll"
                data-tooltip="Toggle select all"
              >
                <span class="icon">
                  <i
                    class="fas fa-check-square"
                    :class="{ 'fa-check-square': !selectAll, 'fa-square': selectAll }"
                  ></i>
                </span>
              </button>
            </div>

            <p class="control">
              <button
                class="button is-info"
                @click.prevent="loadContent(page, true, true)"
                :disabled="isLoading"
                :class="{ 'is-loading': isLoading }"
              >
                <span class="icon"><i class="fas fa-sync"></i></span>
              </button>
            </p>
          </div>
        </div>
        <div class="is-hidden-mobile">
          <span class="subtitle"
            >This page shows local database records not being reported by the specified number of
            backends.</span
          >
        </div>
      </div>

      <div class="column is-12" v-if="total && last_page > 1">
        <div class="field is-grouped">
          <div class="control" v-if="page !== 1">
            <button
              rel="first"
              class="button"
              @click="loadContent(1)"
              :disabled="isLoading"
              :class="{ 'is-loading': isLoading }"
            >
              <span>&lt;&lt;</span>
            </button>
          </div>
          <div class="control" v-if="page > 1 && page - 1 !== 1">
            <button
              rel="prev"
              class="button"
              @click="loadContent(page - 1)"
              :disabled="isLoading"
              :class="{ 'is-loading': isLoading }"
            >
              <span>&lt;</span>
            </button>
          </div>
          <div class="control">
            <div class="select">
              <select v-model="page" @change="loadContent(page)" :disabled="isLoading">
                <option
                  v-for="(item, index) in makePagination(page, last_page)"
                  :key="index"
                  :value="item.page"
                >
                  {{ item.text }}
                </option>
              </select>
            </div>
          </div>
          <div class="control" v-if="page !== last_page && page + 1 !== last_page">
            <button
              rel="next"
              class="button"
              @click="loadContent(page + 1)"
              :disabled="isLoading"
              :class="{ 'is-loading': isLoading }"
            >
              <span>&gt;</span>
            </button>
          </div>
          <div class="control" v-if="page !== last_page">
            <button
              rel="last"
              class="button"
              @click="loadContent(last_page)"
              :disabled="isLoading"
              :class="{ 'is-loading': isLoading }"
            >
              <span>&gt;&gt;</span>
            </button>
          </div>
        </div>
      </div>

      <div class="column is-12" v-if="selected_ids.length > 0">
        <div class="field is-grouped is-justify-content-center">
          <div class="control">
            <button
              class="button is-danger"
              @click="massDelete()"
              :disabled="massActionInProgress"
              :class="{ 'is-loading': massActionInProgress }"
            >
              <span class="icon"><i class="fas fa-trash"></i></span>
              <span class="is-hidden-mobile"
                >Delete '{{ selected_ids.length }}' selected item/s</span
              >
            </button>
          </div>
        </div>
      </div>

      <div class="column is-12">
        <div class="columns is-multiline" v-if="filteredRows(items)?.length > 0">
          <template v-for="item in items" :key="item.id">
            <Lazy
              :unrender="true"
              :min-height="343"
              class="column is-6-tablet"
              v-if="filterItem(item)"
            >
              <div
                class="card is-flex is-full-height is-flex-direction-column"
                :class="{ 'is-success': item.watched }"
              >
                <header class="card-header">
                  <p class="card-header-title is-text-overflow pr-1">
                    <span class="icon">
                      <label class="checkbox">
                        <input type="checkbox" :value="item.id" v-model="selected_ids" /> </label
                      >&nbsp;
                    </span>
                    <FloatingImage
                      :image="`/history/${item.id}/images/poster`"
                      :item_class="'scaled-image'"
                      v-if="poster_enable"
                    >
                      <NuxtLink :to="`/history/${item.id}`">{{ makeName(item) }}</NuxtLink>
                    </FloatingImage>
                    <NuxtLink :to="`/history/${item.id}`" v-else>{{ makeName(item) }}</NuxtLink>
                  </p>
                  <span class="card-header-icon" @click="item.showRawData = !item?.showRawData">
                    <span class="icon">
                      <i
                        class="fas"
                        :class="{
                          'fa-tv': 'episode' === item.type.toLowerCase(),
                          'fa-film': 'movie' === item.type.toLowerCase(),
                        }"
                      ></i>
                    </span>
                  </span>
                </header>
                <div class="card-content is-flex-grow-1">
                  <div class="columns is-multiline is-mobile">
                    <div class="column is-12">
                      <div class="field is-grouped">
                        <div
                          class="control is-clickable"
                          :class="{
                            'is-text-overflow': !item?.expand_title,
                            'is-text-contents': item?.expand_title,
                          }"
                          @click="item.expand_title = !item?.expand_title"
                        >
                          <span class="icon"><i class="fas fa-heading"></i>&nbsp;</span>
                          <template v-if="item?.content_title">
                            <NuxtLink :to="makeSearchLink('subtitle', item.content_title)">
                              {{ item.content_title }}
                            </NuxtLink>
                          </template>
                          <template v-else>
                            <NuxtLink :to="makeSearchLink('subtitle', item.title)">{{
                              item.title
                            }}</NuxtLink>
                          </template>
                        </div>
                        <div class="control">
                          <span
                            class="icon is-clickable"
                            @click="copyText(item?.content_title ?? item.title, false)"
                          >
                            <i class="fas fa-copy"></i
                          ></span>
                        </div>
                      </div>
                    </div>
                    <div class="column is-12">
                      <div class="field is-grouped">
                        <div
                          class="control is-clickable"
                          :class="{
                            'is-text-overflow': !item?.expand_path,
                            'is-text-contents': item?.expand_path,
                          }"
                          @click="item.expand_path = !item?.expand_path"
                        >
                          <span class="icon"><i class="fas fa-file"></i>&nbsp;</span>
                          <NuxtLink
                            v-if="item?.content_path"
                            :to="makeSearchLink('path', item.content_path)"
                          >
                            {{ item.content_path }}
                          </NuxtLink>
                          <span v-else>No path found.</span>
                        </div>
                        <div class="control">
                          <span
                            class="icon is-clickable"
                            @click="copyText(item?.content_path || '', false)"
                          >
                            <i class="fas fa-copy"></i
                          ></span>
                        </div>
                      </div>
                    </div>
                    <div class="column is-12">
                      <div class="field is-grouped">
                        <div class="control is-expanded is-unselectable">
                          <span class="icon"><i class="fas fa-info"></i>&nbsp;</span>
                          <span>Has metadata from</span>
                        </div>
                        <div class="control">
                          <NuxtLink
                            v-for="reportedBackend in item.reported_by"
                            :key="`${item.id}-rb-${reportedBackend}`"
                            :to="'/backend/' + reportedBackend"
                            class="tag is-primary ml-1"
                          >
                            {{ reportedBackend }}
                          </NuxtLink>
                          <NuxtLink
                            v-for="missingBackend in item.not_reported_by"
                            :key="`${item.id}-nrb-${missingBackend}`"
                            :to="'/backend/' + missingBackend"
                            class="tag is-danger ml-1"
                          >
                            {{ missingBackend }}
                          </NuxtLink>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="card-content p-0 m-0" v-if="item?.showRawData">
                  <pre
                    style="position: relative; max-height: 343px"
                    class="is-terminal"
                  ><code>{{ JSON.stringify(item, null, 2)
                  }}</code>
    <button class="button is-small m-4" @click="() => copyText(JSON.stringify(item, null, 2))"
      style="position: absolute; top:0; right:0;">
      <span class="icon"><i class="fas fa-copy"></i></span>
    </button>
  </pre>
                </div>
                <div class="card-footer">
                  <div class="card-footer-item">
                    <span class="icon">
                      <i
                        class="fas"
                        :class="{ 'fa-eye': item.watched, 'fa-eye-slash': !item.watched }"
                      ></i
                      >&nbsp;
                    </span>
                    <span class="has-text-success" v-if="item.watched">Played</span>
                    <span class="has-text-danger" v-else>Unplayed</span>
                  </div>
                  <div class="card-footer-item">
                    <span class="icon"><i class="fas fa-calendar"></i>&nbsp;</span>
                    <span
                      class="has-tooltip"
                      v-tooltip="
                        `Record updated at: ${moment.unix(item.updated_at).format(TOOLTIP_DATE_FORMAT)}`
                      "
                    >
                      {{ moment.unix(item.updated_at).fromNow() }}
                    </span>
                  </div>
                </div>
              </div>
            </Lazy>
          </template>
        </div>

        <div class="column is-12" v-else>
          <Message
            v-if="isLoading"
            message_class="has-background-info-90 has-text-dark"
            title="Loading"
            icon="fas fa-spinner fa-spin"
            message="Loading data. Please wait..."
          />
          <template v-else>
            <Message
              message_class="has-background-warning-80 has-text-dark"
              v-if="filter && items.length > 1"
              title="Information"
              icon="fas fa-check"
            >
              The filter <code>{{ filter }}</code> did not match any records.
            </Message>
            <Message
              message_class="has-background-success-90 has-text-dark"
              v-if="!filter || items.length < 1"
              title="Success"
              icon="fas fa-check"
            >
              WatchState did not find any records matching the criteria. All records has at least
              <code>{{ min }}</code>
              backends reporting it.
            </Message>
          </template>
        </div>

        <div class="column is-12">
          <Message
            message_class="has-background-info-90 has-text-dark"
            :toggle="show_page_tips"
            @toggle="show_page_tips = !show_page_tips"
            :use-toggle="true"
            title="Tips"
            icon="fas fa-info-circle"
          >
            <ul>
              <li>
                You can specify the minimum number of backends that need to report the record to be
                considered valid.
              </li>
              <li>
                By clicking the <span class="fa fa-trash"></span> icon you will delete the the
                reported items from the local database. If the items are not fixed by the time
                <code>import</code> is run, they will re-appear.
              </li>
              <li>
                Deleting records works by deleting everything at or below the specified number of
                backends. For example, if you set the minimum to <code>3</code>, all records that
                are reported by <code>3</code> or fewer backends will be deleted.
              </li>
              <li>
                Records showing here most likely means your backends, are not reporting same data.
                This could be due to many reasons, including using different external databases i.e.
                <code>TheMovieDB</code> vs <code>TheTVDB</code>.
              </li>
              <li>
                The results are cached in your browser temporarily to provide faster response, as
                the operation to generate the report is quite intensive. If you want to refresh the
                data, click the <span class="fa fa-sync"></span> icon.
              </li>
            </ul>
          </Message>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useHead, useRoute, useRouter } from '#app';
import { useStorage } from '@vueuse/core';
import Message from '~/components/Message.vue';
import Lazy from '~/components/Lazy.vue';
import { useSessionCache } from '~/utils/cache';
import {
  request,
  awaitElement,
  copyText,
  makeName,
  makePagination,
  makeSearchLink,
  notification,
  TOOLTIP_DATE_FORMAT,
} from '~/utils';
import moment from 'moment';
import { NuxtLink } from '#components';
import FloatingImage from '~/components/FloatingImage.vue';
import { useDialog } from '~/composables/useDialog';
import type { ParityItem, PaginatedResponse, ExpandableUIState } from '~/types';

type ParityItemWithUI = ParityItem & ExpandableUIState;

type APIResponse = PaginatedResponse<ParityItemWithUI>;

const route = useRoute();
const router = useRouter();

useHead({ title: 'Parity' });

const show_page_tips = useStorage('show_page_tips', true);
const api_user = useStorage('api_user', 'main');
const poster_enable = useStorage('poster_enable', true);

const items = ref<Array<ParityItemWithUI>>([]);
const page = ref<number>(Number(route.query.page ?? 1));
const perpage = ref<number>(Number(route.query.perpage ?? 100));
const total = ref<number>(0);
const last_page = computed<number>(() => Math.ceil(total.value / perpage.value));
const isLoading = ref<boolean>(false);
const isDeleting = ref<boolean>(false);
const filter = ref<string>(String(route.query.filter ?? ''));
const showFilter = ref<boolean>(!!filter.value);
const min = ref<number | null>(route.query.min ? Number(route.query.min) : null);
const max = ref<number>();
const cacheKey = computed<string>(() => `parity_v1-${min.value}-${page.value}-${perpage.value}`);

const selectAll = ref<boolean>(false);
const selected_ids = ref<Array<string | number>>([]);
const massActionInProgress = ref<boolean>(false);

watch(selectAll, (v) => {
  selected_ids.value = v ? filteredRows(items.value).map((i) => i.id) : [];
});

const cache = useSessionCache(api_user.value);

const toggleFilter = (): void => {
  showFilter.value = !showFilter.value;
  if (!showFilter.value) {
    filter.value = '';
    return;
  }
  awaitElement('#filter', (_, elm) => (elm as HTMLInputElement).focus());
};

const loadContent = async (
  pageNumber: number,
  fromPopState = false,
  fromReload = false,
): Promise<void> => {
  pageNumber = Number(pageNumber);
  if (isNaN(pageNumber) || pageNumber < 1) {
    pageNumber = 1;
  }

  const search = new URLSearchParams();
  search.set('perpage', String(perpage.value));
  search.set('page', String(pageNumber));
  let pageTitle = `Parity: Page #${pageNumber}`;

  if (min.value) {
    search.set('min', String(min.value));
    pageTitle += ` - Min: ${min.value}`;
  }

  if (filter.value) {
    search.set('filter', filter.value);
    pageTitle += ` - Filter: ${filter.value}`;
  }

  useHead({ title: pageTitle });

  const newUrl = window.location.pathname + '?' + search.toString();
  isLoading.value = true;
  items.value = [];

  page.value = pageNumber;

  try {
    let json;

    if (true === fromReload) {
      clearCache();
    }

    if (cache.has(cacheKey.value)) {
      json = cache.get(cacheKey.value) as APIResponse;
    } else {
      const response = await request(`/system/parity/?${search.toString()}`);
      json = await parse_api_response<APIResponse>(response);

      if ('parity' !== useRoute().name) {
        return;
      }

      if ('error' in json) {
        notification(
          'error',
          'Error',
          `API Error. ${json.error?.code ?? ''}: ${json.error?.message ?? ''}`,
        );
        return;
      }

      cache.set(cacheKey.value, json);
    }

    if (!fromPopState && window.location.href !== newUrl) {
      await router.push({ path: '/parity', query: Object.fromEntries(search) });
    }

    if ('paging' in json) {
      page.value = json.paging.current_page;
      perpage.value = json.paging.perpage;
      total.value = json.paging.total;
    } else {
      page.value = 1;
      total.value = 0;
    }

    if (json.items) {
      items.value = json.items;
    }
  } catch (e: any) {
    notification('error', 'Error', `Request error. ${e.message}`);
  } finally {
    isLoading.value = false;
    selectAll.value = false;
    selected_ids.value = [];
  }
};

const massDelete = async (): Promise<void> => {
  if (0 === selected_ids.value.length) {
    return;
  }

  const { status: confirmStatus } = await useDialog().confirmDialog({
    title: 'Confirm Deletion',
    message: `Delete '${selected_ids.value.length}' item/s?`,
    confirmColor: 'is-danger',
  });

  if (true !== confirmStatus) {
    return;
  }

  try {
    massActionInProgress.value = true;
    const urls = selected_ids.value.map((id) => `/history/${id}`);

    notification(
      'success',
      'Action in progress',
      `Deleting '${urls.length}' item/s. Please wait...`,
    );

    const requests = await Promise.all(urls.map((url) => request(url, { method: 'DELETE' })));

    if (!requests.every((response: any) => 200 === response.status)) {
      notification(
        'error',
        'Error',
        `Some requests failed. Please check the console for more details.`,
      );
    } else {
      items.value = items.value.filter((i) => !selected_ids.value.includes(i.id));
      try {
        cache.remove(cacheKey.value);
      } catch {}
    }

    notification('success', 'Success', `Deleting '${urls.length}' item/s completed.`);
  } catch (e: any) {
    notification('error', 'Error', `Request error. ${e.message}`);
  } finally {
    massActionInProgress.value = false;
    selected_ids.value = [];
    selectAll.value = false;
  }
};

const deleteData = async (): Promise<void> => {
  if (isDeleting.value) {
    return;
  }

  if (!min.value) {
    notification('error', 'Error', 'Minimum number of backends is not set.');
    return;
  }

  if (items.value.length < 1) {
    notification('error', 'Error', 'There are no reported records to delete.');
    return;
  }

  const { status: confirmStatus } = await useDialog().confirmDialog({
    title: 'Confirm Deletion',
    message: `Delete all reported records?`,
    confirmColor: 'is-danger',
  });

  if (true !== confirmStatus) {
    return;
  }

  isDeleting.value = true;

  try {
    const response = await request(`/system/parity`, {
      method: 'DELETE',
      body: JSON.stringify({ min: min.value }),
    });

    const json = await response.json();
    if (response.status !== 200) {
      notification('error', 'Error', `${json.error?.code ?? ''}: ${json.error?.message ?? ''}`);
      return;
    }

    notification('success', 'Success!', `Deleted '${json.deleted_records ?? 0}' records.`);

    items.value = [];
    total.value = 0;
    filter.value = '';
    page.value = 1;

    clearCache();
  } catch (e: any) {
    notification('error', 'Error', e.message);
  } finally {
    isDeleting.value = false;
  }
};

onMounted(async () => {
  const response = await request(`/backends/`);
  const json: Array<string> = await response.json();
  cache.setNameSpace(api_user.value);

  max.value = json.length;

  if (null === min.value) {
    min.value = json.length;
  } else {
    await loadContent(page.value ?? 1);
  }

  window.addEventListener('popstate', stateCallBack);
});

onBeforeUnmount(() => window.removeEventListener('popstate', stateCallBack));

const numberRange = (start: number, end: number): Array<number> =>
  new Array(end - start).fill(0).map((_, i) => i + start);

const filteredRows = (items: Array<ParityItemWithUI>): Array<ParityItemWithUI> => {
  if (!filter.value) {
    return items;
  }

  return items.filter((i) =>
    Object.values(i).some((v) =>
      typeof v === 'string' ? v.toLowerCase().includes(filter.value.toLowerCase()) : false,
    ),
  );
};

const filterItem = (item: ParityItemWithUI): boolean => {
  if (!filter.value || !item) {
    return true;
  }

  return Object.values(item).some((v) =>
    typeof v === 'string' ? v.toLowerCase().includes(filter.value.toLowerCase()) : false,
  );
};

watch(min, async () => await loadContent(page.value ?? 1));
watch(filter, (val) => {
  if (!val) {
    if (!route?.query['filter']) {
      return;
    }

    router.push({ path: '/parity', query: { ...route.query, filter: undefined } });
    return;
  }

  if (route?.query['filter'] === val) {
    return;
  }

  router.push({ path: '/parity', query: { ...route.query, filter: val } });
});

const clearCache = (): void => {
  cache.clear((k: string) => k.startsWith(`${api_user.value}:parity`));
};

const stateCallBack = async (e: PopStateEvent): Promise<void> => {
  if (!e.state && !(e as any).detail) {
    return;
  }

  const route = useRoute();
  page.value = Number(route.query.page ?? 1);
  perpage.value = Number(route.query.perpage ?? 50);
  filter.value = String(route.query.filter ?? '');
  if (filter.value) {
    showFilter.value = true;
  }
  await loadContent(page.value, true);
};
</script>
