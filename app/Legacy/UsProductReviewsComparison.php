<?php

namespace App\Legacy;

use App\Services\Amazon\SpApiClient;
use App\Services\Feedback\FeedbackService;
use Illuminate\Support\Facades\DB;

/**
 * `compare:us-product-reviews`: runs the page's own summary
 * (FeedbackService::getLatestSummary) against the old database and this
 * one, and hashes every row of the six tables, compressed raw responses
 * included (plan section 2, rule 5). The import mirrors the tables, so
 * right after it everything should match exactly. Counts and hashes only.
 */
final class UsProductReviewsComparison
{
    /**
     * @return list<array{section: string, old_rows: int, new_rows: int, old_hash: string, new_hash: string, match: bool}>
     */
    public function run(): array
    {
        $sections = [
            'Summary page' => fn (?string $connection) => array_map(
                fn (array $row) => RowHasher::normalise([...$row, 'top_negative_topic' => (array) ($row['top_negative_topic'] ?? []), 'top_positive_topic' => (array) ($row['top_positive_topic'] ?? [])]),
                (new FeedbackService(app(SpApiClient::class), $connection))->getLatestSummary(),
            ),
        ];

        foreach (MirrorImports::US_PRODUCT_REVIEWS as $table => $columns) {
            $sections[$table] = fn (?string $connection) => DB::connection($connection)->table($table)->orderBy('id')->get($columns)
                ->map(function ($row) {
                    $row = (array) $row;
                    if (array_key_exists('raw_response_gz', $row)) {
                        $row['raw_response_gz'] = $row['raw_response_gz'] === null ? null : md5($row['raw_response_gz']);
                    }

                    return RowHasher::normalise($row);
                })->all();
        }

        $out = [];
        foreach ($sections as $name => $rows) {
            $old = $rows('legacy');
            $new = $rows(null);
            $oldHash = RowHasher::hash($old);
            $newHash = RowHasher::hash($new);
            $out[] = ['section' => $name, 'old_rows' => count($old), 'new_rows' => count($new), 'old_hash' => $oldHash, 'new_hash' => $newHash, 'match' => $oldHash === $newHash];
        }

        return $out;
    }
}
