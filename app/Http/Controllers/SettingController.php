<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SettingController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->groupBy('group');
        return response()->json($settings);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string',
            'settings.*.value' => 'nullable',
            'settings.*.type' => 'nullable|string|in:string,integer,boolean,json',
            'settings.*.group' => 'nullable|string',
        ]);

        $updated = [];
        foreach ($validated['settings'] as $item) {
            $setting = Setting::where('key', $item['key'])->first();
            if (!$setting) {
                $setting = new Setting(['key' => $item['key']]);
            }
            $setting->value = $item['value'] ?? '';
            $setting->type = $item['type'] ?? 'string';
            $setting->group = $item['group'] ?? 'general';
            $setting->save();
            $updated[] = $setting;
        }

        return response()->json([
            'message' => 'Configuración actualizada',
            'settings' => $updated,
        ]);
    }

    public function getByGroup($group)
    {
        $settings = Setting::where('group', $group)->get();
        return response()->json($settings);
    }
}