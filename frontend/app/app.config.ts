const floatingSurface =
  'ws-floating-surface rounded-lg bg-(--ws-floating-bg) shadow-(--ws-floating-shadow) ring-0';
const overlayBody = 'p-4 sm:p-5';

export default defineAppConfig({
  ui: {
    primary: 'indigo',
    colors: {
      primary: 'indigo',
      secondary: 'amber',
      success: 'emerald',
      neutral: 'stone',
    },
    formField: {
      slots: {
        label: 'block font-medium text-default',
      },
    },
    empty: {
      variants: {
        variant: {
          outline: {
            root: 'border border-dashed border-default bg-muted/10 ring-0',
          },
        },
      },
    },
    input: {
      variants: {
        variant: {
          outline: 'bg-elevated/40 hover:bg-elevated/55 disabled:bg-elevated/20',
        },
      },
    },
    textarea: {
      variants: {
        variant: {
          outline: 'bg-elevated/40 hover:bg-elevated/55 disabled:bg-elevated/20',
        },
      },
    },
    tabs: {
      variants: {
        variant: {
          pill: {
            list: 'bg-elevated/20',
          },
        },
      },
    },
    table: {
      slots: {
        root: 'ws-table-surface',
        thead: 'bg-elevated/20',
        tbody: '[&>tr]:hover:bg-elevated/30',
        th: 'px-3 py-2 text-sm text-left font-medium text-toned',
        td: 'px-3 py-2 text-sm text-default align-top whitespace-normal wrap-anywhere',
        tfoot: 'bg-elevated/40 [&_th]:font-semibold [&_th]:text-highlighted',
      },
    },
    tooltip: {
      slots: {
        content: 'pointer-events-auto',
      },
    },
    toast: {
      slots: {
        root: 'ws-shell-surface bg-transparent',
      },
    },
    modal: {
      slots: {
        content: 'ws-shell-surface bg-transparent',
        body: overlayBody,
      },
    },
    slideover: {
      slots: {
        content: 'ws-shell-surface bg-transparent',
        body: overlayBody,
      },
    },
    card: {
      slots: {
        root: 'min-w-0 max-w-full rounded-xl overflow-hidden',
      },
      variants: {
        variant: {
          outline: {
            root: 'border border-default/70 bg-elevated/20 divide-y divide-default',
          },
          soft: {
            root: 'bg-elevated/20 divide-y divide-default',
          },
          subtle: {
            root: 'border border-default/70 bg-elevated/20 divide-y divide-default',
          },
        },
      },
    },
    dropdownMenu: {
      slots: {
        content: floatingSurface,
      },
    },
    popover: {
      slots: {
        content: floatingSurface,
      },
    },
    select: {
      slots: {
        content: floatingSurface,
      },
      variants: {
        variant: {
          outline: 'bg-elevated/40 hover:bg-elevated/55 disabled:bg-elevated/20',
        },
      },
    },
    selectMenu: {
      slots: {
        content: floatingSurface,
      },
      variants: {
        variant: {
          outline: 'bg-elevated/40 hover:bg-elevated/55 disabled:bg-elevated/20',
        },
      },
    },
    button: {
      compoundVariants: [
        {
          color: 'neutral',
          variant: 'outline',
          class:
            'bg-transparent hover:bg-elevated/30 active:bg-elevated/40 disabled:bg-transparent aria-disabled:bg-transparent',
        },
        {
          color: 'neutral',
          variant: 'soft',
          class:
            'bg-elevated/30 hover:bg-elevated/45 active:bg-elevated/55 disabled:bg-elevated/20 aria-disabled:bg-elevated/20',
        },
        {
          color: 'neutral',
          variant: 'ghost',
          class: 'hover:bg-elevated/30 active:bg-elevated/40 focus-visible:bg-elevated/30',
        },
      ],
      defaultVariants: {
        size: 'sm',
      },
    },
  },
});
