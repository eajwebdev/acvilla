/** "My bookings" on the guest's phone: the reference and mobile number of each request made (or opened) on it. */
export type SavedBooking = { reference: string; phone: string };

const KEY = 'acv-my-bookings';

export function savedBookings(): SavedBooking[] {
  try {
    const list = JSON.parse(window.localStorage.getItem(KEY) ?? '[]') as SavedBooking[];
    return Array.isArray(list) ? list.filter(item => item?.reference && item?.phone) : [];
  } catch { return []; }
}

export function saveBooking(item: SavedBooking) {
  try {
    const rest = savedBookings().filter(saved => saved.reference !== item.reference);
    window.localStorage.setItem(KEY, JSON.stringify([item, ...rest].slice(0, 30)));
  } catch { /* private mode: the list just won't persist */ }
}

export function forgetBooking(reference: string) {
  try { window.localStorage.setItem(KEY, JSON.stringify(savedBookings().filter(saved => saved.reference !== reference))); } catch { /* ignore */ }
}
