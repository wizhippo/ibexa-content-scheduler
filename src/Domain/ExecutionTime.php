<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Domain;

final class ExecutionTime
{
    public static function parseLocal(string $date, string $time, string $timezone, string $occurrence, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $choices = self::occurrences($date, $time, $timezone);
        if (!in_array($occurrence, ['', 'earlier', 'later'], true)) {
            throw new ScheduleException('Choose the first or second occurrence of this time.');
        }
        if (count($choices) === 2 && $occurrence === '') {
            throw new ScheduleException('The clocks go back on this date, so this time occurs twice. Choose the first or second occurrence below.');
        }
        $chosen = $choices[count($choices) === 2 && $occurrence === 'later' ? 1 : 0];
        if ($chosen <= $now) {
            throw new ScheduleException('Choose a future date and time.');
        }

        return $chosen;
    }

    /** Resolve wall time using the timezone's actual rules, including non-hour DST changes. */
    public static function occurrences(string $date, string $time, string $timezone): array
    {
        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new ScheduleException('Choose a valid timezone.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            throw new ScheduleException('Choose a valid date.');
        }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time)) {
            throw new ScheduleException('Choose a valid time, with minutes between 00 and 59.');
        }
        $utc = new \DateTimeZone('UTC');
        $wall = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $date.'T'.$time, $utc);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($wall === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $wall->format('Y-m-d\TH:i') !== $date.'T'.$time) {
            throw new ScheduleException('Choose a valid date.');
        }
        $timestamp = $wall->getTimestamp();
        $offsets = [$zone->getOffset($wall)];
        foreach ($zone->getTransitions($timestamp - 3 * 86400, $timestamp + 3 * 86400) ?: [] as $transition) {
            $offsets[] = $transition['offset'];
        }
        $choices = [];
        foreach (array_unique($offsets) as $offset) {
            $candidate = new \DateTimeImmutable('@'.($timestamp - $offset))->setTimezone($utc);
            if ($candidate->setTimezone($zone)->format('Y-m-d\TH:i') === $date.'T'.$time) {
                $choices[$candidate->getTimestamp()] = $candidate;
            }
        }
        ksort($choices, SORT_NUMERIC);
        if ($choices === []) {
            throw new ScheduleException('This time is skipped when the clocks move forward. Choose another time.');
        }

        return array_values($choices);
    }

    /** An explicit offset disambiguates repeated local times at daylight-saving boundaries. */
    public static function parse(string $value, string $timezone, \DateTimeImmutable $now): \DateTimeImmutable
    {
        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Exception) {
            throw new ScheduleException('Choose a valid timezone.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $value)) {
            throw new ScheduleException('Enter a date and time with its UTC offset, for example 2026-11-15T15:50-05:00.');
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:iP', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($time === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))
            || $time->format('Y-m-d\TH:iP') !== $value || $time->setTimezone($zone)->format('Y-m-d\TH:iP') !== $value) {
            throw new ScheduleException('The date, time, and UTC offset must match the selected timezone.');
        }
        if ($time <= $now) {
            throw new ScheduleException('Choose a future time.');
        }

        return $time->setTimezone(new \DateTimeZone('UTC'));
    }
}
