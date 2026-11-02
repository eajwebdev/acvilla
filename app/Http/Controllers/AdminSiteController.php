<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Rules\ImageSource;
use App\Services\SiteContent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

/** Admin → Website content: every text, photo, icon and option on the guest website. */
class AdminSiteController extends Controller
{
    public function show(Request $request)
    {
        $tenant = $request->user()->tenant;

        return Inertia::render('Admin/Website', ['content' => $tenant->site_content, 'defaults' => SiteContent::defaults(),
            'icons' => SiteContent::ICONS, 'hero' => $tenant->only('slug', 'hero_headline', 'hero_description', 'hero_image')]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([...SiteContent::rules(),
            'hero_headline' => ['nullable', 'string', 'max:160'], 'hero_description' => ['nullable', 'string', 'max:500'],
            'hero_image' => ['nullable', new ImageSource]]);
        $hero = array_intersect_key($data, array_flip(['hero_headline', 'hero_description', 'hero_image']));
        $content = array_diff_key($data, $hero);
        foreach (['request_types', 'call_times'] as $list) {
            $content[$list] = SiteContent::withKeys($content[$list]);
        }
        $tenant = $request->user()->tenant;
        $tenant->update([...$hero, 'site_content' => $content]);
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $tenant->id, 'action' => 'website.updated',
            'entity_type' => 'Tenant', 'entity_id' => $tenant->id, 'metadata' => [], 'ip_address' => $request->ip()]);

        return back()->with('success', 'Website updated.');
    }

    /** Saves a photo into public/uploads/site and returns its address for any image field. */
    public function upload(Request $request)
    {
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192']]);
        $file = $request->file('image');
        $name = strtolower((string) Str::ulid()).'.'.$file->extension();
        $file->move(public_path('uploads/site'), $name);

        return response()->json(['url' => "/uploads/site/{$name}"]);
    }
}
