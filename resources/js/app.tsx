import { createInertiaApp } from '@inertiajs/react';
import { createElement } from 'react';
import { createRoot } from 'react-dom/client';
import { setCurrency, type TenantSettings } from './types';
const pages = import.meta.glob<{ default: React.ComponentType }>('./Pages/**/*.tsx');

type PageProps = { currency?: string; tenant?: { settings?: TenantSettings } | null };

createInertiaApp({
  // Guest pages already end in "| <business name>"; everything else is branded AC Villa.
  title: title => !title ? 'AC Villa & Private Resort' : title.includes('|') ? title : `${title} | AC Villa`,
  resolve: (name) => {
    const page = pages[`./Pages/${name}.tsx`];
    if (!page) throw new Error(`Page ${name} not found`);
    return page().then(module => module.default);
  },
  setup({ el, App, props }) {
    createRoot(el).render(<App {...props}>{({ Component, key, props: pageProps }) => {
      const page = pageProps as PageProps;
      setCurrency(page.tenant?.settings?.currency ?? page.currency);
      return createElement(Component, { key, ...pageProps });
    }}</App>);
  },
  progress: { color: '#0070C0', delay: 150, showSpinner: false },
});
