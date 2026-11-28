<?php

namespace App\Legacy;

use App\Services\AmazonListings\ImageAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * `compare:image-manager`: the page's figures from each product's latest
 * image audit, worked out the same way against the old database and this
 * one; every row of `image_audits`; and whether every image file our
 * accepted submissions point at is on this app's disk (plan section 5.9,
 * "Files"). Counts and hashes only.
 */
final class ImageManagerComparison
{
    /**
     * @return list<array{section: string, old_rows: int, new_rows: int, old_hash: string, new_hash: string, match: bool}>
     */
    public function run(): array
    {
        $out = [];
        $sections = [
            'Page figures' => fn (?string $connection) => $this->figures($connection),
            'image_audits' => fn (?string $connection) => DB::connection($connection)->table('image_audits')->orderBy('id')
                ->get(MirrorImports::IMAGE_MANAGER['image_audits'])->map(fn ($row) => RowHasher::normalise((array) $row))->all(),
        ];
        foreach ($sections as $name => $rows) {
            $old = $rows('legacy');
            $new = $rows(null);
            $out[] = $this->result($name, count($old), count($new), RowHasher::hash($old), RowHasher::hash($new));
        }

        // Old rows: files our accepted submissions (in the old database) point at; new rows: those on disk here.
        $referenced = $this->referencedFiles();
        $present = array_values(array_filter($referenced, fn (string $path) => Storage::disk('public')->exists($path)));
        $out[] = $this->result('Image files on disk here', count($referenced), count($present), RowHasher::hash($referenced), RowHasher::hash($present));

        return $out;
    }

    private function result(string $name, int $oldRows, int $newRows, string $oldHash, string $newHash): array
    {
        return ['section' => $name, 'old_rows' => $oldRows, 'new_rows' => $newRows, 'old_hash' => $oldHash, 'new_hash' => $newHash, 'match' => $oldHash === $newHash];
    }

    /**
     * From each product's latest audit (latest checked_at, then highest ID):
     * how many were audited, failed, have slots not ours or not live, and in
     * which slots.
     *
     * @return list<array<string, mixed>>
     */
    private function figures(?string $connection): array
    {
        $latest = [];
        foreach (DB::connection($connection)->table('image_audits')->orderBy('id')->cursor() as $audit) {
            $current = $latest[$audit->product_id] ?? null;
            if ($current === null || (string) $audit->checked_at >= (string) $current->checked_at) {
                $latest[$audit->product_id] = $audit;
            }
        }

        $count = fn (callable $test) => count(array_filter($latest, $test));
        $rows = [
            ['figure' => 'products audited', 'value' => count($latest)],
            ['figure' => 'latest audit failed to fetch', 'value' => $count(fn ($a) => $a->fetch_error !== null)],
            ['figure' => 'products with slots not ours', 'value' => $count(fn ($a) => (int) $a->unattributed_count > 0)],
            ['figure' => 'products with slots not live', 'value' => $count(fn ($a) => (int) $a->not_live_count > 0)],
        ];
        foreach (array_keys(ImageAuditService::IMAGE_ATTRIBUTE_MAP) as $variant) {
            $has = fn (?string $list) => in_array($variant, explode(',', (string) $list), true);
            $rows[] = ['figure' => "{$variant} not ours", 'value' => $count(fn ($a) => $has($a->unattributed_variants))];
            $rows[] = ['figure' => "{$variant} not live", 'value' => $count(fn ($a) => $has($a->not_live_variants))];
        }

        return array_map(RowHasher::normalise(...), $rows);
    }

    /**
     * Paths on the public disk of the images in the old app's accepted
     * image submissions: the part of each URL after `/storage/`.
     *
     * @return list<string>
     */
    private function referencedFiles(): array
    {
        $paths = [];
        $edits = DB::connection('legacy')->table('listing_edits')
            ->where('status', 'ACCEPTED')
            ->whereIn('attribute', array_values(ImageAuditService::IMAGE_ATTRIBUTE_MAP))
            ->orderBy('id')->pluck('new_value');
        foreach ($edits as $value) {
            foreach (json_decode((string) $value, true) ?: [] as $item) {
                $url = (string) ($item['media_location'] ?? '');
                $path = parse_url($url, PHP_URL_PATH) ?: '';
                if (str_starts_with($path, '/storage/')) {
                    $paths[] = rawurldecode(substr($path, strlen('/storage/')));
                }
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }
}
