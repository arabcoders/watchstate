type TopLevelSectionId =
  | 'overview'
  | 'activity'
  | 'configuration'
  | 'operations'
  | 'diagnostics'
  | 'system'
  | 'help';

type TopLevelEntryId =
  | 'home'
  | 'backends'
  | 'history'
  | 'events'
  | 'tasks'
  | 'logs'
  | 'console'
  | 'processes'
  | 'backup'
  | 'report'
  | 'parity'
  | 'integrity'
  | 'duplicate'
  | 'url-check'
  | 'env'
  | 'plex-token'
  | 'custom'
  | 'ignore'
  | 'suppression'
  | 'identities'
  | 'purge-cache'
  | 'reset'
  | 'prune'
  | 'help'
  | 'api'
  | 'openapi'
  | 'readme'
  | 'faq'
  | 'news'
  | 'changelog'
  | 'github';

type TopLevelSection = {
  id: TopLevelSectionId;
  label: string;
};

type TopLevelNavigationContext = {
  apiUser?: string | null;
  changelogUrl?: string;
};

type TopLevelNavigationDefinition = {
  id: TopLevelEntryId;
  section: TopLevelSectionId;
  label: string;
  pageLabel?: string;
  breadcrumbSectionLabel?: string;
  icon: string;
  to?: string;
  href?: string;
  target?: string;
  matchPath?: string;
  exactMatch?: boolean;
  excludeMatchPaths?: Array<string>;
  visible?: (context: TopLevelNavigationContext) => boolean;
};

export type TopLevelNavigationEntry = TopLevelNavigationDefinition & {
  sectionLabel: string;
};

const TOP_LEVEL_SECTIONS: Array<TopLevelSection> = [
  { id: 'overview', label: 'Overview' },
  { id: 'activity', label: 'Activity' },
  { id: 'configuration', label: 'Configuration' },
  { id: 'operations', label: 'Operations' },
  { id: 'diagnostics', label: 'Diagnostics' },
  { id: 'system', label: 'System' },
  { id: 'help', label: 'Help' },
];

const TOP_LEVEL_NAVIGATION: Array<TopLevelNavigationDefinition> = [
  {
    id: 'home',
    section: 'overview',
    label: 'Home',
    pageLabel: 'Home',
    breadcrumbSectionLabel: 'Overview',
    icon: 'i-lucide-house',
    to: '/',
    matchPath: '/',
  },
  {
    id: 'history',
    section: 'activity',
    label: 'History',
    pageLabel: 'History',
    breadcrumbSectionLabel: 'Activity',
    icon: 'i-lucide-history',
    to: '/history',
    matchPath: '/history',
  },
  {
    id: 'events',
    section: 'activity',
    label: 'Events',
    pageLabel: 'Events',
    breadcrumbSectionLabel: 'Activity',
    icon: 'i-lucide-calendar-days',
    to: '/events',
    matchPath: '/events',
  },
  {
    id: 'backends',
    section: 'configuration',
    label: 'Backends',
    pageLabel: 'Backends',
    breadcrumbSectionLabel: 'Configuration',
    icon: 'i-lucide-server',
    to: '/backends',
    matchPath: '/backends',
  },
  {
    id: 'env',
    section: 'configuration',
    label: 'Environment',
    pageLabel: 'Environment',
    breadcrumbSectionLabel: 'Configuration',
    icon: 'i-lucide-sliders-horizontal',
    to: '/env',
    matchPath: '/env',
  },
  {
    id: 'custom',
    section: 'configuration',
    label: 'Custom GUIDs',
    pageLabel: 'Custom GUIDs',
    breadcrumbSectionLabel: 'Configuration',
    icon: 'i-lucide-map',
    to: '/custom',
    matchPath: '/custom',
  },
  {
    id: 'ignore',
    section: 'configuration',
    label: 'Ignore Rules',
    pageLabel: 'Ignore Rules',
    breadcrumbSectionLabel: 'Configuration',
    icon: 'i-lucide-ban',
    to: '/ignore',
    matchPath: '/ignore',
  },
  {
    id: 'identities',
    section: 'configuration',
    label: 'Identities',
    pageLabel: 'Identities',
    breadcrumbSectionLabel: 'Configuration',
    icon: 'i-lucide-users',
    to: '/identities',
    matchPath: '/identities',
  },
  {
    id: 'tasks',
    section: 'operations',
    label: 'Tasks',
    pageLabel: 'Tasks',
    breadcrumbSectionLabel: 'Operations',
    icon: 'i-lucide-list-checks',
    to: '/tasks',
    matchPath: '/tasks',
  },
  {
    id: 'logs',
    section: 'operations',
    label: 'Logs',
    pageLabel: 'Logs',
    breadcrumbSectionLabel: 'Operations',
    icon: 'i-lucide-scroll-text',
    to: '/logs',
    matchPath: '/logs',
  },
  {
    id: 'console',
    section: 'operations',
    label: 'Console',
    pageLabel: 'Console',
    breadcrumbSectionLabel: 'Operations',
    icon: 'i-lucide-terminal',
    to: '/console',
    matchPath: '/console',
  },
  {
    id: 'backup',
    section: 'operations',
    label: 'Backups',
    pageLabel: 'Backups',
    breadcrumbSectionLabel: 'Operations',
    icon: 'i-lucide-hard-drive-download',
    to: '/backup',
    matchPath: '/backup',
  },
  {
    id: 'report',
    section: 'diagnostics',
    label: 'System Report',
    pageLabel: 'System Report',
    breadcrumbSectionLabel: 'Diagnostics',
    icon: 'i-lucide-flag',
    to: '/report',
    matchPath: '/report',
  },
  {
    id: 'parity',
    section: 'diagnostics',
    label: 'Data Parity',
    pageLabel: 'Data Parity',
    breadcrumbSectionLabel: 'Diagnostics',
    icon: 'i-lucide-database',
    to: '/parity',
    matchPath: '/parity',
  },
  {
    id: 'integrity',
    section: 'diagnostics',
    label: 'Files Integrity',
    pageLabel: 'Files Integrity',
    breadcrumbSectionLabel: 'Diagnostics',
    icon: 'i-lucide-file-check-2',
    to: '/integrity',
    matchPath: '/integrity',
  },
  {
    id: 'duplicate',
    section: 'diagnostics',
    label: 'Duplicate Refs',
    pageLabel: 'Duplicate Refs',
    breadcrumbSectionLabel: 'Diagnostics',
    icon: 'i-lucide-copy',
    to: '/duplicate',
    matchPath: '/duplicate',
  },
  {
    id: 'url-check',
    section: 'diagnostics',
    label: 'URL Checker',
    pageLabel: 'URL Checker',
    breadcrumbSectionLabel: 'Diagnostics',
    icon: 'i-lucide-link',
    to: '/url_check',
    matchPath: '/url_check',
  },
  {
    id: 'plex-token',
    section: 'diagnostics',
    label: 'Plex Token',
    pageLabel: 'Plex Token',
    breadcrumbSectionLabel: 'Diagnostics',
    icon: 'i-lucide-key-round',
    to: '/tools/plex_token',
    matchPath: '/tools/plex_token',
  },
  {
    id: 'processes',
    section: 'system',
    label: 'Processes',
    pageLabel: 'Processes',
    breadcrumbSectionLabel: 'System',
    icon: 'i-lucide-cpu',
    to: '/processes',
    matchPath: '/processes',
  },
  {
    id: 'suppression',
    section: 'system',
    label: 'Suppression',
    pageLabel: 'Suppression',
    breadcrumbSectionLabel: 'System',
    icon: 'i-lucide-bug-off',
    to: '/suppression',
    matchPath: '/suppression',
  },
  {
    id: 'purge-cache',
    section: 'system',
    label: 'Purge Cache',
    pageLabel: 'Purge Cache',
    breadcrumbSectionLabel: 'System',
    icon: 'i-lucide-trash-2',
    to: '/purge_cache',
    matchPath: '/purge_cache',
  },
  {
    id: 'reset',
    section: 'system',
    label: 'Reset',
    pageLabel: 'Reset',
    breadcrumbSectionLabel: 'System',
    icon: 'i-lucide-rotate-ccw',
    to: '/reset',
    matchPath: '/reset',
  },
  {
    id: 'prune',
    section: 'system',
    label: 'Prune',
    pageLabel: 'Prune',
    breadcrumbSectionLabel: 'System',
    icon: 'i-lucide-scissors',
    to: '/prune',
    matchPath: '/prune',
  },
  {
    id: 'help',
    section: 'help',
    label: 'Guides',
    pageLabel: 'Guides',
    breadcrumbSectionLabel: 'Help',
    icon: 'i-lucide-circle-help',
    to: '/help',
    matchPath: '/help',
    excludeMatchPaths: ['/help/api', '/help/openapi', '/help/readme', '/help/faq', '/help/news'],
  },
  {
    id: 'api',
    section: 'help',
    label: 'API',
    pageLabel: 'API',
    breadcrumbSectionLabel: 'Help',
    icon: 'i-lucide-book-open',
    to: '/help/api',
    matchPath: '/help/api',
  },
  {
    id: 'openapi',
    section: 'help',
    label: 'OpenAPI',
    pageLabel: 'OpenAPI',
    breadcrumbSectionLabel: 'Help',
    icon: 'i-lucide-braces',
    to: '/help/openapi',
    matchPath: '/help/openapi',
  },
  {
    id: 'readme',
    section: 'help',
    label: 'README',
    pageLabel: 'README',
    breadcrumbSectionLabel: 'Help',
    icon: 'i-lucide-file-text',
    to: '/help/readme',
    matchPath: '/help/readme',
  },
  {
    id: 'faq',
    section: 'help',
    label: 'FAQ',
    pageLabel: 'FAQ',
    breadcrumbSectionLabel: 'Help',
    icon: 'i-lucide-circle-help',
    to: '/help/faq',
    matchPath: '/help/faq',
  },
  {
    id: 'news',
    section: 'help',
    label: 'News',
    pageLabel: 'News',
    breadcrumbSectionLabel: 'Help',
    icon: 'i-lucide-newspaper',
    to: '/help/news',
    matchPath: '/help/news',
  },
  {
    id: 'changelog',
    section: 'help',
    label: 'Changelog',
    pageLabel: 'Changelog',
    breadcrumbSectionLabel: 'Help',
    icon: 'i-lucide-scroll-text',
    to: '/changelog',
    matchPath: '/changelog',
  },
  {
    id: 'github',
    section: 'help',
    label: 'GitHub',
    pageLabel: 'GitHub',
    breadcrumbSectionLabel: 'Help',
    icon: 'i-lucide-github',
    href: 'https://github.com/arabcoders/watchstate',
    target: '_blank',
  },
];

const getSectionLabel = (sectionId: TopLevelSectionId): string => {
  const section = TOP_LEVEL_SECTIONS.find((item) => item.id === sectionId);
  return section?.label ?? sectionId;
};

const resolveEntry = (
  entry: TopLevelNavigationDefinition,
  context: TopLevelNavigationContext,
): TopLevelNavigationEntry => ({
  ...entry,
  sectionLabel: getSectionLabel(entry.section),
  to: 'changelog' === entry.id ? (context.changelogUrl ?? entry.to) : entry.to,
});

const isEntryVisible = (
  entry: TopLevelNavigationDefinition,
  context: TopLevelNavigationContext,
): boolean => {
  return entry.visible ? entry.visible(context) : true;
};

export const getTopLevelNavigationEntries = (
  context: TopLevelNavigationContext = {},
): Array<TopLevelNavigationEntry> => {
  return TOP_LEVEL_NAVIGATION.filter((entry) => isEntryVisible(entry, context)).map((entry) =>
    resolveEntry(entry, context),
  );
};

export const getTopLevelNavigationSections = (): Array<TopLevelSection> => {
  return TOP_LEVEL_SECTIONS;
};

export const getTopLevelNavigationEntryById = (
  id: TopLevelEntryId,
  context: TopLevelNavigationContext = {},
): TopLevelNavigationEntry | undefined => {
  return getTopLevelNavigationEntries(context).find((entry) => entry.id === id);
};

export const getTopLevelPageShell = (
  id: TopLevelEntryId,
  context: TopLevelNavigationContext = {},
):
  | {
      icon: string;
      sectionLabel: string;
      pageLabel: string;
    }
  | undefined => {
  const entry = getTopLevelNavigationEntryById(id, context);
  if (!entry) {
    return undefined;
  }

  return {
    icon: entry.icon,
    sectionLabel: entry.breadcrumbSectionLabel ?? entry.sectionLabel,
    pageLabel: entry.pageLabel ?? entry.label,
  };
};

export const requireTopLevelPageShell = (
  id: TopLevelEntryId,
  context: TopLevelNavigationContext = {},
): {
  icon: string;
  sectionLabel: string;
  pageLabel: string;
} => {
  const shell = getTopLevelPageShell(id, context);

  if (!shell) {
    throw new Error(`Missing top-level navigation shell for '${id}'`);
  }

  return shell;
};
