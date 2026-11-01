<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentScheduler\Tests;

use PHPUnit\Framework\TestCase;
use Wizhippo\IbexaContentScheduler\Domain\ExecutionTime;
use Wizhippo\IbexaContentScheduler\Domain\ScheduleException;

final class ExecutionTimeTest extends TestCase
{
    /** @dataProvider localTimes */
    public function testLocalTimesResolveWithoutTypingAnOffset(string $date, string $time, string $timezone, string $expected): void
    {
        $resolved = ExecutionTime::parseLocal($date, $time, $timezone, '', new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        self::assertSame($expected, $resolved->format('Y-m-d\TH:i:s\Z'));
        self::assertSame('UTC', $resolved->getTimezone()->getName());
    }

    public static function localTimes(): array
    {
        return [
            ['2026-10-31', '21:30', 'America/Toronto', '2026-11-01T01:30:00Z'],
            ['2026-12-01', '21:30', 'America/Toronto', '2026-12-02T02:30:00Z'],
            ['2026-12-01', '09:15', 'Asia/Kathmandu', '2026-12-01T03:30:00Z'],
            ['2026-12-01', '09:15', '+05:45', '2026-12-01T03:30:00Z'],
        ];
    }

    public function testRepeatedLocalTimeRequiresAnExplicitOccurrence(): void
    {
        $this->expectException(ScheduleException::class);
        $this->expectExceptionMessage('occurs twice');
        ExecutionTime::parseLocal('2026-11-01', '01:30', 'America/Toronto', '', new \DateTimeImmutable('2026-01-01'));
    }

    public function testBothRepeatedLocalTimesCanBeChosen(): void
    {
        $now = new \DateTimeImmutable('2026-01-01');
        $earlier = ExecutionTime::parseLocal('2026-11-01', '01:30', 'America/Toronto', 'earlier', $now);
        $later = ExecutionTime::parseLocal('2026-11-01', '01:30', 'America/Toronto', 'later', $now);
        self::assertSame('2026-11-01T05:30:00+00:00', $earlier->format('c'));
        self::assertSame(3600, $later->getTimestamp() - $earlier->getTimestamp());
    }

    public function testHalfHourDaylightSavingTransitionsAreHandled(): void
    {
        $now = new \DateTimeImmutable('2026-01-01');
        $earlier = ExecutionTime::parseLocal('2026-04-05', '01:45', 'Australia/Lord_Howe', 'earlier', $now);
        $later = ExecutionTime::parseLocal('2026-04-05', '01:45', 'Australia/Lord_Howe', 'later', $now);
        self::assertSame(1800, $later->getTimestamp() - $earlier->getTimestamp());
    }

    /** @dataProvider invalidLocalTimes */
    public function testInvalidLocalTimesAreRejected(string $date, string $time, string $zone, string $occurrence): void
    {
        $this->expectException(ScheduleException::class);
        ExecutionTime::parseLocal($date, $time, $zone, $occurrence, new \DateTimeImmutable('2026-01-01'));
    }

    public static function invalidLocalTimes(): array
    {
        return [
            ['2026-03-08', '02:30', 'America/Toronto', ''],
            ['2026-10-31', '21:84', 'America/Toronto', ''],
            ['2026-02-30', '12:00', 'America/Toronto', ''],
            ['2026-11-01', '01:30', 'Unknown/Timezone', ''],
            ['2026-11-01', '01:30', 'America/Toronto', 'invalid'],
            ['2025-11-01', '12:00', 'America/Toronto', ''],
        ];
    }

    public function testRepeatedHourRequiresAndPreservesTheSelectedOffset(): void
    {
        $now = new \DateTimeImmutable('2026-10-01T00:00:00Z');
        $first = ExecutionTime::parse('2026-11-01T01:30-04:00', 'America/Toronto', $now);
        $second = ExecutionTime::parse('2026-11-01T01:30-05:00', 'America/Toronto', $now);
        self::assertSame(3600, $second->getTimestamp() - $first->getTimestamp());
        self::assertSame('UTC', $first->getTimezone()->getName());
    }

    /** @dataProvider invalidTimes */
    public function testInvalidTimesAreRejected(string $value, string $timezone): void
    {
        $this->expectException(ScheduleException::class);
        ExecutionTime::parse($value, $timezone, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
    }

    public static function invalidTimes(): array
    {
        return [
            ['2026-03-08T02:30-05:00', 'America/Toronto'],
            ['2026-11-01T01:30-06:00', 'America/Toronto'],
            ['2026-02-30T10:00-05:00', 'America/Toronto'],
            ['2026-11-01T01:30', 'America/Toronto'],
            ['2026-11-01T01:30-04:00', 'Missing/Timezone'],
            ['2025-01-01T00:00+00:00', 'UTC'],
        ];
    }
}
