<template>
  <div>
    <div class="columns is-multiline">
      <div class="column is-12 is-clearfix is-unselectable">
        <span class="title is-4">
          <NuxtLink to="/backends">Backends</NuxtLink>
          -
          <NuxtLink :to="'/backend/' + backend">{{ backend }}</NuxtLink>
          : Sessions
        </span>

        <div class="is-pulled-right">
          <div class="field is-grouped">
            <p class="control">
              <button
                class="button is-info"
                @click="loadContent"
                :disabled="isLoading"
                :class="{ 'is-loading': isLoading }"
              >
                <span class="icon"><i class="fas fa-sync"></i></span>
              </button>
            </p>
          </div>
        </div>

        <div class="subtitle is-hidden-mobile">
          Show backend's sessions that are currently active.
        </div>
      </div>

      <div class="column is-12" v-if="1 > items.length">
        <Message
          message_class="is-background-info-90 has-text-dark"
          title="Loading"
          icon="fas fa-spinner fa-spin"
          v-if="isLoading"
          message="Requesting active play sessions. Please wait..."
        />
        <Message
          v-else
          message_class="has-background-success-90 has-text-dark"
          title="Information"
          icon="fa fa-info-circle"
          message="There are no active play sessions currently running."
        />
      </div>
      <template v-else>
        <div class="column is-12">
          <div class="content">
            <h1 class="title is-4">Active Sessions</h1>
          </div>
        </div>
        <div class="column is-12">
          <table class="table is-fullwidth is-hoverable is-striped">
            <thead>
              <tr>
                <th>User</th>
                <th>Title</th>
                <th>State</th>
                <th>Progress at</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in items" :key="item.id">
                <td>{{ item.user_name }}</td>
                <td>
                  <NuxtLink :to="makeItemLink(item)">{{ item.item_title }}</NuxtLink>
                </td>
                <td>{{ item.session_state }}</td>
                <td>{{ formatDuration(item.item_offset_at) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRoute } from '#app';
import { formatDuration, notification, request, parse_api_response } from '~/utils';
import Message from '~/components/Message.vue';
import type { SessionItem } from '~/types';

const backend = useRoute().params.backend as string;
const items = ref<Array<SessionItem>>([]);
const isLoading = ref<boolean>(false);

const loadContent = async (): Promise<void> => {
  try {
    isLoading.value = true;
    items.value = [];

    const response = await request(`/backend/${backend}/sessions`);
    const data = await parse_api_response<Array<SessionItem>>(response);

    if ('error' in data) {
      notification('error', 'Error', `${data.error.code}: ${data.error.message}`);
      return;
    }

    items.value = data;
  } catch (e) {
    return notification(
      'error',
      'Error',
      e instanceof Error ? e.message : 'Unknown error occurred',
    );
  } finally {
    isLoading.value = false;
  }
};

const makeItemLink = (item: SessionItem): string => {
  const params = new URLSearchParams();
  params.append('perpage', '50');
  params.append('page', '1');
  params.append('q', `${backend}.id://${item.item_id}`);
  params.append('key', 'metadata');

  return `/history?${params.toString()}`;
};

onMounted(async () => await loadContent());
</script>
