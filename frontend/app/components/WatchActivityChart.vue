<template>
  <section aria-labelledby="watch-activity-heading" class="space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <h2
        id="watch-activity-heading"
        class="flex items-center gap-2 text-lg font-semibold text-highlighted"
      >
        <UIcon name="i-lucide-chart-column" class="size-5 text-toned" /> Last recorded watch
      </h2>
      <div role="group" aria-label="Chart period" class="flex flex-wrap gap-1">
        <UButton
          v-for="item in periods"
          :key="item.value"
          color="neutral"
          :variant="item.value === period ? 'soft' : 'outline'"
          :aria-pressed="item.value === period"
          size="sm"
          :label="item.label"
          @click="selectPeriod(item.value)"
        />
      </div>
    </div>

    <div class="ws-card min-w-0 space-y-4 p-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <p class="text-sm font-medium text-highlighted" aria-live="polite">
          {{ chart.range }} <span class="font-normal text-toned">UTC</span>
        </p>
        <div class="flex gap-1">
          <UTooltip :text="`Previous ${period}`">
            <UButton
              color="neutral"
              variant="ghost"
              size="sm"
              icon="i-lucide-chevron-left"
              :aria-label="`Previous ${period}`"
              :disabled="false === chart.hasEarlier"
              @click="offset--"
            />
          </UTooltip>
          <UTooltip :text="`Next ${period}`">
            <UButton
              color="neutral"
              variant="ghost"
              size="sm"
              icon="i-lucide-chevron-right"
              :aria-label="`Next ${period}`"
              :disabled="0 === offset"
              @click="offset++"
            />
          </UTooltip>
        </div>
      </div>
      <UEmpty
        v-if="0 === chart.movies + chart.episodes"
        size="sm"
        variant="naked"
        icon="i-lucide-chart-column"
        :title="activity.length ? 'No watch dates in this period' : 'No watch dates available'"
      />
      <template v-else>
        <figure class="space-y-4">
          <figcaption class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-toned">
            <span class="inline-flex items-center gap-2">
              <span aria-hidden="true" class="size-2.5 rounded-sm bg-primary"></span>
              {{ number.format(chart.movies) }} movies
            </span>
            <span class="inline-flex items-center gap-2">
              <span aria-hidden="true" class="size-2.5 rounded-sm bg-info"></span>
              {{ number.format(chart.episodes) }} episodes
            </span>
          </figcaption>

          <div aria-hidden="true" class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-2 gap-y-1">
            <div class="flex flex-col items-end justify-between text-xs tabular-nums text-toned">
              <span>{{ number.format(axisMax) }}</span>
              <span>{{ number.format(axisMax / 2) }}</span>
              <span>0</span>
            </div>
            <div class="relative h-40">
              <div class="absolute inset-0 flex flex-col justify-between">
                <div v-for="line in 3" :key="line" class="border-t border-muted"></div>
              </div>
              <div class="relative grid h-full gap-0.5 sm:gap-1" :style="columns">
                <div
                  v-for="bucket in chart.buckets"
                  :key="bucket.date"
                  class="mx-auto flex w-full max-w-10 flex-col justify-end"
                  :title="`${bucket.range}: ${number.format(bucket.movies)} movies, ${number.format(bucket.episodes)} episodes`"
                >
                  <div
                    class="bg-info"
                    :style="{ height: `${(bucket.episodes / axisMax) * 100}%` }"
                  ></div>
                  <div
                    class="bg-primary"
                    :style="{ height: `${(bucket.movies / axisMax) * 100}%` }"
                  ></div>
                </div>
              </div>
            </div>
            <div
              class="col-start-2 grid gap-0.5 text-center text-xs text-toned sm:gap-1"
              :style="columns"
            >
              <span v-for="(bucket, index) in chart.buckets" :key="bucket.date" class="min-w-0">
                <span
                  v-if="
                    'week' === period ||
                    0 === index ||
                    chart.buckets.length - 1 === index ||
                    (chart.buckets.length - 2 > index && 0 === index % labelInterval[period])
                  "
                  class="whitespace-nowrap"
                >
                  {{ bucket.label }}
                </span>
                <span v-else-if="'year' === period" class="hidden whitespace-nowrap sm:inline">{{
                  bucket.label
                }}</span>
              </span>
            </div>
          </div>
        </figure>

        <details class="text-sm text-toned">
          <summary class="w-fit cursor-pointer font-medium text-default">View chart data</summary>
          <table class="mt-2 w-full text-left">
            <caption class="sr-only">
              Last recorded watch,
              {{
                chart.range
              }}
              UTC
            </caption>
            <thead class="border-b border-default">
              <tr>
                <th scope="col" class="py-1">Date range</th>
                <th scope="col" class="py-1 text-right">Movies</th>
                <th scope="col" class="py-1 text-right">Episodes</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="bucket in chart.buckets"
                :key="bucket.date"
                class="border-b border-default last:border-none"
              >
                <th scope="row" class="py-1 font-normal">{{ bucket.range }}</th>
                <td class="py-1 text-right tabular-nums">{{ number.format(bucket.movies) }}</td>
                <td class="py-1 text-right tabular-nums">{{ number.format(bucket.episodes) }}</td>
              </tr>
            </tbody>
          </table>
        </details>
      </template>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { UButton, UEmpty, UIcon, UTooltip } from '#components';
import type { UserStatisticsActivityDay, WatchActivityPeriod } from '~/types';
import { buildWatchActivity } from '~/utils/watchActivity';

const props = defineProps<{ activity: ReadonlyArray<UserStatisticsActivityDay> }>();
const number = new Intl.NumberFormat();
const periods: Array<{ label: string; value: WatchActivityPeriod }> = [
  { label: 'Week', value: 'week' },
  { label: 'Month', value: 'month' },
  { label: 'Quarter', value: 'quarter' },
  { label: 'Year', value: 'year' },
];
const labelInterval: Record<WatchActivityPeriod, number> = {
  week: 1,
  month: 5,
  quarter: 4,
  year: 3,
};
const period = ref<WatchActivityPeriod>('month');
const offset = ref(0);
const chart = computed(() =>
  buildWatchActivity(props.activity, period.value, offset.value, Date.now()),
);
const axisMax = computed(() => {
  const peak = Math.max(1, ...chart.value.buckets.map((bucket) => bucket.movies + bucket.episodes));
  return 2 * Math.ceil(peak / 2);
});
const columns = computed(() => ({
  gridTemplateColumns: `repeat(${chart.value.buckets.length}, minmax(0, 1fr))`,
}));

const selectPeriod = (value: WatchActivityPeriod): void => {
  if (period.value !== value) {
    period.value = value;
    offset.value = 0;
  }
};
</script>
