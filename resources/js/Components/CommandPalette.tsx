import { router } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import { CornerDownLeft, LogOut, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { moreLinks, opsNav, platformNav, settingsGroups, staffNav, type NavItem } from './nav';

type Command = { id: string; label: string; group: string; icon: NavItem[2]; run: () => void };

function commandsFor(role: string, platform: boolean): Command[] {
  const go = (group: string) => ([href, label, icon]: NavItem): Command => ({ id: `${group}:${href}`, label, group, icon, run: () => router.visit(href) });
  const list = platform ? platformNav.map(go('Platform'))
    : role === 'STAFF' ? [...staffNav.map(go('Workspace')), ...settingsGroups.filter(([, , adminOnly]) => !adminOnly).flatMap(([, links]) => links.map(go('Settings')))]
      : [...opsNav.map(go('Operations')), ...moreLinks.filter(([href]) => href !== '/').map(go('Manage')), ...settingsGroups.flatMap(([, links]) => links.map(go('Settings')))];
  const seen = new Set<string>();
  return [...list.filter(command => { const href = command.id.split(':')[1]; if (seen.has(href)) return false; seen.add(href); return true; }),
    { id: 'signout', label: 'Sign out', group: 'Account', icon: LogOut, run: () => router.post('/logout') }];
}

/** ⌘K / Ctrl+K quick navigation for the operations and platform apps. */
export default function CommandPalette({ role, platform, open, onOpenChange }: { role: string; platform: boolean; open: boolean; onOpenChange: (open: boolean) => void }) {
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const listRef = useRef<HTMLDivElement>(null);
  const commands = useMemo(() => commandsFor(role, platform), [role, platform]);
  const results = useMemo(() => {
    const q = query.trim().toLowerCase();
    return q ? commands.filter(command => `${command.label} ${command.group}`.toLowerCase().includes(q)) : commands;
  }, [commands, query]);

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); onOpenChange(!open); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, onOpenChange]);
  useEffect(() => { if (open) { setQuery(''); setActive(0); } }, [open]);
  useEffect(() => { setActive(0); }, [query]);
  useEffect(() => { listRef.current?.querySelector(`[data-index="${active}"]`)?.scrollIntoView({ block: 'nearest' }); }, [active]);

  const run = (command?: Command) => { if (!command) return; onOpenChange(false); command.run(); };
  const onKeyDown = (event: React.KeyboardEvent) => {
    if (event.key === 'ArrowDown') { event.preventDefault(); setActive(i => Math.min(results.length - 1, i + 1)); }
    if (event.key === 'ArrowUp') { event.preventDefault(); setActive(i => Math.max(0, i - 1)); }
    if (event.key === 'Enter') { event.preventDefault(); run(results[active]); }
  };

  let lastGroup = '';
  return <Dialog.Root open={open} onOpenChange={onOpenChange}>
    <Dialog.Portal>
      <Dialog.Overlay className="tapp-overlay animate-fade"/>
      <Dialog.Content className="tapp-dialog wide animate-fade overflow-hidden md:!top-[12vh] md:![transform:translateX(-50%)]" onKeyDown={onKeyDown} aria-describedby={undefined}>
        <Dialog.Title className="sr-only">Go to a page</Dialog.Title>
        <div className="flex items-center gap-3 border-b border-[var(--line)] px-4">
          <Search size={18} className="shrink-0 text-[var(--muted)]"/>
          <input autoFocus value={query} onChange={event => setQuery(event.target.value)} placeholder="Jump to a page or setting…" aria-label="Search pages" role="combobox" aria-expanded="true" aria-controls="command-results" aria-activedescendant={results[active] ? `command-${active}` : undefined}
            className="h-14 min-w-0 flex-1 bg-transparent text-[15px] font-semibold outline-none placeholder:text-[var(--muted)] focus-visible:!outline-none"/>
          <kbd className="kbd">Esc</kbd>
        </div>
        <div ref={listRef} id="command-results" role="listbox" className="max-h-[min(60vh,420px)] overflow-y-auto p-2">
          {results.map((command, index) => {
            const header = command.group !== lastGroup ? command.group : null;
            lastGroup = command.group;
            const Icon = command.icon;
            return <div key={command.id}>
              {header && <p className="px-3 pb-1 pt-3 text-[10.5px] font-bold uppercase tracking-[.12em] text-[var(--muted)]">{header}</p>}
              <button type="button" id={`command-${index}`} data-index={index} role="option" aria-selected={index === active} onMouseMove={() => setActive(index)} onClick={() => run(command)}
                className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-semibold ${index === active ? 'bg-[var(--mint)] text-forest' : 'text-[#3B404C]'}`}>
                <span className={`grid size-8 place-items-center rounded-lg ${index === active ? 'bg-white text-ocean' : 'bg-[#F5F4F0]'}`}><Icon size={16}/></span>
                <span className="flex-1">{command.label}</span>
                {index === active && <CornerDownLeft size={15} className="text-[var(--muted)]"/>}
              </button>
            </div>;
          })}
          {!results.length && <p className="px-3 py-10 text-center text-sm text-[var(--muted)]">No pages match “{query}”.</p>}
        </div>
        <div className="flex items-center gap-4 border-t border-[var(--line)] bg-[#FAF9F7] px-4 py-2.5 text-[11px] font-semibold text-[var(--muted)]"><span className="flex items-center gap-1.5"><kbd className="kbd">↑</kbd><kbd className="kbd">↓</kbd> navigate</span><span className="flex items-center gap-1.5"><kbd className="kbd">↵</kbd> open</span></div>
      </Dialog.Content>
    </Dialog.Portal>
  </Dialog.Root>;
}
