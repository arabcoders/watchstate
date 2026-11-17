import type { MediaHealthItem } from '~/types';
import { makeGUIDLink, makeSearchLink } from '~/utils';

export const mediaHealthGuidLink = (item: MediaHealthItem, key: string, value: string): string => {
  const source = key.replace(/^guid_/, '');
  const externalLink = makeGUIDLink(item.type, source, value);
  if ('' !== externalLink) {
    return externalLink;
  }

  return makeSearchLink('guid', `${source}://${value}`);
};
