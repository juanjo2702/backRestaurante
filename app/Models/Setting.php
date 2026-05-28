<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group'];

    protected $casts = [
        'value' => 'string',
    ];

    public static function getValue($key, $default = null)
    {
        $setting = self::where('key', $key)->first();
        if (!$setting) {
            return $default;
        }
        // Convertir según tipo
        switch ($setting->type) {
            case 'integer':
                return (int) $setting->value;
            case 'boolean':
                return filter_var($setting->value, FILTER_VALIDATE_BOOLEAN);
            case 'json':
                return json_decode($setting->value, true);
            default:
                return $setting->value;
        }
    }

    public static function setValue($key, $value, $type = 'string', $group = 'general')
    {
        $setting = self::where('key', $key)->first();
        if (!$setting) {
            $setting = new self(['key' => $key]);
        }
        $setting->value = $value;
        $setting->type = $type;
        $setting->group = $group;
        $setting->save();
        return $setting;
    }
}