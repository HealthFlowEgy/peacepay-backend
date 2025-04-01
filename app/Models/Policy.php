<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Policy extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'description',
        'fields',
        'user_id'
    ];

    public function scopeMine($query) {
        if(auth()->check()){
            return $query->where('user_id', auth()->user()->id);
        }
        return $query;
    }

    public function getFieldsAttribute(){
        return json_decode($this->attributes['fields'], true);
    }
}
