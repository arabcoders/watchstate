import { describe, expect, it } from 'bun:test';
import lucideCollection from '@iconify-json/lucide/icons.json';
import { guideIcons } from '#build/guide-icons';

import { renderGuideIcon } from '~/utils/guideIcons';

type LucideIcon = {
  body?: string;
  parent?: string;
};

type LucideCollection = {
  icons: Record<string, LucideIcon>;
  aliases: Record<string, LucideIcon>;
};

const resolveUpstreamBody = (name: string): string | null => {
  const collection = lucideCollection as LucideCollection;
  let currentName: string | undefined = name;
  const visited = new Set<string>();

  while (currentName && !visited.has(currentName)) {
    visited.add(currentName);

    const icon = collection.icons[currentName] ?? collection.aliases[currentName];
    if (!icon) {
      return null;
    }

    if (icon.body) {
      return icon.body;
    }

    currentName = icon.parent;
  }

  return null;
};

describe('guide icons', () => {
  it('matches docs', async () => {
    const root = new URL('../../../', import.meta.url);
    const files = ['API.md', 'FAQ.md', 'NEWS.md', 'README.md'].map((file) => new URL(file, root));

    for await (const file of new Bun.Glob('guides/**/*.md').scan({ cwd: root.pathname })) {
      files.push(new URL(file, root));
    }

    const names = new Set<string>();
    for (const file of files) {
      const markdown = await Bun.file(file).text();
      for (const match of markdown.matchAll(/<!--\s*i:(i-lucide-[\w.-]+)\s*-->/gi)) {
        names.add(match[1] ?? '');
      }
    }

    expect(names.size).toBeGreaterThan(0);
    expect(Object.keys(guideIcons).sort()).toEqual(
      [...names, 'i-lucide-circle-question-mark']
        .map((value) => value.replace(/^i-lucide-/, ''))
        .sort(),
    );

    for (const value of names) {
      const name = value.replace(/^i-lucide-/, '');
      const expectedBody = resolveUpstreamBody(name);

      expect(expectedBody, value).not.toBeNull();
      expect(renderGuideIcon(value), value).toContain(expectedBody as string);
    }
  });

  it('falls back', () => {
    expect(renderGuideIcon('i-lucide-history')).toContain(resolveUpstreamBody('history') as string);
    expect(renderGuideIcon('i-lucide-unknown')).toContain(
      resolveUpstreamBody('circle-question-mark') as string,
    );
  });
});
