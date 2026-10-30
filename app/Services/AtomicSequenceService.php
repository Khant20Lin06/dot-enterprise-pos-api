<?php

namespace App\Services;

use App\Domains\SalesAndPos\Models\SystemSequence;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class AtomicSequenceService
{
    /**
     * Generate next atomic sequential number (e.g. INV-20261030-0001).
     * Concurrency-safe against race conditions across distributed POS terminals.
     */
    public function getNextSequence(string $prefix, ?string $date = null): string
    {
        $dateKey = $date ? Carbon::parse($date)->format('Ymd') : Carbon::today()->format('Ymd');

        // 1. Try Redis Atomic INCR if Redis is enabled & reachable
        if (! app()->runningUnitTests() && config('database.redis.default.host')) {
            try {
                $redisKey = "pos:seq:{$prefix}:{$dateKey}";
                $nextVal = Redis::incr($redisKey);

                if ($nextVal === 1) {
                    // Set key to expire after 48 hours to prevent unbounded memory growth
                    Redis::expire($redisKey, 172800);
                }

                return sprintf('%s-%s-%04d', strtoupper($prefix), $dateKey, $nextVal);
            } catch (Throwable $e) {
                Log::warning('[AtomicSequenceService] Redis INCR failed, falling back to DB lock: ' . $e->getMessage());
            }
        }

        // 2. Database Pessimistic Row-Locking Sequence Generator (ACID Guaranteed)
        return DB::transaction(function () use ($prefix, $dateKey) {
            $sequence = SystemSequence::where('prefix', $prefix)
                ->where('date_key', $dateKey)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                $sequence = SystemSequence::create([
                    'prefix' => $prefix,
                    'date_key' => $dateKey,
                    'current_value' => 0,
                ]);

                // Re-lock
                $sequence = SystemSequence::where('id', $sequence->id)
                    ->lockForUpdate()
                    ->first();
            }

            $nextVal = $sequence->current_value + 1;
            $sequence->update(['current_value' => $nextVal]);

            return sprintf('%s-%s-%04d', strtoupper($prefix), $dateKey, $nextVal);
        });
    }
}
