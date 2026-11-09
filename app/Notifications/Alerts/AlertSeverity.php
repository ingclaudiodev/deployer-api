<?php

namespace App\Notifications\Alerts;

enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /**
     * Slack's named attachment colours, so the bar beside the message tells the severity at a glance.
     */
    public function color(): string
    {
        return match ($this) {
            self::Info => 'good',
            self::Warning => 'warning',
            self::Critical => 'danger',
        };
    }

    public function emoji(): string
    {
        return match ($this) {
            self::Info => ':information_source:',
            self::Warning => ':warning:',
            self::Critical => ':rotating_light:',
        };
    }
}
