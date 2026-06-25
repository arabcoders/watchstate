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
    description: 'Recent activities.',
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
    description: 'Tracked play-state changes across backends.',
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
    description: 'Inspect recent events and their status.',
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
    breadcrumbSectionLabel: 'Configuration',
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
    breadcrumbSectionLabel: 'Configuration',
    description: 'Map custom GUIDs between backends.',
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
    breadcrumbSectionLabel: 'Configuration',
    description: 'Manage user identities.',
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
    description: 'Run and inspect scheduled tasks.',
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
    description: 'Application, task, and access logs.',
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
    description: 'Run CLI commands.',
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
    description: 'Download and restore database snapshots.',
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
    description: 'Diagnostic report for submitting bugs.',
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
    description: 'Check data consistency across backends.',
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
    description: 'Verify local media files still exist.',
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
    description: 'Find duplicate history records.',
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
    description: 'Test WatchState reachability.',
    icon: 'i-lucide-link',
    to: '/url_check',
    matchPath: '/url_check',
  },
  {
    id: 'processes',
    section: 'system',
    label: 'Processes',
    pageLabel: 'Processes',
    breadcrumbSectionLabel: 'System',
    description: 'Show active processes.',
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
    description: 'Filter out noisy log entries.',
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
    description: 'Clear cached data.',
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
    description: 'Reset the application state.',
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
    description: 'Media servers OpenAPI specification.',
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
