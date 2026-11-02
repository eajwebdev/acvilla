import { Activity, BookOpen, Building2, CalendarClock, CalendarDays, CalendarRange, ChartLine, CircleHelp, ClipboardCheck, Clock3, DatabaseBackup, Globe, History, LayoutGrid, ListChecks, Mail, Package, PackagePlus, Palette, ReceiptText, ScrollText, ShoppingBasket, Sparkles, SprayCan, Star, Target, TicketPercent, Trash2, UserRound, Users, Wallet } from 'lucide-react';

/** Navigation for the operations app, shared by the sidebar, the settings menu and the command palette. */
export type NavItem = readonly [href: string, label: string, icon: typeof LayoutGrid];

export const opsNav: readonly NavItem[] = [
  ['/admin', 'Dashboard', LayoutGrid], ['/admin/calendar', 'Calendar', CalendarDays], ['/admin/cleaning', 'Cleaning', SprayCan], ['/admin/attendance', 'Attendance', Clock3],
  ['/admin/bookings', 'Bookings', BookOpen], ['/admin/pos', 'POS', ShoppingBasket], ['/admin/insights', 'Insights', ChartLine],
];
export const staffNav: readonly NavItem[] = [
  ['/admin/my-tasks', 'My tasks', ClipboardCheck], ['/admin/attendance', 'Attendance', Clock3], ['/admin/bookings', 'Bookings', BookOpen],
  ['/admin/calendar', 'Calendar', CalendarDays], ['/admin/pos', 'POS', ShoppingBasket],
];
export const platformNav: readonly NavItem[] = [['/superadmin', 'Overview', LayoutGrid], ['/superadmin/clients', 'Clients', Users], ['/superadmin/health', 'System health', Activity]];
export const moreLinks: readonly NavItem[] = [['/admin/properties', 'Properties & units', Building2], ['/admin/customers', 'Customers', Users], ['/admin/reviews', 'Reviews', Star], ['/', 'Guest site', Globe], ['/admin/settings/help', 'Help & guides', CircleHelp]];

export const settingsGroups: readonly (readonly [group: string, links: readonly NavItem[], adminOnly: boolean])[] = [
  ['Account', [['/admin/settings/profile', 'My profile', UserRound]], false],
  ['Team', [['/admin/settings/team', 'Users', Users]], true],
  ['Property', [['/admin/properties', 'Units', Building2], ['/admin/settings/add-ons', 'Add-ons', PackagePlus], ['/admin/settings/cleaning', 'Cleaning checklist', ListChecks],
    ['/admin/settings/general', 'Default times', Clock3], ['/admin/settings/targets', 'Revenue target', Target], ['/admin/settings/seasons', 'Rates', CalendarRange],
    ['/admin/settings/amenities', 'Amenities', Sparkles]], true],
  ['Point of sale', [['/admin/settings/pos-items', 'POS items', Package]], true],
  ['Bookings', [['/admin/settings/booking', 'Booking rules', CalendarClock], ['/admin/settings/pricing', 'Pricing & taxes', ReceiptText], ['/admin/settings/promos', 'Promo codes', TicketPercent]], true],
  ['Payments & messages', [['/admin/settings/payments', 'Payments', Wallet], ['/admin/settings/messages', 'Guest messages', Mail]], true],
  ['Website', [['/admin/website', 'Website content', Globe], ['/admin/branding', 'Branding & contact', Palette]], true],
  ['Preferences', [ ['/admin/settings/policies', 'Quotation & House Rules', ScrollText]], true],
  ['Data & admin', [['/admin/settings/deleted-bookings', 'Deleted bookings', Trash2], ['/admin/settings/audit', 'Audit log', History], ['/admin/settings/backup', 'Download backup', DatabaseBackup]], true],
  ['Support', [['/admin/settings/help', 'Help & guides', CircleHelp]], false],
];
