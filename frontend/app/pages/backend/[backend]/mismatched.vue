<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <NuxtLink to="/backends">Backends</NuxtLink>
          -
          <NuxtLink :to="'/backend/' + backend">{{ backend }}</NuxtLink>
          : Misidentified
        </span>
        <div class="is-pulled-right" v-if="hasLooked">
          <div class="field is-grouped">
            <p class="control">
              <button
                class="button is-info"
                @click="loadContent(false)"
                :disabled="isLoading"
                :class="{ 'is-loading': isLoading }"
              >
                <span class="icon"><i class="fas fa-sync" /></span>
              </button>
            </p>
          </div>
        </div>
        <div class="subtitle is-hidden-mobile">
          This page will show items that <code>WatchState</code> thinks are possibly mis-identified
          in your backend.
        </div>
      </div>

      <div class="column is-12" v-if="false === hasLooked">
        <div class="card">
          <header class="card-header">
            <p class="card-header-title is-justify-center">Request Analyze</p>
          </header>
          <div class="card-content">
            <div class="content">
              <ul>
                <li>
                  Checking the items will take time, you will see the spinner while
                  <code>WatchState</code> is analyzing the entire backend libraries content. Do not
                  reload the page.
                </li>
              </ul>
            </div>
          </div>
          <div class="control">
            <button
              class="button is-fullwidth is-primary"
              @click="() => loadContent()"
              :disabled="isLoading"
            >
              <span class="icon"><i class="fas fa-check" /></span>
              <span>Initiate The process</span>
            </button>
          </div>
        </div>
      </div>
      <div class="column is-12" v-if="items.length < 1">
        <Message
          v-if="isLoading"
          message_class="is-background-info-90 has-text-dark"
          title="Analyzing"
          icon="fas fa-spinner fa-spin"
          message="Analyzing the backend content. Please wait. It will take a while..."
        />
        <Message
          v-else-if="!isLoading && hasLooked"
          message_class="has-background-success-90 has-text-dark"
          title="Success!"
          icon="fas fa-check"
          message="WatchState did not find any possible mismatched items in the libraries we looked at."
        />
      </div>

      <template v-if="items.length > 1">
        <div class="column is-12">
          <Message
            class="has-background-warning-80 has-text-dark"
            title="Warning"
            icon="fas fa-exclamation-triangle"
            message="WatchState found some items that might be mis-identified in your backend. Please review the results."
          />
        </div>

        <div class="column is-6" v-for="item in items" :key="item.title + item.library">
          <div class="card">
            <header class="card-header">
              <p class="card-header-title is-text-overflow">
                <NuxtLink target="_blank" :to="item.webUrl" v-if="item.webUrl">{{
                  item.title
                }}</NuxtLink>
                <span v-else>{{ item.title }}</span>
              </p>
              <div class="card-header-icon" @click="item.showItem = !item.showItem">
                <span class="icon has-tooltip">
                  <i
                    class="fas fa-film"
                    :class="{ 'fa-film': 'Movie' === item.type, 'fa-tv': 'Movie' !== item.type }"
                  />
                </span>
              </div>
            </header>
            <div class="card-content">
              <div class="columns is-mobile is-multiline">
                <div class="column is-6">
                  <strong class="is-unselectable">Library:</strong> {{ item.library }}
                </div>
                <div class="column is-6 has-text-right">
                  <strong class="is-unselectable">Type:</strong> {{ item.type }}
                </div>
                <div class="column is-6">
                  <strong class="is-unselectable">Year:</strong> {{ item.year ?? '???' }}
                </div>
                <div class="column is-6 has-text-right">
                  <strong class="is-unselectable">Percent:</strong>
                  <span :class="percentColor(item.percent)"> {{ item.percent.toFixed(2) }}% </span>
                </div>
                <div
                  class="column is-12"
                  v-if="item.path"
                  @click="
                    (e: Event) =>
                      (e.target as HTMLElement)?.firstElementChild?.classList?.toggle(
                        'is-text-overflow',
                      )
                  "
                >
                  <div class="is-text-overflow">
                    <strong class="is-unselectable">Path:&nbsp;</strong>
                    <NuxtLink :to="makeSearchLink('path', item.path)">{{ item.path }}</NuxtLink>
                  </div>
                </div>
              </div>
            </div>
            <div class="card-content p-0 m-0" v-if="item?.showItem">
              <div class="mt-2" style="position: relative; max-height: 343px; overflow-y: auto">
                <code
                  class="is-terminal is-block is-pre-wrap"
                  v-text="JSON.stringify(item, null, 2)"
                />
                <button
                  class="button m-4"
                  v-tooltip="'Copy text'"
                  style="position: absolute; top: 0; right: 0"
                  @click="() => copyText(JSON.stringify(item, null, 2))"
                >
                  <span class="icon"><i class="fas fa-copy" /></span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </template>

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
              This service expects standard plex naming conventions
              <NuxtLink
                target="_blank"
                to="https://support.plex.tv/articles/naming-and-organizing-your-tv-show-files/"
              >
                for series </NuxtLink
              >, and
              <NuxtLink
                target="_blank"
                to="https://support.plex.tv/articles/naming-and-organizing-your-movie-media-files/"
              >
                for movies </NuxtLink
              >. So if you libraries doesn't follow the same conventions, you will see a lot of
              items being reported as misidentified.
            </li>
            <li>
              If you see a lot of misidentified items, you might want to check the that the source
              directory matches the item.
            </li>
            <li>
              Clicking on the icon next to the title will show you the raw data that was used to
              generate the report.
            </li>
          </ul>
        </Message>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useHead } from '#app';
import { useStorage } from '@vueuse/core';
import { makeSearchLink, notification, request, copyText, parse_api_response } from '~/utils';
import Message from '~/components/Message.vue';
import { useSessionCache } from '~/utils/cache';
import type { MismatchedItem } from '~/types';

type MismatchedItemWithUI = MismatchedItem & {
  /** UI state: Whether to show full item details */
  showItem?: boolean;
};

const route = useRoute();
const backend = route.params.backend as string;
const items = ref<Array<MismatchedItemWithUI>>([]);
const isLoading = ref<boolean>(false);
const hasLooked = ref<boolean>(false);
const show_page_tips = useStorage('show_page_tips', true);
const cache = useSessionCache();
const cacheKey = `backend-${backend}-mismatched`;

useHead({ title: `Backends: ${backend} - Misidentified items` });

const loadContent = async (useCache: boolean = true): Promise<void> => {
  hasLooked.value = true;
  isLoading.value = true;
  items.value = [];

  try {
    if (useCache && cache.has(cacheKey)) {
      const cachedData = cache.get<Array<MismatchedItemWithUI>>(cacheKey);
      if (null !== cachedData) {
        items.value = cachedData;
      }
    } else {
      const response = await request(`/backend/${backend}/mismatched`);
      const data = await parse_api_response<Array<MismatchedItemWithUI>>(response);

      if ('error' in data) {
        notification('error', 'Error', `${data.error.code}: ${data.error.message}`);
        return;
      }

      cache.set(cacheKey, data);
      items.value = data;
    }
  } catch (e) {
    hasLooked.value = false;
    const errorMessage = e instanceof Error ? e.message : 'Unknown error occurred';
    return notification('error', 'Error', errorMessage);
  } finally {
    isLoading.value = false;
  }
};

const percentColor = (percent: number): string => {
  const percentInt = parseInt(percent.toString());
  if (90 < percentInt) {
    return 'has-text-success';
  } else if (50 < percentInt && percentInt < 90) {
    return 'has-text-warning';
  } else {
    return 'has-text-danger';
  }
};
</script>
