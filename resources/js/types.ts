export type Bed = { type: string; count: number };
export type Room = { id: number; name: string; whole_property?: boolean; kind?: 'room' | 'venue'; description: string | null; maximum_occupancy: number; quantity: number; base_rate: string; weekend_rate: string | null; cleaning_fee: string; active?: boolean; bedrooms: number; bathrooms: string; beds: Bed[] | null; size_sqm: number | null; base_guests: number | null; extra_guest_fee: string; min_nights: number | null; max_nights: number | null; amenities: string[] | null; photos: string[] | null };
const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? '' : 's'}`;
/** "2 bedrooms · 1 king, 2 single · 1.5 baths · 55 m²" */
export const roomSpecs = (room: Room) => [
  room.bedrooms > 0 ? plural(room.bedrooms, 'bedroom') : 'Studio',
  room.beds?.length ? room.beds.map(bed => `${bed.count} ${bed.type}`).join(', ') : null,
  Number(room.bathrooms) > 0 ? `${Number(room.bathrooms)} ${Number(room.bathrooms) === 1 ? 'bath' : 'baths'}` : null,
  room.size_sqm ? `${room.size_sqm} m²` : null,
].filter(Boolean).join(' · ');
export const includedGuests = (room: Room) => Math.min(room.base_guests ?? room.maximum_occupancy, room.maximum_occupancy);
export type Property = { id: number; name: string; slug: string; city: string; province: string; short_description: string | null; description: string | null; cover_image: string | null; rooms: Room[]; tenant?: { business_name: string; logo: string | null } };
export type Booking = { id: number; reference: string; guest_phone?: string | null; guest_email?: string | null; source?: string; expires_at?: string | null; deposit_amount?: string | null; balance_due_on?: string | null; payments?: { id: number; method: string; amount: string; payer_reference: string | null; status: string; created_at: string }[]; review?: { id: number; rating: number; comment: string | null; approved: boolean } | null; guest_name: string; check_in: string; check_out: string; guests: number; status: string; payment_status: string; total: string; paid_amount?: string | null; property: Property; room: Room; price?: { subtotal: string; extra_guest_fee: string; cleaning_fee: string; service_fee: string; tax: string; discount: string; discounts: DiscountLine[] | null; add_ons: AddOnLine[] | null; add_ons_total: string; nightly_rates: { date: string; rate: number; season?: string | null }[]; total: string } };
export type TenantSettings = { currency: string; reference_prefix: string; check_in_time: string; check_out_time: string; event_start_time?: string; event_end_time?: string; turnover_minutes?: number; min_nights: number; max_nights: number; advance_notice_days: number; booking_window_days: number; hold_minutes: number; manual_payment_hours: number; request_hold_hours: number; service_fee_percent: number; tax_label: string; tax_percent: number; weekly_discount_percent: number; monthly_discount_percent: number; weekend_days: number[]; amenities: string[]; house_rules: string; cancellation_policy: string; test_payments: boolean; deposit_percent: number; balance_due_days: number; payment_methods: Record<string, PaymentMethodConfig>; monthly_revenue_target: number; cleaning_checklist: string[]; cleaning_photo_required: boolean };
export type PaymentMethodConfig = { enabled: boolean; account_name: string; account_number: string; instructions: string; qr_image: string };
export type PaymentOption = PaymentMethodConfig & { method: string; label: string };
export type DiscountLine = { label: string; amount: number };
export type AddOnLine = { id: number; name: string; pricing: string; quantity: number; unit_price: number; amount: number };
export type AddOn = { id: number; name: string; description: string | null; price: string; pricing: 'per_stay' | 'per_night' | 'per_guest' | 'per_guest_night'; max_quantity: number; room_ids: number[] | null; active?: boolean };
export const pricingLabel: Record<AddOn['pricing'], string> = { per_stay: 'per stay', per_night: 'per night', per_guest: 'per guest', per_guest_night: 'per guest, per night' };
export type Quote = { nights: number; tax_label: string; nightly_rates: { date: string; rate: number; season: string | null }[]; subtotal: number; extra_guest_fee: number; cleaning_fee: number; service_fee: number; tax: number; discount: number; discounts: DiscountLine[]; add_ons: AddOnLine[]; add_ons_total: number; total: number };
// Set from page props on every render in app.tsx, so money() follows the tenant's currency setting.
let activeCurrency = 'PHP';
export const setCurrency = (code?: string | null) => { activeCurrency = code || 'PHP'; };
export const currency = () => activeCurrency;
export const money = (n: string | number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: activeCurrency, maximumFractionDigits: 0 }).format(Number(n));
export const compactMoney = (n: string | number) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: activeCurrency, notation: 'compact' }).format(Number(n));
export const time12 = (hhmm: string) => new Date(`2000-01-01T${hhmm}:00`).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
/** How a unit's rate is quoted: per night for rooms, per event for venues, once for a whole-property package. */
export const rateUnit = (room: Pick<Room, 'kind' | 'whole_property'>) => room.whole_property ? 'total package' : room.kind === 'venue' ? '/ event' : '/ night';
