// AtIcon path registry (UI foundation rule 5: one SVG icon set, no emoji icons).
// Path data adapted from Lucide (https://lucide.dev), ISC License,
// Copyright (c) Lucide Contributors. Circles/rects are rewritten as paths.
// All icons use a 24x24 viewBox, no fill, stroke = currentColor.
export const AT_ICON_PATHS = Object.freeze({
  close: ['M18 6 6 18', 'm6 6 12 12'],
  check: ['M20 6 9 17l-5-5'],
  warning: [
    'm21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3',
    'M12 9v4',
    'M12 17h.01',
  ],
  info: ['M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0', 'M12 16v-4', 'M12 8h.01'],
  search: ['M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0', 'm21 21-4.3-4.3'],
  user: ['M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2', 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0'],
  bell: ['M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9', 'M10.3 21a1.94 1.94 0 0 0 3.4 0'],
  calendar: [
    'M8 2v4',
    'M16 2v4',
    'M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z',
    'M3 10h18',
  ],
  clipboard: [
    'M9 2h6a1 1 0 0 1 1 1v2a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1Z',
    'M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2',
  ],
  edit: ['M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z', 'm15 5 4 4'],
  package: [
    'm7.5 4.27 9 5.15',
    'M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z',
    'm3.3 7 8.7 5 8.7-5',
    'M12 22V12',
  ],
  'chevron-left': ['m15 18-6-6 6-6'],
  'chevron-right': ['m9 18 6-6-6-6'],
  plus: ['M5 12h14', 'M12 5v14'],
  more: [
    'M13 12a1 1 0 1 1-2 0 1 1 0 0 1 2 0',
    'M20 12a1 1 0 1 1-2 0 1 1 0 0 1 2 0',
    'M6 12a1 1 0 1 1-2 0 1 1 0 0 1 2 0',
  ],
});

export const AT_ICON_NAMES = Object.freeze(Object.keys(AT_ICON_PATHS));

// Size names map to --ds-font-size-* variables; the SVG is 1em square, so
// `inherit` follows the surrounding text size.
export const AT_ICON_SIZES = Object.freeze(['sm', 'md', 'lg', 'inherit']);
