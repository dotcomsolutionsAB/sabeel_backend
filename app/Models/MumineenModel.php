<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MumineenModel extends Model
{
    //
    protected $table = 't_mumineen';

    protected $fillable = [
        'family_id',
        'hof_type',
        'its',
        'hof_its',
        'family_its',
        'name',
        'sector',
        'sub_sector',
        'mobile',
        'email',
        'gender',
        'age',
        'pic',
        'status',
    ];

    protected $casts = [
        'family_id' => 'integer',
        'age'       => 'integer',
    ];

    // One family can have many links to establishments
    public function establishmentLinks(): HasMany
    {
        return $this->hasMany(MumineenEstablishmentModel::class, 'family_id', 'family_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(ReceiptModel::class, 'family_id', 'family_id');
    }

    public function sabeelEntries(): HasMany
    {
        return $this->hasMany(MumineenSabeelModel::class, 'family_id', 'family_id');
    }

    public function syncPhotos()
    {
        $itsValue = $this->its;  // Get the ITS value of the current record

        // Check if the image exists
        $imagePath = public_path('storage/uploads/its_images/' . $itsValue . '.jpg');

        if (file_exists($imagePath)) {
            // If image exists, set the full URL
            $this->pic = url('storage/uploads/its_images/' . $itsValue . '.jpg');
        } else {
            // If not, set the placeholder image
            $this->pic = url('storage/uploads/its_images/placeholder.jpg');
        }
    }
}
