<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RealtimeEventService
{
    private const STORE = 'file';
    private const LIMIT = 200;
    private const TTL_SECONDS = 3600;

    public function publish(string $type, array $payload = [], array $channels = ['global'], ?string $aggregateId = null): array
    {
        $event = [
            'id' => (int) now()->getPreciseTimestamp(6),
            'type' => $type,
            'payload' => $payload,
            'occurred_at' => now()->toIso8601String(),
            'aggregate_id' => $aggregateId ?? (string) Str::uuid(),
        ];

        foreach (array_unique($channels) as $channel) {
            $this->appendToChannel($channel, $event);
        }

        return $event;
    }

    public function eventsForChannels(array $channels, int $lastEventId = 0): array
    {
        $events = collect();

        foreach (array_unique($channels) as $channel) {
            $channelEvents = Cache::store(self::STORE)->get($this->channelKey($channel), []);
            $events = $events->merge($channelEvents);
        }

        return $events
            ->unique('id')
            ->sortBy('id')
            ->filter(fn (array $event) => $event['id'] > $lastEventId)
            ->values()
            ->all();
    }

    private function appendToChannel(string $channel, array $event): void
    {
        $key = $this->channelKey($channel);
        $events = Cache::store(self::STORE)->get($key, []);
        $events[] = $event;

        if (count($events) > self::LIMIT) {
            $events = array_slice($events, -self::LIMIT);
        }

        Cache::store(self::STORE)->put($key, $events, now()->addSeconds(self::TTL_SECONDS));
    }

    private function channelKey(string $channel): string
    {
        return 'realtime:channel:' . $channel;
    }
}
