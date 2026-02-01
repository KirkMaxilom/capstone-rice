<?php

/**
 * Calculates a simple moving average forecast for the next 3 periods.
 */
function calculateForecastFromMonthlySales(array $salesData, int $window = 3): array
{
    // If no data, return flat zero forecast
    if (count($salesData) === 0) {
        return [0, 0, 0];
    }

    // If not enough data for window, use last known value as baseline
    if (count($salesData) < $window) {
        $baseline = end($salesData);
        return [$baseline, $baseline, $baseline];
    }

    $forecast = [];
    $series = $salesData;

    // Generate 3 months of forecast
    for ($i = 0; $i < 3; $i++) {
        $slice = array_slice($series, -$window);
        $avg = array_sum($slice) / count($slice);
        $forecast[] = round($avg, 2);
        $series[] = $avg; // Append forecast to series for next iteration
    }

    return $forecast;
}

/**
 * Calculates Days of Cover and determines risk level.
 */
function calculateDaysOfCover(float $currentStockKg, float $forecastMonthlyKg, int $leadTimeDays, int $safetyDays): array {
    // Convert monthly forecast -> daily (avoid division by zero)
    $forecastDaily = max($forecastMonthlyKg / 30, 0.01);
    $daysOfCover = $currentStockKg / $forecastDaily;

    $danger = $leadTimeDays;
    $warning = $leadTimeDays + $safetyDays;

    if ($daysOfCover < $danger) {
        $risk = 'red';
    } elseif ($daysOfCover < $warning) {
        $risk = 'yellow';
    } else {
        $risk = 'green';
    }

    return [
        'forecast_daily' => round($forecastDaily, 2),
        'days_of_cover'  => round($daysOfCover, 1),
        'risk_band'      => $risk
    ];
}