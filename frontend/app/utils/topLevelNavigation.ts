type TopLevelSectionId =
  | 'overview'
  | 'history'
  | 'backends'
  | 'activity'
  | 'configuration'
  | 'help';

type TopLevelEntryId =
  | 'home'
  | 'backends'
  | 'history'
  | 'user-statistics'
  | 'events'
  | 'transport-queue'
  | 'tasks'
  | 'logs'
  | 'console'
  | 'processes'
  | 'backup'
  | 'report'
  | 'media-health'
  | 'backend-report'
  | 'url-check'
  | 'env'
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
  description?: string;
  icon: string;
  to?: string;
  href?: string;
  target?: string;
  matchPath?: string;
  additionalMatchPaths?: Array<string>;
  exactMatch?: boolean;
  excludeMatchPaths?: Array<string>;
  visible?: (context: TopLevelNavigationContext) => boolean;
};

export type TopLevelNavigationEntry = TopLevelNavigationDefinition & {
  sectionLabel: string;
};

const TOP_LEVEL_SECTIONS: Array<TopLevelSection> = [
  { id: 'overview', label: 'Overview' },
  { id: 'history', label: 'History' },
  { id: 'backends', label: 'Backends' },
  { id: 'activity', label: 'Activity' },
  { id: 'configuration', label: 'Settings' },
  { id: 'help', label: 'Help' },
];

const TOP_LEVEL_NAVIGATION: Array<TopLevelNavigationDefinition> = [
  {
    id: 'home',
    section: 'overview',
    label: 'Overview',
    pageLabel: 'Overview',
    breadcrumbSectionLabel: 'Overview',
    description: 'Recent watch-history updates & background activity.',
    icon: 'i-lucide-house',
    to: '/',
    matchPath: '/',
  },
  {
    id: 'history',
    section: 'history',
    label: 'History',
    pageLabel: 'History',
    breadcrumbSectionLabel: 'History',
    description: 'Find movies & episodes, change watched status, or export records.',
    icon: 'i-lucide-history',
    to: '/history',
    matchPath: '/history',
  },
  {
    id: 'user-statistics',
    section: 'history',
    label: 'Watch journey',
    pageLabel: 'User Statistics',
    icon: 'i-lucide-chart-no-axes-combined',
    to: '/user_statistics',
    matchPath: '/user_statistics',
  },
  {
    id: 'events',
    section: 'activity',
    label: 'Events',
    pageLabel: 'Events',
    breadcrumbSectionLabel: 'Activity',
    description: 'Inspect recent events and their status.',
    icon: 'i-lucide-calendar-days',
    to: '/events',
    matchPath: '/events',
  },
  {
    id: 'transport-queue',
    section: 'activity',
    label: 'Transport',
    pageLabel: 'Transport Queue',
    breadcrumbSectionLabel: 'Activity',
    description: 'Inspect queued events and their delivery status.',
    icon: 'i-lucide-inbox',
    to: '/transport/queue',
    matchPath: '/transport/queue',
  },
  {
    id: 'backends',
    section: 'backends',
    label: 'Backends',
    pageLabel: 'Backends',
    breadcrumbSectionLabel: 'Backends',
    description: 'Manage backend connections.',
    icon: 'i-lucide-server',
    to: '/backends',
    matchPath: '/backends',
    additionalMatchPaths: ['/backend'],
  },
  {
    id: 'env',
    section: 'configuration',
    label: 'Environment',
    pageLabel: 'Environment',
    breadcrumbSectionLabel: 'Settings',
    description: 'Edit configuration variables.',
    icon: 'i-lucide-sliders-horizontal',
    to: '/env',
    matchPath: '/env',
  },
  {
    id: 'custom',
    section: 'configuration',
    label: 'Custom GUIDs',
    pageLabel: 'Custom GUIDs',
    breadcrumbSectionLabel: 'Settings',
    description: 'Support additional metadata providers.',
    icon: 'i-lucide-map',
    to: '/custom',
    matchPath: '/custom',
  },
  {
    id: 'ignore',
    section: 'configuration',
    label: 'Ignore Rules',
    pageLabel: 'Ignore Rules',
    breadcrumbSectionLabel: 'Settings',
    description: 'Exclude specific GUIDs from processing.',
    icon: 'i-lucide-ban',
    to: '/ignore',
    matchPath: '/ignore',
  },
  {
    id: 'identities',
    section: 'configuration',
    label: 'Identities',
    pageLabel: 'Identities',
    breadcrumbSectionLabel: 'Settings',
    description: 'Manage user identities.',
    icon: 'i-lucide-users',
    to: '/identities',
    matchPath: '/identities',
  },
  {
    id: 'tasks',
    section: 'activity',
    label: 'Tasks',
    pageLabel: 'Tasks',
    breadcrumbSectionLabel: 'Activity',
    description: 'Run and inspect scheduled tasks.',
    icon: 'i-lucide-list-checks',
    to: '/tasks',
    matchPath: '/tasks',
  },
  {
    id: 'logs',
    section: 'activity',
    label: 'Logs',
    pageLabel: 'Logs',
    breadcrumbSectionLabel: 'Activity',
    description: 'Application, task, and access logs.',
    icon: 'i-lucide-scroll-text',
    to: '/logs',
    matchPath: '/logs',
  },
  {
    id: 'console',
    section: 'activity',
    label: 'Console',
    pageLabel: 'Console',
    breadcrumbSectionLabel: 'Activity',
    description: 'Run CLI commands.',
    icon: 'i-lucide-terminal',
    to: '/console',
    matchPath: '/console',
  },
  {
    id: 'backup',
    section: 'configuration',
    label: 'Backups',
    pageLabel: 'Backups',
    breadcrumbSectionLabel: 'Settings',
    description: 'Download play-state backups or prepare a restore to a media backend.',
    icon: 'i-lucide-hard-drive-download',
    to: '/backup',
    matchPath: '/backup',
  },
  {
    id: 'report',
    section: 'activity',
    label: 'System Report',
    pageLabel: 'System Report',
    breadcrumbSectionLabel: 'Activity',
    description: 'Diagnostic report for submitting bugs.',
    icon: 'i-lucide-flag',
    to: '/report',
    matchPath: '/report',
  },
  {
    id: 'media-health',
    section: 'activity',
    label: 'Media Health',
    pageLabel: 'Media Health',
    breadcrumbSectionLabel: 'Activity',
    description: 'Find unhealthy media records.',
    icon: 'i-lucide-radar',
    to: '/media_health',
    matchPath: '/media_health',
  },
  {
    id: 'backend-report',
    section: 'activity',
    label: 'Backend Report',
    pageLabel: 'Backend Report',
    breadcrumbSectionLabel: 'Activity',
    description: 'Compare local media totals by backend and library.',
    icon: 'i-lucide-chart-no-axes-combined',
    to: '/backend_report',
    matchPath: '/backend_report',
  },
  {
    id: 'url-check',
    section: 'activity',
    label: 'URL Checker',
    pageLabel: 'URL Checker',
    breadcrumbSectionLabel: 'Activity',
    description: 'Check backend connectivity from WatchState.',
    icon: 'i-lucide-link',
    to: '/url_check',
    matchPath: '/url_check',
  },
  {
    id: 'processes',
    section: 'activity',
    label: 'Processes',
    pageLabel: 'Processes',
    breadcrumbSectionLabel: 'Activity',
    description: 'Show active processes.',
    icon: 'i-lucide-cpu',
    to: '/processes',
    matchPath: '/processes',
  },
  {
    id: 'suppression',
    section: 'configuration',
    label: 'Suppression',
    pageLabel: 'Suppression',
    breadcrumbSectionLabel: 'Settings',
    description: 'Filter out noisy log entries.',
    icon: 'i-lucide-bug-off',
    to: '/suppression',
    matchPath: '/suppression',
  },
  {
    id: 'purge-cache',
    section: 'configuration',
    label: 'Purge Cache',
    pageLabel: 'Purge Cache',
    breadcrumbSectionLabel: 'Settings',
    description: 'Clear cached data.',
    icon: 'i-lucide-trash-2',
    to: '/purge_cache',
    matchPath: '/purge_cache',
  },
  {
    id: 'reset',
    section: 'configuration',
    label: 'Reset',
    pageLabel: 'Reset',
    breadcrumbSectionLabel: 'Settings',
    description: 'Reset the application state.',
    icon: 'i-lucide-rotate-ccw',
    to: '/reset',
    matchPath: '/reset',
  },
  {
    id: 'prune',
    section: 'configuration',
    label: 'Prune',
    pageLabel: 'Prune',
    breadcrumbSectionLabel: 'Settings',
    description: 'Remove old or orphaned records.',
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
    description: 'User guides and documentation.',
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
    description: 'WatchState API documentation.',
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
    description: 'Plex, Jellyfin, and Emby API specifications.',
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
    description: 'Project overview and setup instructions.',
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
    description: 'Frequently asked questions.',
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
    description: 'Project news history.',
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
    description: 'Application changelog.',
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
    description: 'Project source code and issue tracker.',
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
      description: string;
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
    description: entry.description ?? '',
  };
};

export const requireTopLevelPageShell = (
  id: TopLevelEntryId,
  context: TopLevelNavigationContext = {},
): {
  icon: string;
  sectionLabel: string;
  pageLabel: string;
  description: string;
} => {
  const shell = getTopLevelPageShell(id, context);

  if (!shell) {
    throw new Error(`Missing top-level navigation shell for '${id}'`);
  }

  return shell;
};
