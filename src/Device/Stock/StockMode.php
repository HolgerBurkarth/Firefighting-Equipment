<?php

declare(strict_types=1);

namespace FirefightingEquipment\Device\Stock;

/**
 * Wie eine Buchung mit dem Sollbestand umgeht (Phase 2, P2.8, siehe StockGuard):
 * - Enforce: unterschreitet sie eine Regel, wird sie abgelehnt (409 rule_violation, details.stock);
 * - Ask: wie Enforce, aber die Rolle hat stock.override – die Antwort sagt es (details.stock.can_override), die App
 *   fragt und sendet confirmed_below_minimum;
 * - Override: gebucht, auch wenn sie eine Regel unterschreitet (Hinweis an die Admins, wenn die Buchung eine
 *   client_ref hat);
 * - Skip: keine Prüfung (der Tausch und die Abläufe, die ihre eigene Prüfung machen, sowie Offline-Buchungen).
 */
enum StockMode
{
    case Enforce;
    case Ask;
    case Override;
    case Skip;
}
