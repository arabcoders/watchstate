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
        label: 'block font-bold text-default',
      },
    },
    tooltip: {
      slots: {
        content: 'pointer-events-auto',
      },
    },
    card: {
      slots: {
        root: 'min-w-0 max-w-full rounded-lg overflow-hidden',
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
        content: 'ws-floating-surface',
      },
    },
    popover: {
      slots: {
        content: 'ws-floating-surface',
      },
    },
    select: {
      slots: {
        content: 'ws-floating-surface',
      },
      variants: {
        variant: {
          outline: 'bg-elevated/40 hover:bg-elevated/55 disabled:bg-elevated/20',
        },
      },
    },
    selectMenu: {
      slots: {
        content: 'ws-floating-surface',
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
