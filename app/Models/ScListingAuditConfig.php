<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScListingAuditConfig extends Model
{
    protected $table = 'sc_listing_audit_config';

    public $timestamps = false;

    protected $fillable = [
        'effective_from', 'attr_slot_1', 'attr_slot_2', 'attr_slot_3', 'attr_slot_4',
        'attr_slot_5', 'attr_slot_6', 'attr_slot_7',
        'alias_slot_1', 'alias_slot_2', 'alias_slot_3', 'alias_slot_4',
        'alias_slot_5', 'alias_slot_6', 'alias_slot_7',
        'reason', 'changed_by_user_id',
    ];

    protected function casts(): array
    {
        return ['effective_from' => 'datetime'];
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public function slots(): array
    {
        return array_values(array_filter([
            $this->attr_slot_1, $this->attr_slot_2, $this->attr_slot_3, $this->attr_slot_4,
            $this->attr_slot_5, $this->attr_slot_6, $this->attr_slot_7,
        ]));
    }

    /**
     * The raw 7 slots, gaps (null) included and positions preserved — unlike
     * slots(), which compacts/reindexes. Needed for the config form's own
     * pre-population: matching a form field at physical position N against
     * the compacted list breaks as soon as an earlier slot is empty, since
     * position N in the compacted list no longer corresponds to physical
     * slot N.
     */
    public function rawSlots(): array
    {
        return [
            $this->attr_slot_1, $this->attr_slot_2, $this->attr_slot_3, $this->attr_slot_4,
            $this->attr_slot_5, $this->attr_slot_6, $this->attr_slot_7,
        ];
    }

    /** The raw 7 alias slots, positions preserved — see rawSlots(). */
    public function rawAliases(): array
    {
        return [
            $this->alias_slot_1, $this->alias_slot_2, $this->alias_slot_3, $this->alias_slot_4,
            $this->alias_slot_5, $this->alias_slot_6, $this->alias_slot_7,
        ];
    }

    /**
     * Attribute key => alias, for whichever configured slots have one set.
     * Keyed by attribute key (not slot position) since slots() drops gaps
     * and re-indexes, so position alone can't be matched back to a slot.
     */
    public function aliases(): array
    {
        $aliases = [];
        for ($i = 1; $i <= 7; $i++) {
            $key = $this->{"attr_slot_{$i}"};
            $alias = $this->{"alias_slot_{$i}"};
            if ($key !== null && $alias !== null && $alias !== '') {
                $aliases[$key] = $alias;
            }
        }

        return $aliases;
    }
}
