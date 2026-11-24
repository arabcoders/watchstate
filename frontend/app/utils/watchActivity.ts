import moment from 'moment';
import type { UserStatisticsActivityDay, WatchActivityPeriod } from '~/types';

export const buildWatchActivity = (
  activity: ReadonlyArray<UserStatisticsActivityDay>,
  period: WatchActivityPeriod,
  offset: number,
  now: number,
) => {
  const start = moment
    .utc(now)
    .startOf('week' === period ? 'isoWeek' : period)
    .add(offset, period);
  const end = start.clone().add(1, period);
  const counts = new Map(activity.map((day) => [day.date, day]));
  const buckets = [];
  let movies = 0;
  let episodes = 0;

  for (let cursor = start.clone(); cursor.isBefore(end);) {
    const boundary = cursor.clone();
    if ('quarter' === period) {
      boundary.startOf('isoWeek').add(1, 'week');
    } else {
      boundary.add(1, 'year' === period ? 'month' : 'day');
    }

    const stop = moment.min(boundary, end);
    const last = stop.clone().subtract(1, 'day');
    const date = cursor.format('YYYY-MM-DD');
    const bucket = {
      date,
      label: cursor.format(
        'week' === period
          ? 'ddd'
          : 'month' === period
            ? 'D'
            : 'quarter' === period
              ? 'MMM D'
              : 'MMM',
      ),
      range: cursor.isSame(last, 'day')
        ? cursor.format('MMM D')
        : `${cursor.format('MMM D')} to ${last.format('MMM D')}`,
      movies: 0,
      episodes: 0,
    };
    for (const day = cursor.clone(); day.isBefore(stop); day.add(1, 'day')) {
      const count = counts.get(day.format('YYYY-MM-DD'));
      bucket.movies += count?.movies ?? 0;
      bucket.episodes += count?.episodes ?? 0;
    }

    buckets.push(bucket);
    movies += bucket.movies;
    episodes += bucket.episodes;
    cursor = stop;
  }

  return {
    buckets,
    movies,
    episodes,
    range: `${start.format('MMM D, YYYY')} to ${end.clone().subtract(1, 'day').format('MMM D, YYYY')}`,
    hasEarlier: undefined !== activity[0] && activity[0].date < start.format('YYYY-MM-DD'),
  };
};
