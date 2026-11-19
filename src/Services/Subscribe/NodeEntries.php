<?php

declare(strict_types=1);

namespace App\Services\Subscribe;

use Illuminate\Support\Collection;
use function array_values;
use function is_array;
use function json_decode;
use function json_encode;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Lets one node be reached through several entries (relays in front of it).
 *
 * A node's custom_config may carry
 *
 *     "entries": [
 *         {"label": "A", "server": "entry-a.example.com", "port": 20001},
 *         {"label": "B", "server": "entry-b.example.com", "port": 20002}
 *     ]
 *
 * A subscription then lists the node once per entry, so a user can switch to
 * another entry when one is slow. Each line is a copy of the node with
 *
 *   - "<name> · 线路<label>" as its name,
 *   - the entry's server as its server,
 *   - the entry's port as custom_config.offset_port_user. With no port, or an
 *     empty one, the line keeps the node's own port. Plain Shadowsocks nodes
 *     (sort 0) listen on the user's port, so an entry port has no effect on
 *     them.
 *
 * The copies exist only in memory. They keep the node's id and are never
 * saved, so traffic, device counts and the node pages still see one node.
 */
final class NodeEntries
{
    private const KEY = 'entries';
    private const NAME_SEPARATOR = ' · 线路';

    /**
     * Replaces every node that has usable entries with one copy per entry,
     * in place, keeping the order. All other nodes pass through untouched.
     */
    public static function expand(Collection $nodes): Collection
    {
        $lines = [];

        foreach ($nodes as $node) {
            foreach (self::linesOf($node) as $line) {
                $lines[] = $line;
            }
        }

        return new Collection($lines);
    }

    private static function linesOf($node): array
    {
        $custom = json_decode((string) $node->custom_config, true);
        $entries = is_array($custom) ? self::entriesOf($custom[self::KEY] ?? null) : [];

        if ($entries === []) {
            return [$node];
        }

        unset($custom[self::KEY]);
        $lines = [];

        foreach ($entries as $entry) {
            $lines[] = self::lineFor($node, $custom, $entry);
        }

        return $lines;
    }

    /**
     * The usable entries of a custom_config.entries value. A malformed entry
     * is dropped, and so is one that repeats an earlier label: two lines with
     * the same name make Clash and Surge reject the whole profile.
     *
     * @return array<int, NodeEntry>
     */
    private static function entriesOf(mixed $list): array
    {
        $entries = [];

        foreach (is_array($list) ? $list : [] as $item) {
            $entry = NodeEntry::parse($item);

            if ($entry !== null) {
                $entries[$entry->label] ??= $entry;
            }
        }

        return array_values($entries);
    }

    private static function lineFor($node, array $custom, NodeEntry $entry)
    {
        if ($entry->port !== null) {
            $custom['offset_port_user'] = $entry->port;
        }

        $line = clone $node;
        $line->name = $node->name . self::NAME_SEPARATOR . $entry->label;
        $line->server = $entry->server;
        $line->custom_config = json_encode($custom, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $line;
    }
}
