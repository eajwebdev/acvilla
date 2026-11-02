<?php

namespace App\Services;

use App\Rules\ImageSource;
use Illuminate\Support\Str;

/**
 * Everything on the guest website the owner can edit in Admin → Website content.
 * Stored on tenants.site_content; missing keys fall back to these defaults. In titles, *words* show in gold italics.
 */
class SiteContent
{
    /** Icons the editor offers; the guest site maps each name to its drawing. */
    public const ICONS = ['waves', 'a-frame', 'diamond', 'bed', 'aircon', 'sunrise', 'palm', 'glass', 'users', 'heart', 'party', 'sparkles',
        'music', 'car', 'food', 'flower', 'wifi', 'shower', 'calendar', 'phone', 'home', 'camera', 'star', 'sun'];

    /** The sections that can be switched off. */
    public const SECTIONS = ['highlights', 'rooms', 'events', 'amenities', 'gallery', 'steps', 'contact', 'final'];

    /** List fields and the keys each of their items has. */
    private const LISTS = [
        'highlights' => ['icon', 'label'], 'rooms_features' => ['icon', 'label'], 'events_features' => ['icon', 'label'],
        'gallery' => ['image', 'caption'], 'steps' => ['icon', 'title', 'body'],
        'request_types' => ['key', 'label'], 'call_times' => ['key', 'label'],
    ];

    private const IMAGES = ['hero_card_image_1', 'hero_card_image_2', 'rooms_image', 'rooms_inset_image', 'events_image', 'contact_image', 'final_image'];

    public static function defaults(): array
    {
        $img = fn (string $file) => "/images/acvilla/{$file}";

        return [
            'sections' => array_fill_keys(self::SECTIONS, true),
            'hero_eyebrow' => 'Mabinay, Negros Oriental', 'hero_card_image_1' => $img('aframe-villa.jpg'), 'hero_card_image_2' => $img('balcony-view.jpg'),
            'hero_primary_label' => 'Book Now', 'hero_secondary_label' => 'View Rooms',
            'reserve_button' => 'Reserve Now',
            'reserve_note' => 'No payment needed online. Send your request and the owner will call you to confirm your booking.',
            'highlights' => [['icon' => 'waves', 'label' => 'Private Pool'], ['icon' => 'a-frame', 'label' => 'A-Frame Villas'],
                ['icon' => 'diamond', 'label' => 'Events & Weddings'], ['icon' => 'bed', 'label' => 'Comfortable Stay']],

            'rooms_eyebrow' => 'Room Accommodation', 'rooms_title' => 'Stay in Our *Cozy A-Houses*',
            'rooms_text' => 'Experience comfort and relaxation in our A-House rooms, from cozy rooms for 4 to the Barkada Room for 8. Tap a room to browse its photos.',
            'rooms_image' => $img('aframe-villa.jpg'), 'rooms_inset_image' => $img('loft-bedroom.jpg'),
            'rooms_features' => [['icon' => 'a-frame', 'label' => 'A-Frame Villa'], ['icon' => 'waves', 'label' => 'Pool Access'],
                ['icon' => 'aircon', 'label' => 'Air-Conditioned Rooms'], ['icon' => 'sunrise', 'label' => 'Perfect for Getaways']],
            'rooms_primary_label' => 'Check Availability', 'rooms_secondary_label' => 'Explore Rooms',
            'rooms_note' => 'Check-in 2:00 PM · Check-out 12:00 noon · Extra person ₱250',
            'venues_title' => 'Function Hall *Rates*', 'venues_text' => 'Day use, day-to-night and grand hall packages for weddings, birthdays, reunions and seminars.',

            'events_eyebrow' => 'Events & Weddings', 'events_title' => 'Celebrate *with Us*',
            'events_text' => 'Private weddings and intimate gatherings at your own exclusive resort: a poolside ceremony, a reception under the lights, and villas for the whole entourage.',
            'events_image' => $img('wedding-setup.jpg'), 'events_primary_label' => 'Inquire for Events', 'events_secondary_label' => 'View Wedding Package',
            'package_kicker' => 'Exclusive', 'package_title' => 'Wedding', 'package_subtitle' => 'Package', 'package_heading' => 'Rent the Whole Resort',
            'package_note' => 'A private and exclusive venue for your special day',
            'package_includes' => 'All rooms · function hall · private pool and cabanas', 'package_button' => 'Book Now',
            'events_features' => [['icon' => 'diamond', 'label' => 'Exclusive Venue'], ['icon' => 'waves', 'label' => 'Poolside Setup'],
                ['icon' => 'palm', 'label' => 'Private Resort Rental'], ['icon' => 'glass', 'label' => 'Special Celebrations']],
            'events_tagline' => 'Weddings  •  Events  •  Intimate Gatherings',

            'amenities_eyebrow' => 'Amenities', 'amenities_title' => 'Everything for a *restful stay*', 'amenities_text' => 'Your privacy, more memories, more love.',

            'gallery_eyebrow' => 'Gallery', 'gallery_title' => 'A closer look at *AC Villa*', 'gallery_text' => 'Tap a photo to view it full size.',
            'gallery' => collect([['hero-resort.jpg', 'The main house and pool at golden hour'], ['aframe-villa.jpg', 'A-frame villa'],
                ['wedding-setup.jpg', 'Poolside wedding setup'], ['aerial.jpg', 'Aerial view of the resort'], ['pool-day.jpg', 'The private pool'],
                ['loft-bedroom.jpg', 'Loft bedroom inside an A-House'], ['living-hall.jpg', 'Function and living hall'], ['pool-night.jpg', 'The pool at night'],
                ['balcony-view.jpg', 'Balcony lounge overlooking the pool']])->map(fn ($item) => ['image' => $img($item[0]), 'caption' => $item[1]])->all(),

            'steps_eyebrow' => 'How booking works', 'steps_title' => 'Ready to *book your stay?*',
            'steps_text' => 'Reserve your private getaway in three easy steps.',
            'steps' => [
                ['icon' => 'calendar', 'title' => 'Send your request', 'body' => 'Pick your dates, A-House and the kind of stay or event, then leave your name and mobile number.'],
                ['icon' => 'phone', 'title' => 'We call you', 'body' => 'The owner calls you to confirm availability, answer questions and arrange your payment. Your dates are held meanwhile.'],
                ['icon' => 'sparkles', 'title' => 'Relax & celebrate', 'body' => "Your booking is confirmed. All that's left is to arrive, unwind and make memories."],
            ],
            'steps_button' => 'Reserve Now',

            'contact_eyebrow' => 'Visit us', 'contact_title' => 'Find *AC Villa*', 'location_note' => 'Beside NORSU',
            'map_url' => 'https://www.google.com/maps/search/?api=1&query=North+Poblacion+Mabinay+Negros+Oriental+NORSU+Mabinay+Campus',
            // Where the pin on the landing-page map goes: a place name, an address or "lat,lng" coordinates.
            'map_pin' => '9.7585357,122.9191284',
            'contact_image' => $img('aerial.jpg'), 'contact_photo_title' => 'North Poblacion, Mabinay', 'contact_photo_text' => 'Negros Oriental · beside NORSU Mabinay Campus',

            'final_eyebrow' => 'Your privacy, more memories, more love', 'final_title' => 'Ready to Book *Your Stay?*',
            'final_text' => 'Reserve your private getaway at AC Villa & Private Resort.', 'final_button' => 'Reserve Now', 'final_image' => $img('pool-night.jpg'),

            'footer_tagline' => 'Your privacy, more memories, more love.',
            'footer_about' => 'A private resort and events place with A-House rooms, a sparkling pool, a function hall and a grand hall in Mabinay, Negros Oriental.',
            'phone_alt' => '0966 147 5314', 'facebook_url' => 'https://www.facebook.com/profile.php?id=61585170956861',

            'request_types' => [['key' => 'stay', 'label' => 'Overnight stay'], ['key' => 'day_tour', 'label' => 'Day tour / swimming'],
                ['key' => 'wedding', 'label' => 'Wedding'], ['key' => 'celebration', 'label' => 'Birthday / celebration'],
                ['key' => 'corporate', 'label' => 'Corporate / group event'], ['key' => 'whole_resort', 'label' => 'Exclusive whole-resort rental']],
            'call_times' => [['key' => 'anytime', 'label' => 'Anytime'], ['key' => 'morning', 'label' => 'Morning (8 AM to 12 NN)'],
                ['key' => 'afternoon', 'label' => 'Afternoon (12 NN to 5 PM)'], ['key' => 'evening', 'label' => 'Evening (5 PM to 8 PM)']],
        ];
    }

    /** Stored values over the defaults, unknown keys dropped and every value typed like its default. */
    public static function resolve(?array $stored): array
    {
        $out = [];
        foreach (self::defaults() as $key => $default) {
            $value = $stored[$key] ?? null;
            $out[$key] = match (true) {
                $key === 'sections' => collect($default)->map(fn ($on, $section) => filter_var(($value ?? [])[$section] ?? $on, FILTER_VALIDATE_BOOLEAN))->all(),
                isset(self::LISTS[$key]) => is_array($value) ? self::list($key, $value) : $default,
                default => $value === null ? $default : trim((string) $value),
            };
        }

        return $out;
    }

    private static function list(string $key, array $items): array
    {
        return collect($items)->filter(fn ($item) => is_array($item))->map(fn (array $item) => collect(self::LISTS[$key])
            ->mapWithKeys(fn ($field) => [$field => trim((string) ($item[$field] ?? ''))])->all())->values()->all();
    }

    /** Option lists get a stable key per label so saved bookings and links keep matching. */
    public static function withKeys(array $items): array
    {
        $seen = [];

        return collect($items)->map(function (array $item) use (&$seen) {
            $key = Str::slug(($item['key'] ?? '') ?: $item['label'], '_') ?: 'option';
            for ($base = $key, $n = 2; in_array($key, $seen, true); $n++) {
                $key = "{$base}_{$n}";
            }
            $seen[] = $key;

            return ['key' => $key, 'label' => $item['label']];
        })->all();
    }

    public static function rules(): array
    {
        $text = fn (int $max) => ['nullable', 'string', 'max:'.$max];
        $icon = ['required', 'in:'.implode(',', self::ICONS)];
        $rules = ['sections' => ['required', 'array:'.implode(',', self::SECTIONS)], 'sections.*' => ['boolean']];
        foreach (self::defaults() as $key => $default) {
            if (is_string($default)) {
                $rules[$key] = in_array($key, self::IMAGES, true) ? ['nullable', new ImageSource] : (in_array($key, ['map_url', 'facebook_url'], true) ? ['nullable', 'url:http,https', 'max:2048'] : $text(600));
            }
        }

        return $rules + [
            'highlights' => ['present', 'array', 'max:6'], 'highlights.*.icon' => $icon, 'highlights.*.label' => ['required', 'string', 'max:60'],
            'rooms_features' => ['present', 'array', 'max:6'], 'rooms_features.*.icon' => $icon, 'rooms_features.*.label' => ['required', 'string', 'max:60'],
            'events_features' => ['present', 'array', 'max:6'], 'events_features.*.icon' => $icon, 'events_features.*.label' => ['required', 'string', 'max:60'],
            'gallery' => ['present', 'array', 'max:30'], 'gallery.*.image' => ['required', new ImageSource], 'gallery.*.caption' => ['nullable', 'string', 'max:120'],
            'steps' => ['present', 'array', 'max:6'], 'steps.*.icon' => $icon, 'steps.*.title' => ['required', 'string', 'max:80'], 'steps.*.body' => ['nullable', 'string', 'max:300'],
            'request_types' => ['required', 'array', 'min:1', 'max:15'], 'request_types.*.key' => ['nullable', 'string', 'max:40'], 'request_types.*.label' => ['required', 'string', 'max:60', 'distinct'],
            'call_times' => ['required', 'array', 'min:1', 'max:10'], 'call_times.*.key' => ['nullable', 'string', 'max:40'], 'call_times.*.label' => ['required', 'string', 'max:60', 'distinct'],
        ];
    }

    /** key => label, for validating and showing a guest's choice. */
    public static function options(array $content, string $list): array
    {
        return collect($content[$list])->pluck('label', 'key')->all();
    }
}
