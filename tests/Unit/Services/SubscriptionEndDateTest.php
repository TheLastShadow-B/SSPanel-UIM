<?php

declare(strict_types=1);

use App\Services\SubscriptionService;
use Carbon\Carbon;

if (! function_exists('renewalChainEnds')) {
    /**
     * Renew $periods times from $start the way advanceRenewedPeriod does
     * (next start = previous end + 1 day) and return each period's end date.
     *
     * @return list<string>
     */
    function renewalChainEnds(string $start, string $cycle, int $anchorDay, int $periods): array
    {
        $ends = [];
        $current = Carbon::parse($start);

        for ($i = 0; $i < $periods; $i++) {
            $end = SubscriptionService::calculateEndDate($current, $cycle, $anchorDay);
            $ends[] = $end->format('Y-m-d');
            $current = $end->copy()->addDay();
        }

        return $ends;
    }
}

it('returns to a 31st anchor after short months, so 12 monthly renewals cover one calendar year', function () {
    expect(renewalChainEnds('2026-01-31', 'month', 31, 12))->toBe([
        '2026-02-27',
        '2026-03-30',
        '2026-04-29',
        '2026-05-30',
        '2026-06-29',
        '2026-07-30',
        '2026-08-30',
        '2026-09-29',
        '2026-10-30',
        '2026-11-29',
        '2026-12-30',
        '2027-01-30',
    ]);
});

it('returns to a 30th anchor after February', function () {
    expect(renewalChainEnds('2026-01-30', 'month', 30, 3))->toBe([
        '2026-02-27',
        '2026-03-29',
        '2026-04-29',
    ]);
});

it('keeps a mid-month anchor on the same day every month', function () {
    expect(renewalChainEnds('2026-10-14', 'month', 14, 5))->toBe([
        '2026-11-13',
        '2026-12-13',
        '2027-01-13',
        '2027-02-13',
        '2027-03-13',
    ]);
});

it('ends four quarterly renewals on the same day as one yearly period', function () {
    expect(renewalChainEnds('2026-01-31', 'quarter', 31, 4))->toBe([
        '2026-04-29',
        '2026-07-30',
        '2026-10-30',
        '2027-01-30',
    ]);
    expect(SubscriptionService::calculateEndDate(Carbon::parse('2026-01-31'), 'year', 31)->format('Y-m-d'))
        ->toBe('2027-01-30');
});

it('gives a Feb 29 yearly anchor 365 days in common years and returns to Feb 29 in the next leap year', function () {
    expect(renewalChainEnds('2028-02-29', 'year', 29, 4))->toBe([
        '2029-02-27',
        '2030-02-27',
        '2031-02-27',
        '2032-02-28',
    ]);
});

it('counts a yearly period that spans Feb 29 as 366 days', function () {
    expect(SubscriptionService::calculateEndDate(Carbon::parse('2027-03-01'), 'year', 1)->format('Y-m-d'))
        ->toBe('2028-02-29');
});

it('defaults the anchor to the start day when none is given', function () {
    // A new subscription starting on the 28th is anchored on the 28th, not the 31st.
    expect(SubscriptionService::calculateEndDate(Carbon::parse('2026-02-28'), 'month')->format('Y-m-d'))
        ->toBe('2026-03-27');
    expect(SubscriptionService::calculateEndDate(Carbon::parse('2026-02-28'), 'month', 31)->format('Y-m-d'))
        ->toBe('2026-03-30');
});

it('heals a subscription that already drifted off its month-end anchor', function () {
    // Anchored on the 31st but stuck on the 28th by the old calculation: the next
    // period is three days longer, then the chain is back on the month end.
    expect(renewalChainEnds('2026-05-28', 'month', 31, 3))->toBe([
        '2026-06-29',
        '2026-07-30',
        '2026-08-30',
    ]);
});

it('never shortens a period whose start is past the anchor day', function () {
    // e.g. an admin moved end_date to the 20th on a subscription anchored on the 5th.
    expect(renewalChainEnds('2026-03-21', 'month', 5, 2))->toBe([
        '2026-04-20',
        '2026-05-20',
    ]);
});

it('only moves up to the anchor when it is at most three days away', function () {
    // Three days is the most a month-end clamp can take (31st -> 28th).
    expect(SubscriptionService::calculateEndDate(Carbon::parse('2026-03-02'), 'month', 5)->format('Y-m-d'))
        ->toBe('2026-04-04');
    expect(SubscriptionService::calculateEndDate(Carbon::parse('2026-03-01'), 'month', 5)->format('Y-m-d'))
        ->toBe('2026-03-31');
    expect(SubscriptionService::calculateEndDate(Carbon::parse('2026-11-01'), 'month', 31)->format('Y-m-d'))
        ->toBe('2026-11-30');
});

it('does not modify the start date passed in', function () {
    $start = Carbon::parse('2026-02-28');

    SubscriptionService::calculateEndDate($start, 'month', 31);

    expect($start->format('Y-m-d'))->toBe('2026-02-28');
});
