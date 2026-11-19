/**
 * The brand palette for places CSS classes can't reach: chart libraries, SVG attributes and inline styles
 * (SVG presentation attributes can't read CSS variables). Keep these in step with the @theme tokens in
 * resources/css/app.css; class names should use the tokens (text-sea, bg-sand…) rather than these values.
 */
export const palette = {
  forest: '#0A2266',
  sea: '#0039D5',
  ocean: '#002EAD',
  leaf: '#FFC800',
  leafLight: '#FFD84D',
  ink: '#141C33',
  inkMuted: '#5E6679',
  inkFaint: '#AFB4C1',
  lineSoft: '#EBE8E0',
  lineStrong: '#D8D2C4',
  sand: '#F3F0EA',
  paper: '#FBFAF6',
  blue100: '#DCE6FF',
  blue300: '#8AA8FB',
  /** The dashboard's revenue target line. */
  target: '#A07716',
  white: '#fff',
} as const;

/** Axis labels shared by every chart. */
export const chartTick = { fontSize: 10, fontFamily: 'Jost', fill: palette.inkMuted } as const;
