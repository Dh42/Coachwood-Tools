<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/** Ported from the old app, plus `group` and the helpers the launcher and sidebar share. */
#[Fillable(['name', 'description', 'slug', 'group', 'icon', 'url', 'active', 'sort_order'])]
class Tool extends Model
{
    /** The heading for tools with no group. */
    public const DEFAULT_GROUP = 'Tools';

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function permissionName(): string
    {
        return "tool.{$this->slug}";
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Active tools the user may open, in order. Admins pass every check
     * (Gate::before), so they see them all.
     *
     * @return Collection<int, Tool>
     */
    public static function visibleTo(?User $user): Collection
    {
        if (! $user) {
            return new Collection;
        }

        return self::query()->active()->ordered()->get()
            ->filter(fn (Tool $tool) => $user->can($tool->permissionName()))
            ->values();
    }

    /**
     * Tools grouped for display. Groups appear in the order of their first
     * tool, so reordering tools also reorders groups.
     *
     * @param  Collection<int, Tool>  $tools
     * @return array<string, list<Tool>>
     */
    public static function grouped(Collection $tools): array
    {
        $groups = [];
        foreach ($tools as $tool) {
            $groups[$tool->group ?: self::DEFAULT_GROUP][] = $tool;
        }

        return $groups;
    }
}
