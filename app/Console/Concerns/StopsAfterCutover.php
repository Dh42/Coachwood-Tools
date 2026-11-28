<?php

namespace App\Console\Concerns;

use App\Cutover\Cutover;

/**
 * For a `legacy:import-*` command whose tables belong to one cutover group.
 * Once the group has moved, this app owns those tables and the import would
 * replace them with the old app's, so it refuses.
 */
trait StopsAfterCutover
{
    protected function hasMovedFromOldApp(string $group): bool
    {
        if (! Cutover::hasMoved($group)) {
            return false;
        }

        $this->error(Cutover::group($group)['label'].' moved from the old app on '.Cutover::movedAt($group).' UTC. This app owns its data now, so the import would overwrite it. Nothing was changed.');

        return true;
    }
}
