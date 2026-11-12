<?php

namespace App\Enums;

/**
 * How a service is charged. Jobs, worker pools and instances have no choice:
 * they are charged for the time they run.
 */
enum ProjectBilling: string
{
    /** CPU and memory only while a request is being handled, plus each request. */
    case Request = 'solicitudes';

    /** The whole time each instance is up; requests are not charged. */
    case Instance = 'instancias';
}
