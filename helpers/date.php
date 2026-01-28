<?php

function formatDateHuman(string $datetime, string $userTz = 'America/Asuncion'): string
{
    if (empty($datetime)) {
        return '';
    }

    try {
        // Fecha viene SIEMPRE en UTC desde la DB
        $dt = new DateTime($datetime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone($userTz));
    } catch (Exception $e) {
        return $datetime;
    }

    // Locale español
    setlocale(LC_TIME, 'es_ES.UTF-8', 'es_ES', 'spanish');

    $ts = $dt->getTimestamp();

    $today     = (new DateTime('today', new DateTimeZone($userTz)))->getTimestamp();
    $yesterday = (new DateTime('yesterday', new DateTimeZone($userTz)))->getTimestamp();
    
    // Hoy → HH:MM
    if ($ts >= $today) {
        return $dt -> format('H:i');
    }

    // Ayer
    if ($ts >= $yesterday) {
        return 'Ayer.' . $dt -> format('H:i');
    }

    // Este año → 14 ene
    if ($dt->format('Y') === (new DateTime('now', new DateTimeZone($userTz)))->format('Y')) {
        return strftime('%d %b', $ts). '. '. $dt -> format('H:i');
    }

    // Otros años → 14 ene 2024
    return strftime('%d %b %Y', $ts). '. '. $dt -> format('H:i');
}