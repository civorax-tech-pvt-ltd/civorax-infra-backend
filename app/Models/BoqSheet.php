<?php

namespace App\Models;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * BOQ spreadsheets (CSV, which Excel opens and saves): export and import of the BOQ library and of a
 * project's BOQ. Column names are matched loosely ("Qty", "Quantity", "Rate (Rs)", "Particulars", …).
 */
class BoqSheet
{
    /**
     * Normalised header => our field.
     *
     * @var array<string, string>
     */
    protected const COLUMNS = [
        'code' => 'code', 'itemcode' => 'code', 'itemno' => 'code', 'ref' => 'code', 'refno' => 'code',
        'category' => 'category', 'group' => 'category', 'trade' => 'category',
        'description' => 'description', 'desc' => 'description', 'particulars' => 'description', 'itemdescription' => 'description',
        'descriptionofwork' => 'description', 'specification' => 'description', 'work' => 'description',
        'unit' => 'unit', 'uom' => 'unit', 'units' => 'unit',
        'quantity' => 'quantity', 'qty' => 'quantity', 'quantityforthisproject' => 'quantity',
        'rate' => 'rate', 'raters' => 'rate', 'ratenpr' => 'rate', 'unitrate' => 'rate', 'defaultrate' => 'rate', 'rateforthisproject' => 'rate',
        'amount' => 'amount', 'amountrs' => 'amount', 'amountnpr' => 'amount', 'total' => 'amount',
        'rateincludessuppliervat' => 'vat', 'rateincludesvat' => 'vat', 'includesvat' => 'vat', 'inclvat' => 'vat', 'vat' => 'vat',
        'active' => 'active',
        'plannedstart' => 'planned_start', 'startdate' => 'planned_start', 'start' => 'planned_start',
        'plannedend' => 'planned_end', 'enddate' => 'planned_end', 'end' => 'planned_end', 'finish' => 'planned_end',
        'variation' => 'variation', 'extrawork' => 'variation', 'extraworkvariation' => 'variation',
    ];

    public const LIBRARY_HEADERS = ['Code', 'Category', 'Description', 'Unit', 'Rate', 'Rate includes supplier VAT', 'Active'];

    public const PROJECT_HEADERS = ['Code', 'Category', 'Description', 'Unit', 'Quantity', 'Rate', 'Amount', 'Rate includes supplier VAT', 'Planned start', 'Planned end', 'Variation'];

    // ── Export ────────────────────────────────────────────────────────────────

    public static function exportLibrary(): StreamedResponse
    {
        $rows = BoqMasterItem::query()->orderBy('category')->orderBy('code')->orderBy('description')->get()
            ->map(fn (BoqMasterItem $item): array => [
                $item->code,
                BoqMasterItem::categoryLabel($item->category),
                $item->description,
                $item->unit,
                static::number($item->default_rate),
                $item->rate_includes_vat ? 'Yes' : 'No',
                $item->is_active ? 'Yes' : 'No',
            ]);

        return static::download('boq-library-'.now()->format('Y-m-d').'.csv', self::LIBRARY_HEADERS, $rows);
    }

    public static function exportProject(Project $project): StreamedResponse
    {
        $categories = BoqMasterItem::categoryOptions();

        $rows = $project->boqItems()->with(['masterItem', 'latestApprovedMeasurement'])->orderBy('sort')->get()
            ->map(fn (BoqItem $item): array => [
                $item->code,
                $categories[$item->masterItem?->category] ?? $item->masterItem?->category,
                $item->description,
                $item->unit,
                static::number($item->quantity, 3),
                static::number($item->rate),
                static::number($item->planned_value),
                $item->rate_includes_vat ? 'Yes' : 'No',
                $item->planned_start?->toDateString(),
                $item->planned_end?->toDateString(),
                $item->is_variation ? 'Yes' : 'No',
                static::number($item->executedQuantity(), 3),
                round($item->progressPercent(), 1),
            ]);

        return static::download(
            'boq-'.Str::slug($project->title).'-'.now()->format('Y-m-d').'.csv',
            [...self::PROJECT_HEADERS, 'Done to date', '% complete'],
            $rows,
        );
    }

    /**
     * An empty sheet with the right columns and one example row.
     */
    public static function template(string $kind): StreamedResponse
    {
        $spec = 'RCC 1:1.5:3 (M20) in slab including shuttering and curing';

        return $kind === 'project'
            ? static::download('boq-project-template.csv', self::PROJECT_HEADERS, [['RCC-01', 'Concrete / RCC', $spec, 'm³', '12.5', '15000', '', 'No', '', '', 'No']])
            : static::download('boq-library-template.csv', self::LIBRARY_HEADERS, [['RCC-01', 'Concrete / RCC', $spec, 'm³', '15000', 'No', 'Yes']]);
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    protected static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 marker so Excel shows m², m³ and Nepali text correctly
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected static function number(float|string|null $value, int $decimals = 2): string
    {
        return rtrim(rtrim(number_format((float) $value, $decimals, '.', ''), '0'), '.');
    }

    // ── Import ────────────────────────────────────────────────────────────────

    /**
     * Rows of a CSV file keyed by our field names; columns we don't know are ignored.
     *
     * @return list<array<string, string>>
     */
    public static function read(string $path): array
    {
        $handle = fopen($path, 'r');
        $first = (string) fgets($handle);
        rewind($handle);

        // Excel saves with ";" in some regions.
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn (string $d): int => substr_count($first, $d))->first();

        $header = fgetcsv($handle, null, $delimiter) ?: [];
        $fields = array_map(function ($name): ?string {
            $key = preg_replace('/[^a-z]/', '', strtolower(str_replace("\xEF\xBB\xBF", '', (string) $name)));

            return self::COLUMNS[$key] ?? null;
        }, $header);

        $rows = [];

        while (($cells = fgetcsv($handle, null, $delimiter)) !== false) {
            $row = [];

            foreach ($fields as $i => $field) {
                if ($field !== null && ! isset($row[$field])) {
                    $row[$field] = trim((string) ($cells[$i] ?? ''));
                }
            }

            if (array_filter($row, 'filled') !== []) {
                $rows[] = $row;
            }
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Adds new library items and updates existing ones (matched by code, else by description and unit).
     *
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, skipped: list<string>}
     */
    public static function importLibrary(array $rows, User $by): array
    {
        $result = ['created' => 0, 'updated' => 0, 'skipped' => []];

        DB::transaction(function () use ($rows, $by, &$result): void {
            foreach ($rows as $i => $row) {
                $line = $i + 2; // row 1 is the header
                $item = static::findLibraryItem($row);

                if ($item === null && (blank($row['description'] ?? null) || blank($row['unit'] ?? null))) {
                    $result['skipped'][] = "Row {$line}: description and unit are needed for a new item.";

                    continue;
                }

                $item ??= new BoqMasterItem(['created_by' => $by->getKey(), 'default_rate' => 0]);

                $item->fill(array_filter([
                    'code' => $row['code'] ?? null,
                    'description' => $row['description'] ?? null,
                    'unit' => $row['unit'] ?? null,
                    'category' => static::categoryKey($row['category'] ?? null),
                    'default_rate' => static::parseNumber($row['rate'] ?? null),
                    'rate_includes_vat' => static::parseBool($row['vat'] ?? null),
                    'is_active' => static::parseBool($row['active'] ?? null),
                ], fn ($value): bool => $value !== null && $value !== ''));

                if (! $item->exists) {
                    $result['created']++;
                } elseif ($item->isDirty()) {
                    $result['updated']++;
                }

                $item->save();
            }
        });

        return $result;
    }

    /**
     * Adds lines to a project's BOQ (or updates the line already there for the same item). Items not yet in
     * the library are added to it, like "New item". Rate comes from the sheet, else amount ÷ quantity,
     * else the library rate.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{created: int, updated: int, library: int, skipped: list<string>}
     */
    public static function importProject(Project $project, array $rows, User $by): array
    {
        $result = ['created' => 0, 'updated' => 0, 'library' => 0, 'skipped' => []];

        DB::transaction(function () use ($project, $rows, $by, &$result): void {
            $sort = (int) $project->boqItems()->max('sort');

            foreach ($rows as $i => $row) {
                $line = $i + 2;
                $quantity = static::parseNumber($row['quantity'] ?? null);

                if ($quantity === null || $quantity <= 0) {
                    $result['skipped'][] = "Row {$line}: no quantity.";

                    continue;
                }

                $master = static::findLibraryItem($row);

                if ($master === null) {
                    if (blank($row['description'] ?? null) || blank($row['unit'] ?? null)) {
                        $result['skipped'][] = "Row {$line}: not in the library, so description and unit are needed.";

                        continue;
                    }

                    $master = BoqMasterItem::create([
                        'code' => ($row['code'] ?? '') ?: null,
                        'description' => $row['description'],
                        'unit' => $row['unit'],
                        'category' => static::categoryKey($row['category'] ?? null),
                        'default_rate' => static::rateFor($row, $quantity) ?? 0,
                        'rate_includes_vat' => (bool) static::parseBool($row['vat'] ?? null),
                        'created_by' => $by->getKey(),
                    ]);
                    $result['library']++;
                }

                $values = array_filter([
                    'unit' => ($row['unit'] ?? '') ?: $master->unit,
                    'quantity' => $quantity,
                    'rate' => static::rateFor($row, $quantity) ?? (float) $master->default_rate,
                    'rate_includes_vat' => static::parseBool($row['vat'] ?? null),
                    'planned_start' => static::parseDate($row['planned_start'] ?? null),
                    'planned_end' => static::parseDate($row['planned_end'] ?? null),
                    'is_variation' => static::parseBool($row['variation'] ?? null),
                ], fn ($value): bool => $value !== null);

                $existing = $project->boqItems()->where('master_item_id', $master->id)->first();

                if ($existing) {
                    $existing->update($values);
                    $result['updated']++;

                    continue;
                }

                $project->boqItems()->create([
                    'master_item_id' => $master->id,
                    'code' => $master->code,
                    'description' => $master->description,
                    'norms' => $master->norms,
                    'rate_includes_vat' => $master->rate_includes_vat,
                    'sort' => ++$sort,
                    'created_by' => $by->getKey(),
                    ...$values,
                ]);
                $result['created']++;
            }
        });

        return $result;
    }

    /**
     * @param  array<string, string>  $row
     */
    protected static function findLibraryItem(array $row): ?BoqMasterItem
    {
        if (filled($row['code'] ?? null)) {
            return BoqMasterItem::query()->where('code', $row['code'])->first();
        }

        if (filled($row['description'] ?? null)) {
            return BoqMasterItem::query()
                ->where('description', $row['description'])
                ->when(filled($row['unit'] ?? null), fn ($q) => $q->where('unit', $row['unit']))
                ->first();
        }

        return null;
    }

    /**
     * @param  array<string, string>  $row
     */
    protected static function rateFor(array $row, float $quantity): ?float
    {
        $rate = static::parseNumber($row['rate'] ?? null);
        $amount = static::parseNumber($row['amount'] ?? null);

        return $rate ?? ($amount !== null && $quantity > 0 ? round($amount / $quantity, 2) : null);
    }

    protected static function categoryKey(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $options = BoqMasterItem::categoryOptions();

        if (isset($options[$value])) {
            return $value;
        }

        return BoqCategory::findOrCreateByName($value)->key;
    }

    protected static function parseNumber(?string $value): ?float
    {
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(['Rs', 'NPR', ','], '', (string) $value));

        return is_numeric($clean) ? (float) $clean : null;
    }

    protected static function parseBool(?string $value): ?bool
    {
        return match (strtolower(trim((string) $value))) {
            'yes', 'y', '1', 'true', 'x', '✓' => true,
            'no', 'n', '0', 'false' => false,
            default => null,
        };
    }

    protected static function parseDate(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
