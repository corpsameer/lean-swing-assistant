<?php

namespace App\Console\Commands;

use App\Models\Symbol;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class BuildNasdaqUniverse extends Command
{
    protected $signature = 'universe:build-nasdaq';

    protected $description = 'Build symbol universe from Nasdaq Trader symbol directory files';

    private const NASDAQ_URL = 'https://www.nasdaqtrader.com/dynamic/symdir/nasdaqlisted.txt';

    private const OTHER_URL = 'https://www.nasdaqtrader.com/dynamic/symdir/otherlisted.txt';

    public function handle(): int
    {
        $this->line('Source URLs:');
        $this->line('- nasdaqlisted: '.self::NASDAQ_URL);
        $this->line('- otherlisted: '.self::OTHER_URL);

        $nasdaqRows = $this->downloadAndParse(self::NASDAQ_URL);
        $otherRows = $this->downloadAndParse(self::OTHER_URL);

        $includeEtfs = filter_var(env('NASDAQ_UNIVERSE_INCLUDE_ETFS', false), FILTER_VALIDATE_BOOL);
        $includeUnits = filter_var(env('NASDAQ_UNIVERSE_INCLUDE_UNITS', false), FILTER_VALIDATE_BOOL);
        $includeWarrants = filter_var(env('NASDAQ_UNIVERSE_INCLUDE_WARRANTS', false), FILTER_VALIDATE_BOOL);
        $maxSymbols = (int) env('NASDAQ_UNIVERSE_MAX_SYMBOLS', 1000);

        $skippedTestIssues = 0;
        $skippedEtfs = 0;
        $skippedInstrumentType = 0;

        /** @var array<string,array{symbol:string,name:?string,exchange:?string,source_file:string}> $symbolsByTicker */
        $symbolsByTicker = [];

        foreach ($nasdaqRows as $row) {
            $normalized = $this->normalizeNasdaqListedRow($row);
            if ($normalized === null) {
                continue;
            }
            $decision = $this->shouldInclude($normalized['symbol'], $normalized['name'], $normalized['test_issue'], $normalized['etf'], $includeEtfs, $includeUnits, $includeWarrants);
            if ($decision !== 'include') {
                if ($decision === 'test') {
                    $skippedTestIssues++;
                } elseif ($decision === 'etf') {
                    $skippedEtfs++;
                } else {
                    $skippedInstrumentType++;
                }
                continue;
            }

            $symbolsByTicker[$normalized['symbol']] = [
                'symbol' => $normalized['symbol'],
                'name' => $normalized['name'],
                'exchange' => 'Q',
                'source_file' => 'nasdaqlisted',
            ];
        }

        foreach ($otherRows as $row) {
            $normalized = $this->normalizeOtherListedRow($row);
            if ($normalized === null) {
                continue;
            }
            $decision = $this->shouldInclude($normalized['symbol'], $normalized['name'], $normalized['test_issue'], $normalized['etf'], $includeEtfs, $includeUnits, $includeWarrants);
            if ($decision !== 'include') {
                if ($decision === 'test') {
                    $skippedTestIssues++;
                } elseif ($decision === 'etf') {
                    $skippedEtfs++;
                } else {
                    $skippedInstrumentType++;
                }
                continue;
            }

            if (! isset($symbolsByTicker[$normalized['symbol']])) {
                $symbolsByTicker[$normalized['symbol']] = [
                    'symbol' => $normalized['symbol'],
                    'name' => $normalized['name'],
                    'exchange' => $normalized['exchange'],
                    'source_file' => 'otherlisted',
                ];
            }
        }

        ksort($symbolsByTicker);

        $cappedCount = null;
        if ($maxSymbols > 0 && count($symbolsByTicker) > $maxSymbols) {
            $cappedCount = count($symbolsByTicker) - $maxSymbols;
            $symbolsByTicker = array_slice($symbolsByTicker, 0, $maxSymbols, true);
        }

        $columns = Schema::getColumnListing('symbols');
        $hasColumn = static fn (string $column): bool => in_array($column, $columns, true);

        $inserted = 0;
        $updated = 0;
        $truncatedNames = 0;

        foreach ($symbolsByTicker as $payload) {
            $existing = Symbol::query()->where('symbol', $payload['symbol'])->first();
            $normalizedName = $this->normalizeNameForStorage($payload['name']);
            if ($normalizedName !== $payload['name']) {
                $truncatedNames++;
            }
            $safe = ['is_active' => true];

            if ($hasColumn('company_name')) {
                $safe['company_name'] = $normalizedName;
            }
            if ($hasColumn('name')) {
                $safe['name'] = $normalizedName;
            }
            if ($hasColumn('security_name')) {
                $safe['security_name'] = $normalizedName;
            }
            if ($hasColumn('exchange')) {
                $safe['exchange'] = $payload['exchange'];
            }
            if ($hasColumn('source')) {
                $safe['source'] = 'nasdaq_trader';
            }
            if ($hasColumn('source_type')) {
                $safe['source_type'] = 'nasdaq_trader';
            }
            if ($hasColumn('last_seen_at')) {
                $safe['last_seen_at'] = now();
            }
            if ($hasColumn('imported_at')) {
                $safe['imported_at'] = now();
            }

            if ($existing === null) {
                $row = array_merge(['symbol' => $payload['symbol']], $safe);
                Symbol::query()->create($row);
                $inserted++;
            } else {
                $existing->fill($safe);
                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                }
            }
        }

        $symbolsList = array_values($symbolsByTicker);
        Storage::disk('local')->put('nasdaq_universe.json', json_encode([
            'source' => 'nasdaq_trader',
            'fetched_at_utc' => now('UTC')->toIso8601String(),
            'sources' => [
                'nasdaqlisted' => self::NASDAQ_URL,
                'otherlisted' => self::OTHER_URL,
            ],
            'counts' => [
                'nasdaq_rows' => count($nasdaqRows),
                'other_rows' => count($otherRows),
                'filtered_symbols' => count($symbolsList),
                'inserted' => $inserted,
                'updated' => $updated,
            ],
            'symbols' => $symbolsList,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->line('Nasdaq listed rows read: '.count($nasdaqRows));
        $this->line('Other listed rows read: '.count($otherRows));
        $this->line('Symbols after filtering: '.count($symbolsList));
        $this->line('Symbols inserted: '.$inserted);
        $this->line('Symbols updated: '.$updated);
        $this->line('Skipped test issues: '.$skippedTestIssues);
        $this->line('Skipped ETFs: '.$skippedEtfs);
        $this->line('Skipped units/warrants/rights/etc.: '.$skippedInstrumentType);
        if ($cappedCount !== null) {
            $this->line('Capped count: '.$cappedCount);
        }
        $this->line('Names truncated to fit DB columns: '.$truncatedNames);

        return $symbolsList === [] ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int,array<string,string>> */
    private function downloadAndParse(string $url): array
    {
        $response = Http::timeout(30)->get($url);
        if (! $response->successful()) {
            throw new \RuntimeException('Failed to download '.$url.' status='.$response->status());
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($response->body())) ?: [];
        if ($lines === []) {
            return [];
        }

        $header = str_getcsv((string) array_shift($lines), '|');
        $rows = [];

        foreach ($lines as $line) {
            if ($line === '' || str_starts_with($line, 'File Creation Time')) {
                continue;
            }
            $fields = str_getcsv($line, '|');
            if (count($fields) < 2) {
                continue;
            }

            $assoc = [];
            foreach ($header as $index => $name) {
                $assoc[trim((string) $name)] = isset($fields[$index]) ? trim((string) $fields[$index]) : '';
            }

            $rows[] = $assoc;
        }

        return $rows;
    }

    /** @param array<string,string> $row */
    private function normalizeNasdaqListedRow(array $row): ?array
    {
        $symbol = strtoupper(trim((string) ($row['Symbol'] ?? '')));
        if ($symbol === '') {
            return null;
        }

        return [
            'symbol' => $symbol,
            'name' => trim((string) ($row['Security Name'] ?? '')) ?: null,
            'test_issue' => strtoupper(trim((string) ($row['Test Issue'] ?? ''))),
            'etf' => strtoupper(trim((string) ($row['ETF'] ?? ''))),
        ];
    }

    /** @param array<string,string> $row */
    private function normalizeOtherListedRow(array $row): ?array
    {
        $symbol = strtoupper(trim((string) ($row['ACT Symbol'] ?? '')));
        if ($symbol === '') {
            return null;
        }

        return [
            'symbol' => $symbol,
            'name' => trim((string) ($row['Security Name'] ?? '')) ?: null,
            'exchange' => strtoupper(trim((string) ($row['Exchange'] ?? ''))),
            'test_issue' => strtoupper(trim((string) ($row['Test Issue'] ?? ''))),
            'etf' => strtoupper(trim((string) ($row['ETF'] ?? ''))),
        ];
    }

    private function shouldInclude(string $symbol, ?string $name, string $testIssue, string $etfFlag, bool $includeEtfs, bool $includeUnits, bool $includeWarrants): string
    {
        if ($testIssue !== 'N') {
            return 'test';
        }

        if (! $includeEtfs && $etfFlag !== 'N') {
            return 'etf';
        }

        $nameLc = strtolower((string) $name);
        $symbolLc = strtolower($symbol);

        $alwaysExcludePatterns = [
            ' right', ' rights', ' preferred', ' depositary', ' note', ' notes', ' etn', ' fund', ' closed-end', ' certificate',
        ];
        foreach ($alwaysExcludePatterns as $pattern) {
            if (str_contains($nameLc, $pattern)) {
                return 'instrument';
            }
        }

        if (! $includeUnits && (str_contains($nameLc, ' unit') || str_ends_with($symbolLc, 'u'))) {
            return 'instrument';
        }

        if (! $includeWarrants && (str_contains($nameLc, ' warrant') || str_ends_with($symbolLc, 'w'))) {
            return 'instrument';
        }

        return 'include';
    }

    private function normalizeNameForStorage(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return mb_substr(trim($name), 0, 255);
    }

}

