<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class Configuration extends Model
{
    protected $fillable = [
        'configuration_group_id',
        'key',
        'value',
        'display_name',
        'description',
        'type',
        'options',
        'is_required',
        'is_encrypted',
        'validation_rules',
        'sort_order'
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'is_encrypted' => 'boolean',
    ];

    public function configurationGroup(): BelongsTo
    {
        return $this->belongsTo(ConfigurationGroup::class);
    }

    // Accessor to decrypt encrypted values
    public function getValueAttribute($value)
    {
        if ($this->is_encrypted && $value) {
            try {
                return Crypt::decryptString($value);
            } catch (\Exception $e) {
                return $value;
            }
        }
        return $value;
    }

    // Mutator to encrypt values when needed
    public function setValueAttribute($value)
    {
        if ($this->is_encrypted && $value) {
            $this->attributes['value'] = Crypt::encryptString($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }

    // Get raw encrypted value for database operations
    public function getRawValue()
    {
        return $this->attributes['value'] ?? null;
    }
}
